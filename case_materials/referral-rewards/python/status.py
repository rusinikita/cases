from __future__ import annotations

import enum


class RewardStatus(enum.IntEnum):
    """Lifecycle state of a referral reward. Persisted in rewards.status."""

    PENDING = enum.auto()
    PAID = enum.auto()
    WAITING_ANTIFRAUD_RESULT = enum.auto()
    REJECTED = enum.auto()

    @property
    def is_final(self) -> bool:
        """Whether the reward will not change its status anymore."""
        return self in (RewardStatus.PAID, RewardStatus.REJECTED)

    def can_transition_to(self, nxt: RewardStatus) -> bool:
        return nxt in _TRANSITIONS.get(self, ())


_TRANSITIONS: dict[RewardStatus, tuple[RewardStatus, ...]] = {
    RewardStatus.PENDING: (RewardStatus.WAITING_ANTIFRAUD_RESULT,),
    RewardStatus.WAITING_ANTIFRAUD_RESULT: (RewardStatus.PAID, RewardStatus.REJECTED),
}
