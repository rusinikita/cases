package rewards

import (
	"context"
	"fmt"
	"log/slog"
	"time"

	"github.com/jackc/pgx/v5/pgxpool"

	"korzina.tech/platform/metrics"
)

const (
	// How long we wait for an antifraud verdict before paying the reward
	// anyway. Fail open: an antifraud outage must not block honest users.
	antifraudVerdictTimeout = 24 * time.Hour

	timeoutJobInterval  = 5 * time.Minute
	timeoutJobBatchSize = 500
)

type Reward struct {
	ID              int64
	UserID          int64
	Amount          int // RUB
	Status          RewardStatus
	StatusChangedAt time.Time
}

type PayoutRequest struct {
	IdempotencyKey string
	UserID         int64
	Amount         int
}

// PayoutClient transfers money to the user's linked card.
type PayoutClient interface {
	Pay(ctx context.Context, req PayoutRequest) (payoutID string, err error)
}

// AntifraudTimeoutJob pays out rewards whose antifraud check did not finish
// in time.
type AntifraudTimeoutJob struct {
	db      *pgxpool.Pool
	payouts PayoutClient
	log     *slog.Logger
}

func NewAntifraudTimeoutJob(db *pgxpool.Pool, payouts PayoutClient, log *slog.Logger) *AntifraudTimeoutJob {
	return &AntifraudTimeoutJob{db: db, payouts: payouts, log: log}
}

// Run executes the job immediately and then every timeoutJobInterval until
// ctx is cancelled.
func (j *AntifraudTimeoutJob) Run(ctx context.Context) error {
	ticker := time.NewTicker(timeoutJobInterval)
	defer ticker.Stop()

	for {
		if err := j.tick(ctx); err != nil {
			j.log.ErrorContext(ctx, "antifraud timeout job failed", "err", err)
		}
		select {
		case <-ctx.Done():
			return ctx.Err()
		case <-ticker.C:
		}
	}
}

func (j *AntifraudTimeoutJob) tick(ctx context.Context) error {
	var (
		lastID int64
		paid   int
	)
	for {
		batch, err := j.overdue(ctx, lastID)
		if err != nil {
			return fmt.Errorf("select overdue rewards: %w", err)
		}
		if len(batch) == 0 {
			break
		}
		for _, r := range batch {
			lastID = r.ID
			if err := j.payOut(ctx, r); err != nil {
				// Left in place, the next tick picks it up again.
				j.log.WarnContext(ctx, "fail-open payout failed", "reward_id", r.ID, "err", err)
				continue
			}
			paid++
		}
	}
	if paid > 0 {
		j.log.InfoContext(ctx, "antifraud verdict timed out, rewards paid", "count", paid)
	}
	return nil
}

func (j *AntifraudTimeoutJob) overdue(ctx context.Context, afterID int64) ([]Reward, error) {
	rows, err := j.db.Query(ctx, `
		SELECT id, user_id, amount, status, status_changed_at
		FROM rewards
		WHERE status = $1
		  AND status_changed_at < now() - $2::interval
		  AND id > $3
		ORDER BY id
		LIMIT $4`,
		RewardWaitingAntifraudResult, antifraudVerdictTimeout, afterID, timeoutJobBatchSize)
	if err != nil {
		return nil, err
	}
	defer rows.Close()

	var batch []Reward
	for rows.Next() {
		var r Reward
		if err := rows.Scan(&r.ID, &r.UserID, &r.Amount, &r.Status, &r.StatusChangedAt); err != nil {
			return nil, err
		}
		batch = append(batch, r)
	}
	return batch, rows.Err()
}

func (j *AntifraudTimeoutJob) payOut(ctx context.Context, r Reward) error {
	payoutID, err := j.payouts.Pay(ctx, PayoutRequest{
		IdempotencyKey: fmt.Sprintf("reward-%d", r.ID),
		UserID:         r.UserID,
		Amount:         r.Amount,
	})
	if err != nil {
		return fmt.Errorf("payout: %w", err)
	}

	_, err = j.db.Exec(ctx, `
		UPDATE rewards
		SET status = $1, status_changed_at = now(), payout_id = $2
		WHERE id = $3 AND status = $4`,
		RewardPaid, payoutID, r.ID, RewardWaitingAntifraudResult)
	if err != nil {
		return fmt.Errorf("mark reward paid: %w", err)
	}
	metrics.Inc("rewards_paid_total", "reason", "antifraud_timeout")
	return nil
}
