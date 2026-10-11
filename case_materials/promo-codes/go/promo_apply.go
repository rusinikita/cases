package promo

import (
	"context"
	"database/sql"
	"errors"
	"fmt"
	"log/slog"
	"slices"
	"strings"
	"time"

	"github.com/jackc/pgx/v5/pgconn"

	"veter.app/rides/internal/db"
)

type Source string

const (
	SourceDeeplink Source = "deeplink"
	SourceManual   Source = "manual"
)

const (
	SegmentAll       = "all"
	SegmentNew       = "new"
	SegmentReturning = "returning"
)

var (
	ErrPromoNotFound     = errors.New("promo not found")
	ErrPromoExpired      = errors.New("promo expired")
	ErrPromoAlreadyUsed  = errors.New("promo already used")
	ErrPromoLimitReached = errors.New("promo activation limit reached")
)

const pgUniqueViolation = "23505"

// Queries is the subset of generated queries used by the service
// (see queries.sql).
type Queries interface {
	GetPromoByCode(ctx context.Context, code string) (db.PromoCode, error)
	CountUserActivations(ctx context.Context, arg db.CountUserActivationsParams) (int64, error)
	CountActivations(ctx context.Context, promoID int64) (int64, error)
	CreateActivation(ctx context.Context, arg db.CreateActivationParams) error
}

type User struct {
	ID             int64
	CityID         int32
	CompletedRides int
}

type UserProvider interface {
	Get(ctx context.Context, userID int64) (User, error)
}

type Service struct {
	q     Queries
	users UserProvider
	log   *slog.Logger
	now   func() time.Time
}

func NewService(q Queries, users UserProvider, log *slog.Logger) *Service {
	return &Service{q: q, users: users, log: log, now: time.Now}
}

func normalizeCode(raw string) string {
	return strings.ToUpper(strings.TrimSpace(raw))
}

// Apply activates the promo code for the user. The discount itself is applied
// by pricing when the next ride is created.
func (s *Service) Apply(ctx context.Context, userID int64, raw string, src Source) error {
	promo, err := s.q.GetPromoByCode(ctx, normalizeCode(raw))
	if errors.Is(err, sql.ErrNoRows) {
		s.log.Info("promo not found", "input", raw, "user_id", userID, "source", src)
		return ErrPromoNotFound
	}
	if err != nil {
		return fmt.Errorf("get promo: %w", err)
	}

	now := s.now()
	if !promo.IsActive || now.Before(promo.StartsAt) {
		return s.hide(promo, userID, src, "inactive")
	}
	if !now.Before(promo.EndsAt) {
		return ErrPromoExpired
	}

	user, err := s.users.Get(ctx, userID)
	if err != nil {
		return fmt.Errorf("get user %d: %w", userID, err)
	}
	if !segmentMatches(promo.Segment, user) {
		return s.hide(promo, userID, src, "segment")
	}
	if len(promo.CityIDs) > 0 && !slices.Contains(promo.CityIDs, user.CityID) {
		return s.hide(promo, userID, src, "city")
	}

	used, err := s.q.CountUserActivations(ctx, db.CountUserActivationsParams{
		PromoID: promo.ID,
		UserID:  userID,
	})
	if err != nil {
		return fmt.Errorf("count user activations: %w", err)
	}
	if used >= int64(promo.PerUserLimit) {
		return ErrPromoAlreadyUsed
	}

	if promo.MaxActivations.Valid {
		total, err := s.q.CountActivations(ctx, promo.ID)
		if err != nil {
			return fmt.Errorf("count activations: %w", err)
		}
		if total >= int64(promo.MaxActivations.Int32) {
			return ErrPromoLimitReached
		}
	}

	err = s.q.CreateActivation(ctx, db.CreateActivationParams{
		PromoID:     promo.ID,
		UserID:      userID,
		Source:      string(src),
		ActivatedAt: now,
	})
	var pgErr *pgconn.PgError
	if errors.As(err, &pgErr) && pgErr.Code == pgUniqueViolation {
		// Double tap or a concurrent request from another device.
		return ErrPromoAlreadyUsed
	}
	if err != nil {
		return fmt.Errorf("create activation: %w", err)
	}

	s.log.Info("promo applied", "promo_id", promo.ID, "user_id", userID, "source", src)
	return nil
}

// hide makes a promo the user is not eligible for look non-existent:
// targeted and not yet launched campaigns must not leak to everyone else.
func (s *Service) hide(promo db.PromoCode, userID int64, src Source, reason string) error {
	s.log.Info("promo hidden", "promo_id", promo.ID, "user_id", userID, "source", src, "reason", reason)
	return ErrPromoNotFound
}

func segmentMatches(segment string, u User) bool {
	switch segment {
	case SegmentNew:
		return u.CompletedRides == 0
	case SegmentReturning:
		return u.CompletedRides > 0
	default:
		return true
	}
}
