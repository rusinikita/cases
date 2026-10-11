package tech.korzina.referral.rewards;

import java.util.EnumSet;
import java.util.Set;

/**
 * Lifecycle state of a referral reward. Persisted in rewards.status
 * by ordinal, see {@link #dbValue()}.
 */
public enum RewardStatus {
    UNSPECIFIED,
    PENDING,
    PAID,
    WAITING_ANTIFRAUD_RESULT,
    REJECTED;

    private static final RewardStatus[] VALUES = values();

    public short dbValue() {
        return (short) ordinal();
    }

    public static RewardStatus fromDb(int value) {
        if (value <= 0 || value >= VALUES.length) {
            throw new IllegalArgumentException("Unknown reward status: " + value);
        }
        return VALUES[value];
    }

    /** Whether the reward will not change its status anymore. */
    public boolean isFinal() {
        return this == PAID || this == REJECTED;
    }

    public boolean canTransitionTo(RewardStatus next) {
        return allowedNext().contains(next);
    }

    private Set<RewardStatus> allowedNext() {
        return switch (this) {
            case PENDING -> EnumSet.of(WAITING_ANTIFRAUD_RESULT);
            case WAITING_ANTIFRAUD_RESULT -> EnumSet.of(PAID, REJECTED);
            default -> EnumSet.noneOf(RewardStatus.class);
        };
    }
}
