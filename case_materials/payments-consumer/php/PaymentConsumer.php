<?php

declare(strict_types=1);

namespace Korzina\Orders\Payments;

use DateInterval;
use JsonException;
use Korzina\Orders\Payments\Exception\OrderAlreadyPaidException;
use Korzina\Orders\Payments\Exception\OrderNotFoundException;
use Korzina\Platform\Kafka\Context;
use Korzina\Platform\Kafka\Message;
use Korzina\Platform\Kafka\MessageHandlerInterface;
use Korzina\Platform\Metrics\MetricsInterface;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;

final class PaymentConsumer implements MessageHandlerInterface
{
    private const NEXT_ORDER_CAMPAIGN = 'next_order_speclist';
    private const NEXT_ORDER_DISCOUNT_PERCENT = 20;
    private const NEXT_ORDER_DISCOUNT_TTL = 'P14D';

    public function __construct(
        private readonly OrderStoreInterface $orders,
        private readonly ReceiptSenderInterface $receipts,
        private readonly PromoClientInterface $promo,
        private readonly MetricsInterface $metrics,
        private readonly LoggerInterface $logger,
        private readonly ClockInterface $clock,
    ) {
    }

    /**
     * Processes one payments.succeeded message. Any exception thrown from here
     * makes the consumer redeliver the message according to its retry policy.
     */
    public function handle(Context $ctx, Message $message): void
    {
        try {
            $evt = PaymentSucceeded::fromArray(
                json_decode($message->value, true, 16, JSON_THROW_ON_ERROR)
            );
        } catch (JsonException $e) {
            // Redelivery will not fix a broken payload.
            $this->logger->error('malformed payment event', [
                'partition' => $message->partition,
                'offset' => $message->offset,
                'exception' => $e,
            ]);
            $this->metrics->increment('payment_consumer_skipped_total', ['reason' => 'malformed']);

            return;
        }

        if ($evt->orderId === 0 || $evt->userId === 0 || $evt->paymentId === '') {
            $this->logger->error('incomplete payment event', [
                'event_id' => $evt->eventId,
                'partition' => $message->partition,
                'offset' => $message->offset,
            ]);
            $this->metrics->increment('payment_consumer_skipped_total', ['reason' => 'incomplete']);

            return;
        }

        try {
            $this->orders->markPaid($ctx, $evt->orderId, $evt->paymentId, $evt->paidAt);
        } catch (OrderAlreadyPaidException) {
            $this->logger->info('order already paid', [
                'order_id' => $evt->orderId,
                'event_id' => $evt->eventId,
            ]);
        } catch (OrderNotFoundException) {
            $this->logger->warning('payment for unknown order', [
                'order_id' => $evt->orderId,
                'event_id' => $evt->eventId,
            ]);
            $this->metrics->increment('payment_consumer_skipped_total', ['reason' => 'unknown_order']);

            return;
        }

        $this->receipts->send($ctx, new Receipt(
            idempotencyKey: $evt->paymentId,
            orderId: $evt->orderId,
            userId: $evt->userId,
            amount: $evt->amount,
            currency: $evt->currency,
            items: $evt->items,
            paidAt: $evt->paidAt,
        ));

        if (PromoSkus::containsAny($evt->items)) {
            $this->promo->grantDiscount($ctx, new DiscountGrant(
                userId: $evt->userId,
                campaign: self::NEXT_ORDER_CAMPAIGN,
                percent: self::NEXT_ORDER_DISCOUNT_PERCENT,
                expiresAt: $this->clock->now()->add(new DateInterval(self::NEXT_ORDER_DISCOUNT_TTL)),
                sourceOrderId: $evt->orderId,
            ));
            $this->metrics->increment(
                'payment_consumer_discounts_granted_total',
                ['campaign' => self::NEXT_ORDER_CAMPAIGN],
            );
        }
    }
}

/**
 * Payload of the payments.succeeded topic.
 */
final class PaymentSucceeded
{
    /**
     * @param list<Item> $items
     */
    public function __construct(
        public readonly string $eventId,
        public readonly string $paymentId,
        public readonly int $orderId,
        public readonly int $userId,
        public readonly int $amount, // minor units
        public readonly string $currency,
        public readonly string $country,
        public readonly string $method,
        public readonly \DateTimeImmutable $paidAt,
        public readonly array $items,
    ) {
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            eventId: (string) ($data['event_id'] ?? ''),
            paymentId: (string) ($data['payment_id'] ?? ''),
            orderId: (int) ($data['order_id'] ?? 0),
            userId: (int) ($data['user_id'] ?? 0),
            amount: (int) ($data['amount'] ?? 0),
            currency: (string) ($data['currency'] ?? ''),
            country: (string) ($data['country'] ?? ''),
            method: (string) ($data['method'] ?? ''),
            paidAt: new \DateTimeImmutable((string) ($data['paid_at'] ?? 'now')),
            items: array_map(
                static fn (array $item): Item => new Item(
                    sku: (string) $item['sku'],
                    qty: (int) $item['qty'],
                    price: (int) $item['price'],
                ),
                $data['items'] ?? [],
            ),
        );
    }
}

final class Item
{
    public function __construct(
        public readonly string $sku,
        public readonly int $qty,
        public readonly int $price,
    ) {
    }
}

final class Receipt
{
    /**
     * @param list<Item> $items
     */
    public function __construct(
        public readonly string $idempotencyKey,
        public readonly int $orderId,
        public readonly int $userId,
        public readonly int $amount,
        public readonly string $currency,
        public readonly array $items,
        public readonly \DateTimeImmutable $paidAt,
    ) {
    }
}

final class DiscountGrant
{
    public function __construct(
        public readonly int $userId,
        public readonly string $campaign,
        public readonly int $percent,
        public readonly \DateTimeImmutable $expiresAt,
        public readonly int $sourceOrderId,
    ) {
    }
}

interface OrderStoreInterface
{
    /**
     * @throws OrderNotFoundException
     * @throws OrderAlreadyPaidException
     */
    public function markPaid(Context $ctx, int $orderId, string $paymentId, \DateTimeImmutable $paidAt): void;
}

interface ReceiptSenderInterface
{
    public function send(Context $ctx, Receipt $receipt): void;
}

interface PromoClientInterface
{
    public function grantDiscount(Context $ctx, DiscountGrant $grant): void;
}
