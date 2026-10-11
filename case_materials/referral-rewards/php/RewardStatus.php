<?php

declare(strict_types=1);

namespace Korzina\Referral\Rewards;

/**
 * Lifecycle state of a referral reward. Persisted in rewards.status.
 */
enum RewardStatus: int
{
    case Pending = 1;
    case Paid = 2;
    case WaitingAntifraudResult = 3;
    case Rejected = 4;

    /**
     * Whether the reward will not change its status anymore.
     */
    public function isFinal(): bool
    {
        return $this === self::Paid || $this === self::Rejected;
    }

    public function canTransitionTo(self $next): bool
    {
        return in_array($next, $this->allowedNext(), true);
    }

    /**
     * @return list<self>
     */
    private function allowedNext(): array
    {
        return match ($this) {
            self::Pending => [self::WaitingAntifraudResult],
            self::WaitingAntifraudResult => [self::Paid, self::Rejected],
            default => [],
        };
    }
}
