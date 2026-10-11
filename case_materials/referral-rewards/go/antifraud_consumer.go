package rewards

import (
	"context"
	"encoding/json"
	"errors"
	"fmt"
	"log/slog"
	"time"

	"github.com/jackc/pgx/v5"
	"github.com/jackc/pgx/v5/pgxpool"

	"korzina.tech/platform/kafkaconsumer"
	"korzina.tech/platform/metrics"
)

const (
	decisionOK    = "ok"
	decisionFraud = "fraud"
)

// AntifraudVerdict is the payload of the antifraud.reward-verdicts topic.
type AntifraudVerdict struct {
	CheckID   string    `json:"check_id"`
	RewardID  int64     `json:"reward_id"`
	Decision  string    `json:"decision"`
	Reason    string    `json:"reason,omitempty"`
	DecidedAt time.Time `json:"decided_at"`
}

// AntifraudConsumer applies antifraud verdicts to rewards that wait for them.
type AntifraudConsumer struct {
	db      *pgxpool.Pool
	payouts PayoutClient
	log     *slog.Logger
}

func NewAntifraudConsumer(db *pgxpool.Pool, payouts PayoutClient, log *slog.Logger) *AntifraudConsumer {
	return &AntifraudConsumer{db: db, payouts: payouts, log: log}
}

// Handle processes one verdict. A non-nil error makes the consumer redeliver
// the message.
func (c *AntifraudConsumer) Handle(ctx context.Context, msg kafkaconsumer.Message) error {
	var v AntifraudVerdict
	if err := json.Unmarshal(msg.Value, &v); err != nil {
		c.log.ErrorContext(ctx, "malformed antifraud verdict",
			"partition", msg.Partition, "offset", msg.Offset, "err", err)
		metrics.Inc("antifraud_verdicts_skipped_total", "reason", "malformed")
		return nil
	}

	r, err := c.load(ctx, v.RewardID)
	if errors.Is(err, pgx.ErrNoRows) {
		c.log.WarnContext(ctx, "verdict for unknown reward", "reward_id", v.RewardID, "check_id", v.CheckID)
		metrics.Inc("antifraud_verdicts_skipped_total", "reason", "unknown_reward")
		return nil
	}
	if err != nil {
		return fmt.Errorf("load reward %d: %w", v.RewardID, err)
	}

	if r.Status != RewardWaitingAntifraudResult {
		// Late or duplicate verdict: the reward has already been settled,
		// e.g. by the timeout job.
		c.log.InfoContext(ctx, "verdict ignored, reward is not waiting",
			"reward_id", r.ID, "status", r.Status.String(), "check_id", v.CheckID)
		metrics.Inc("antifraud_verdicts_skipped_total", "reason", "not_waiting")
		return nil
	}

	switch v.Decision {
	case decisionOK:
		return c.approve(ctx, r)
	case decisionFraud:
		return c.reject(ctx, r, v)
	default:
		c.log.ErrorContext(ctx, "unknown antifraud decision",
			"reward_id", r.ID, "decision", v.Decision, "check_id", v.CheckID)
		metrics.Inc("antifraud_verdicts_skipped_total", "reason", "unknown_decision")
		return nil
	}
}

func (c *AntifraudConsumer) load(ctx context.Context, id int64) (Reward, error) {
	var r Reward
	err := c.db.QueryRow(ctx, `
		SELECT id, user_id, amount, status, status_changed_at
		FROM rewards
		WHERE id = $1`, id).
		Scan(&r.ID, &r.UserID, &r.Amount, &r.Status, &r.StatusChangedAt)
	return r, err
}

func (c *AntifraudConsumer) approve(ctx context.Context, r Reward) error {
	payoutID, err := c.payouts.Pay(ctx, PayoutRequest{
		IdempotencyKey: fmt.Sprintf("reward-%d", r.ID),
		UserID:         r.UserID,
		Amount:         r.Amount,
	})
	if err != nil {
		return fmt.Errorf("payout for reward %d: %w", r.ID, err)
	}

	_, err = c.db.Exec(ctx, `
		UPDATE rewards
		SET status = $1, status_changed_at = now(), payout_id = $2
		WHERE id = $3 AND status = $4`,
		RewardPaid, payoutID, r.ID, RewardWaitingAntifraudResult)
	if err != nil {
		return fmt.Errorf("mark reward %d paid: %w", r.ID, err)
	}
	metrics.Inc("rewards_paid_total", "reason", "antifraud_ok")
	return nil
}

func (c *AntifraudConsumer) reject(ctx context.Context, r Reward, v AntifraudVerdict) error {
	_, err := c.db.Exec(ctx, `
		UPDATE rewards
		SET status = $1, status_changed_at = now()
		WHERE id = $2 AND status = $3`,
		RewardRejected, r.ID, RewardWaitingAntifraudResult)
	if err != nil {
		return fmt.Errorf("reject reward %d: %w", r.ID, err)
	}
	c.log.InfoContext(ctx, "reward rejected by antifraud",
		"reward_id", r.ID, "user_id", r.UserID, "check_id", v.CheckID, "reason", v.Reason)
	metrics.Inc("rewards_rejected_total", "reason", "antifraud_fraud")
	return nil
}
