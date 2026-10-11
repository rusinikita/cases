package tech.korzina.referral.rewards;

import com.fasterxml.jackson.annotation.JsonIgnoreProperties;
import com.fasterxml.jackson.core.JsonProcessingException;
import com.fasterxml.jackson.databind.ObjectMapper;
import com.fasterxml.jackson.databind.PropertyNamingStrategies;
import com.fasterxml.jackson.databind.annotation.JsonNaming;
import io.micrometer.core.instrument.MeterRegistry;
import java.time.Instant;
import java.util.List;
import java.util.UUID;
import org.slf4j.Logger;
import org.slf4j.LoggerFactory;
import org.springframework.jdbc.core.JdbcTemplate;
import org.springframework.stereotype.Component;
import tech.korzina.platform.kafka.ConsumerHandler;
import tech.korzina.platform.kafka.Message;
import tech.korzina.platform.kafka.MessageHandler;
import tech.korzina.referral.rewards.AntifraudTimeoutJob.PayoutClient;
import tech.korzina.referral.rewards.AntifraudTimeoutJob.PayoutRequest;
import tech.korzina.referral.rewards.AntifraudTimeoutJob.Reward;

/**
 * Applies antifraud verdicts to rewards that wait for them.
 */
@Component
@ConsumerHandler("antifraud_reward_verdicts")
public class AntifraudVerdictConsumer implements MessageHandler {

    private static final Logger log = LoggerFactory.getLogger(AntifraudVerdictConsumer.class);

    private static final String DECISION_OK = "ok";
    private static final String DECISION_FRAUD = "fraud";

    private final JdbcTemplate jdbc;
    private final PayoutClient payouts;
    private final ObjectMapper mapper;
    private final MeterRegistry metrics;

    public AntifraudVerdictConsumer(
            JdbcTemplate jdbc, PayoutClient payouts, ObjectMapper mapper, MeterRegistry metrics) {
        this.jdbc = jdbc;
        this.payouts = payouts;
        this.mapper = mapper;
        this.metrics = metrics;
    }

    /**
     * Processes one verdict. Any exception thrown from here makes the consumer
     * redeliver the message.
     */
    @Override
    public void handle(Message message) {
        AntifraudVerdict verdict;
        try {
            verdict = mapper.readValue(message.value(), AntifraudVerdict.class);
        } catch (JsonProcessingException e) {
            log.error("malformed antifraud verdict, partition={} offset={}",
                    message.partition(), message.offset(), e);
            skipped("malformed");
            return;
        }

        List<Reward> found = jdbc.query("""
                SELECT id, user_id, amount, status, status_changed_at
                FROM rewards
                WHERE id = ?
                """,
                AntifraudTimeoutJob.REWARD_MAPPER,
                verdict.rewardId());
        if (found.isEmpty()) {
            log.warn("verdict for unknown reward, reward_id={} check_id={}",
                    verdict.rewardId(), verdict.checkId());
            skipped("unknown_reward");
            return;
        }
        Reward reward = found.get(0);

        if (reward.status() != RewardStatus.WAITING_ANTIFRAUD_RESULT) {
            // Late or duplicate verdict: the reward has already been settled,
            // e.g. by the timeout job.
            log.info("verdict ignored, reward is not waiting, reward_id={} status={} check_id={}",
                    reward.id(), reward.status(), verdict.checkId());
            skipped("not_waiting");
            return;
        }

        switch (verdict.decision()) {
            case DECISION_OK -> approve(reward);
            case DECISION_FRAUD -> reject(reward, verdict);
            default -> {
                log.error("unknown antifraud decision, reward_id={} decision={} check_id={}",
                        reward.id(), verdict.decision(), verdict.checkId());
                skipped("unknown_decision");
            }
        }
    }

    private void approve(Reward reward) {
        UUID payoutId = payouts.pay(new PayoutRequest("reward-" + reward.id(), reward.userId(), reward.amount()));

        jdbc.update("""
                UPDATE rewards
                SET status = ?, status_changed_at = now(), payout_id = ?
                WHERE id = ? AND status = ?
                """,
                RewardStatus.PAID.dbValue(),
                payoutId,
                reward.id(),
                RewardStatus.WAITING_ANTIFRAUD_RESULT.dbValue());
        metrics.counter("rewards_paid_total", "reason", "antifraud_ok").increment();
    }

    private void reject(Reward reward, AntifraudVerdict verdict) {
        jdbc.update("""
                UPDATE rewards
                SET status = ?, status_changed_at = now()
                WHERE id = ? AND status = ?
                """,
                RewardStatus.REJECTED.dbValue(),
                reward.id(),
                RewardStatus.WAITING_ANTIFRAUD_RESULT.dbValue());
        log.info("reward rejected by antifraud, reward_id={} user_id={} check_id={} reason={}",
                reward.id(), reward.userId(), verdict.checkId(), verdict.reason());
        metrics.counter("rewards_rejected_total", "reason", "antifraud_fraud").increment();
    }

    private void skipped(String reason) {
        metrics.counter("antifraud_verdicts_skipped_total", "reason", reason).increment();
    }

    /** Payload of the antifraud.reward-verdicts topic. */
    @JsonIgnoreProperties(ignoreUnknown = true)
    @JsonNaming(PropertyNamingStrategies.SnakeCaseStrategy.class)
    public record AntifraudVerdict(
            String checkId, long rewardId, String decision, String reason, Instant decidedAt) {
    }
}
