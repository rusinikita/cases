package tech.korzina.orders.payments;

import com.fasterxml.jackson.annotation.JsonIgnoreProperties;
import com.fasterxml.jackson.core.JsonProcessingException;
import com.fasterxml.jackson.databind.ObjectMapper;
import com.fasterxml.jackson.databind.PropertyNamingStrategies;
import com.fasterxml.jackson.databind.annotation.JsonNaming;
import io.micrometer.core.instrument.MeterRegistry;
import java.time.Clock;
import java.time.Duration;
import java.time.Instant;
import java.util.List;
import org.slf4j.Logger;
import org.slf4j.LoggerFactory;
import org.springframework.stereotype.Component;
import tech.korzina.platform.kafka.ConsumerHandler;
import tech.korzina.platform.kafka.Message;
import tech.korzina.platform.kafka.MessageHandler;
import tech.korzina.platform.kafka.ProcessingContext;

@Component
@ConsumerHandler("payment_succeeded")
public class PaymentConsumer implements MessageHandler {

    private static final Logger log = LoggerFactory.getLogger(PaymentConsumer.class);

    private static final String NEXT_ORDER_CAMPAIGN = "next_order_speclist";
    private static final int NEXT_ORDER_DISCOUNT_PERCENT = 20;
    private static final Duration NEXT_ORDER_DISCOUNT_TTL = Duration.ofDays(14);

    private final OrderStore orders;
    private final ReceiptSender receipts;
    private final PromoClient promo;
    private final ObjectMapper mapper;
    private final MeterRegistry metrics;
    private final Clock clock;

    public PaymentConsumer(
            OrderStore orders,
            ReceiptSender receipts,
            PromoClient promo,
            ObjectMapper mapper,
            MeterRegistry metrics,
            Clock clock) {
        this.orders = orders;
        this.receipts = receipts;
        this.promo = promo;
        this.mapper = mapper;
        this.metrics = metrics;
        this.clock = clock;
    }

    /**
     * Processes one payments.succeeded message. Any exception thrown from here
     * makes the consumer redeliver the message according to its retry policy.
     */
    @Override
    public void handle(ProcessingContext ctx, Message msg) {
        PaymentSucceeded evt;
        try {
            evt = mapper.readValue(msg.value(), PaymentSucceeded.class);
        } catch (JsonProcessingException e) {
            // Redelivery will not fix a broken payload.
            log.error("malformed payment event partition={} offset={}", msg.partition(), msg.offset(), e);
            skipped("malformed");
            return;
        }
        if (evt.orderId() == 0 || evt.userId() == 0 || evt.paymentId() == null || evt.paymentId().isEmpty()) {
            log.error("incomplete payment event event_id={} partition={} offset={}",
                    evt.eventId(), msg.partition(), msg.offset());
            skipped("incomplete");
            return;
        }

        try {
            orders.markPaid(ctx, evt.orderId(), evt.paymentId(), evt.paidAt());
        } catch (OrderAlreadyPaidException e) {
            log.info("order already paid order_id={} event_id={}", evt.orderId(), evt.eventId());
        } catch (OrderNotFoundException e) {
            log.warn("payment for unknown order order_id={} event_id={}", evt.orderId(), evt.eventId());
            skipped("unknown_order");
            return;
        }

        receipts.send(ctx, new Receipt(
                evt.paymentId(),
                evt.orderId(),
                evt.userId(),
                evt.amount(),
                evt.currency(),
                evt.items(),
                evt.paidAt()));

        if (PromoSkus.containsAny(evt.items())) {
            promo.grantDiscount(ctx, new DiscountGrant(
                    evt.userId(),
                    NEXT_ORDER_CAMPAIGN,
                    NEXT_ORDER_DISCOUNT_PERCENT,
                    clock.instant().plus(NEXT_ORDER_DISCOUNT_TTL),
                    evt.orderId()));
            metrics.counter("payment_consumer_discounts_granted_total", "campaign", NEXT_ORDER_CAMPAIGN)
                    .increment();
        }
    }

    private void skipped(String reason) {
        metrics.counter("payment_consumer_skipped_total", "reason", reason).increment();
    }

    /** Payload of the payments.succeeded topic. */
    @JsonNaming(PropertyNamingStrategies.SnakeCaseStrategy.class)
    @JsonIgnoreProperties(ignoreUnknown = true)
    public record PaymentSucceeded(
            String eventId,
            String paymentId,
            long orderId,
            long userId,
            long amount, // minor units
            String currency,
            String country,
            String method,
            Instant paidAt,
            List<Item> items) {}

    @JsonIgnoreProperties(ignoreUnknown = true)
    public record Item(String sku, int qty, long price) {}

    public record Receipt(
            String idempotencyKey,
            long orderId,
            long userId,
            long amount,
            String currency,
            List<Item> items,
            Instant paidAt) {}

    public record DiscountGrant(
            long userId,
            String campaign,
            int percent,
            Instant expiresAt,
            long sourceOrderId) {}

    public interface OrderStore {
        void markPaid(ProcessingContext ctx, long orderId, String paymentId, Instant paidAt)
                throws OrderNotFoundException, OrderAlreadyPaidException;
    }

    public interface ReceiptSender {
        void send(ProcessingContext ctx, Receipt receipt);
    }

    public interface PromoClient {
        void grantDiscount(ProcessingContext ctx, DiscountGrant grant);
    }

    public static class OrderNotFoundException extends Exception {}

    public static class OrderAlreadyPaidException extends Exception {}
}
