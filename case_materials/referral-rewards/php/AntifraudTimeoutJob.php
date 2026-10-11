<?php

declare(strict_types=1);

namespace Korzina\Referral\Rewards;

use Doctrine\DBAL\Connection;
use Korzina\Platform\Metrics\MetricsInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Scheduler\Attribute\AsPeriodicTask;

/**
 * Pays out rewards whose antifraud check did not finish in time.
 * Runs on worker start and then every five minutes.
 */
#[AsPeriodicTask(frequency: '5 minutes', from: 'now')]
final class AntifraudTimeoutJob
{
    /**
     * How long we wait for an antifraud verdict before paying the reward
     * anyway. Fail open: an antifraud outage must not block honest users.
     */
    private const ANTIFRAUD_VERDICT_TIMEOUT = '24 hours';

    private const BATCH_SIZE = 500;

    public function __construct(
        private readonly Connection $db,
        private readonly PayoutClientInterface $payouts,
        private readonly MetricsInterface $metrics,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function __invoke(): void
    {
        $lastId = 0;
        $paid = 0;

        while (true) {
            $batch = $this->overdue($lastId);
            if ($batch === []) {
                break;
            }

            foreach ($batch as $reward) {
                $lastId = $reward->id;
                try {
                    $this->payOut($reward);
                    ++$paid;
                } catch (\Throwable $e) {
                    // Left in place, the next run picks it up again.
                    $this->logger->warning('fail-open payout failed', [
                        'reward_id' => $reward->id,
                        'exception' => $e,
                    ]);
                }
            }
        }

        if ($paid > 0) {
            $this->logger->info('antifraud verdict timed out, rewards paid', ['count' => $paid]);
        }
    }

    /**
     * @return list<Reward>
     */
    private function overdue(int $afterId): array
    {
        $rows = $this->db->fetchAllAssociative(
            <<<'SQL'
                SELECT id, user_id, amount, status, status_changed_at
                FROM rewards
                WHERE status = :status
                  AND status_changed_at < now() - CAST(:timeout AS interval)
                  AND id > :after_id
                ORDER BY id
                LIMIT :limit
                SQL,
            [
                'status' => RewardStatus::WaitingAntifraudResult->value,
                'timeout' => self::ANTIFRAUD_VERDICT_TIMEOUT,
                'after_id' => $afterId,
                'limit' => self::BATCH_SIZE,
            ],
        );

        return array_map(Reward::fromRow(...), $rows);
    }

    private function payOut(Reward $reward): void
    {
        $payoutId = $this->payouts->pay(new PayoutRequest(
            idempotencyKey: sprintf('reward-%d', $reward->id),
            userId: $reward->userId,
            amount: $reward->amount,
        ));

        $this->db->executeStatement(
            <<<'SQL'
                UPDATE rewards
                SET status = :paid, status_changed_at = now(), payout_id = :payout_id
                WHERE id = :id AND status = :waiting
                SQL,
            [
                'paid' => RewardStatus::Paid->value,
                'payout_id' => $payoutId,
                'id' => $reward->id,
                'waiting' => RewardStatus::WaitingAntifraudResult->value,
            ],
        );
        $this->metrics->increment('rewards_paid_total', ['reason' => 'antifraud_timeout']);
    }
}

final readonly class Reward
{
    public function __construct(
        public int $id,
        public int $userId,
        public int $amount, // RUB
        public RewardStatus $status,
        public \DateTimeImmutable $statusChangedAt,
    ) {
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function fromRow(array $row): self
    {
        return new self(
            (int) $row['id'],
            (int) $row['user_id'],
            (int) $row['amount'],
            RewardStatus::from((int) $row['status']),
            new \DateTimeImmutable($row['status_changed_at']),
        );
    }
}

final readonly class PayoutRequest
{
    public function __construct(
        public string $idempotencyKey,
        public int $userId,
        public int $amount,
    ) {
    }
}

/**
 * Transfers money to the user's linked card.
 */
interface PayoutClientInterface
{
    /**
     * @return string payout id (UUID)
     */
    public function pay(PayoutRequest $request): string;
}
