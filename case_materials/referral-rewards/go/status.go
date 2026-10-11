package rewards

import "fmt"

// RewardStatus is the lifecycle state of a referral reward.
// Persisted in rewards.status.
type RewardStatus int16

const (
	RewardPending RewardStatus = iota + 1
	RewardPaid
	RewardWaitingAntifraudResult
	RewardRejected
)

func (s RewardStatus) String() string {
	switch s {
	case RewardPending:
		return "pending"
	case RewardPaid:
		return "paid"
	case RewardWaitingAntifraudResult:
		return "waiting_antifraud_result"
	case RewardRejected:
		return "rejected"
	default:
		return fmt.Sprintf("RewardStatus(%d)", int16(s))
	}
}

// Final reports whether the reward will not change its status anymore.
func (s RewardStatus) Final() bool {
	return s == RewardPaid || s == RewardRejected
}

var transitions = map[RewardStatus][]RewardStatus{
	RewardPending:                {RewardWaitingAntifraudResult},
	RewardWaitingAntifraudResult: {RewardPaid, RewardRejected},
}

// CanTransitionTo reports whether a reward may move from s to next.
func (s RewardStatus) CanTransitionTo(next RewardStatus) bool {
	for _, allowed := range transitions[s] {
		if allowed == next {
			return true
		}
	}
	return false
}
