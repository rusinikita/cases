package tech.korzina.referral.rewards;

import io.micrometer.core.instrument.MeterRegistry;
import java.time.Duration;
import java.time.Instant;
import java.util.List;
import java.util.UUID;
import org.slf4j.Logger;
import org.slf4j.LoggerFactory;
import org.springframework.jdbc.core.JdbcTemplate;
import org.springframework.jdbc.core.RowMapper;
import org.springframework.scheduling.annotation.Scheduled;
import org.springframework.stereotype.Component;

/**
 * Pays out rewards whose antifraud check did not finish in time.
 */
@Component
public class AntifraudTimeoutJob {

    private static final Logger log = LoggerFactory.getLogger(AntifraudTimeoutJob.class);

    /**
     * How long we wait for an antifraud verdict before paying the reward
     * anyway. Fail open: an antifraud outage must not block honest users.
     */
    private static final Duration ANTIFRAUD_VERDICT_TIMEOUT = Duration.ofHours(24);

    private static final int BATCH_SIZE = 500;

    static final RowMapper<Reward> REWARD_MAPPER = (rs, rowNum) -> new Reward(
            rs.getLong("id"),
            rs.getLong("user_id"),
            rs.getInt("amount"),
            RewardStatus.fromDb(rs.getShort("status")),
            rs.getTimestamp("status_changed_at").toInstant());

    private final JdbcTemplate jdbc;
    private final PayoutClient payouts;
    private final MeterRegistry metrics;

    public AntifraudTimeoutJob(JdbcTemplate jdbc, PayoutClient payouts, MeterRegistry metrics) {
        this.jdbc = jdbc;
        this.payouts = payouts;
        this.metrics = metrics;
    }

    /** Runs on startup and then every five minutes. */
    @Scheduled(fixedDelayString = "PT5M")
    public void run() {
        long lastId = 0;
        int paid = 0;
        while (true) {
            List<Reward> batch = overdue(lastId);
            if (batch.isEmpty()) {
                break;
            }
            for (Reward reward : batch) {
                lastId = reward.id();
                try {
                    payOut(reward);
                    paid++;
                } catch (RuntimeException e) {
                    // Left in place, the next run picks it up again.
                    log.warn("fail-open payout failed, reward_id={}", reward.id(), e);
                }
            }
        }
        if (paid > 0) {
            log.info("antifraud verdict timed out, rewards paid, count={}", paid);
        }
    }

    private List<Reward> overdue(long afterId) {
        return jdbc.query("""
                SELECT id, user_id, amount, status, status_changed_at
                FROM rewards
                WHERE status = ?
                  AND status_changed_at < now() - make_interval(secs => ?)
                  AND id > ?
                ORDER BY id
                LIMIT ?
                """,
                REWARD_MAPPER,
                RewardStatus.WAITING_ANTIFRAUD_RESULT.dbValue(),
                ANTIFRAUD_VERDICT_TIMEOUT.toSeconds(),
                afterId,
                BATCH_SIZE);
    }

    private void payOut(Reward reward) {
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
        metrics.counter("rewards_paid_total", "reason", "antifraud_timeout").increment();
    }

    /** @param amount RUB */
    public record Reward(long id, long userId, int amount, RewardStatus status, Instant statusChangedAt) {
    }

    public record PayoutRequest(String idempotencyKey, long userId, int amount) {
    }

    /** Transfers money to the user's linked card. */
    public interface PayoutClient {
        UUID pay(PayoutRequest request);
    }
}
