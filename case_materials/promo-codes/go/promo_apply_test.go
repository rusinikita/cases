package promo

import (
	"context"
	"database/sql"
	"errors"
	"io"
	"log/slog"
	"testing"
	"time"

	"veter.app/rides/internal/db"
)

var testNow = time.Date(2026, 6, 15, 12, 0, 0, 0, time.UTC)

type fakeQueries struct {
	promos      map[string]db.PromoCode
	activations []db.CreateActivationParams
}

func (f *fakeQueries) GetPromoByCode(_ context.Context, code string) (db.PromoCode, error) {
	p, ok := f.promos[code]
	if !ok {
		return db.PromoCode{}, sql.ErrNoRows
	}
	return p, nil
}

func (f *fakeQueries) CountUserActivations(_ context.Context, arg db.CountUserActivationsParams) (int64, error) {
	var n int64
	for _, a := range f.activations {
		if a.PromoID == arg.PromoID && a.UserID == arg.UserID {
			n++
		}
	}
	return n, nil
}

func (f *fakeQueries) CountActivations(_ context.Context, promoID int64) (int64, error) {
	var n int64
	for _, a := range f.activations {
		if a.PromoID == promoID {
			n++
		}
	}
	return n, nil
}

func (f *fakeQueries) CreateActivation(_ context.Context, arg db.CreateActivationParams) error {
	f.activations = append(f.activations, arg)
	return nil
}

type fakeUsers map[int64]User

func (f fakeUsers) Get(_ context.Context, id int64) (User, error) {
	return f[id], nil
}

func promoFixture(id int64, code string, mod func(*db.PromoCode)) db.PromoCode {
	p := db.PromoCode{
		ID:              id,
		Code:            code,
		DiscountPercent: 10,
		StartsAt:        testNow.AddDate(0, 0, -7),
		EndsAt:          testNow.AddDate(0, 0, 7),
		IsActive:        true,
		PerUserLimit:    1,
		Segment:         SegmentAll,
	}
	if mod != nil {
		mod(&p)
	}
	return p
}

func newTestService(promos ...db.PromoCode) (*Service, *fakeQueries) {
	q := &fakeQueries{promos: map[string]db.PromoCode{}}
	for _, p := range promos {
		q.promos[p.Code] = p
	}
	users := fakeUsers{
		1: {ID: 1, CityID: 1, CompletedRides: 0},
		2: {ID: 2, CityID: 1, CompletedRides: 14},
		3: {ID: 3, CityID: 7, CompletedRides: 3},
	}
	s := NewService(q, users, slog.New(slog.NewTextHandler(io.Discard, nil)))
	s.now = func() time.Time { return testNow }
	return s, q
}

func TestNormalizeCode(t *testing.T) {
	tests := map[string]string{
		"WELCOME10":     "WELCOME10",
		"welcome10":     "WELCOME10",
		"Welcome10":     "WELCOME10",
		"  WELCOME10":   "WELCOME10",
		"welcome10 \n":  "WELCOME10",
		"\tWeLcOmE10  ": "WELCOME10",
	}
	for in, want := range tests {
		if got := normalizeCode(in); got != want {
			t.Errorf("normalizeCode(%q) = %q, want %q", in, got, want)
		}
	}
}

func TestApply(t *testing.T) {
	welcome := promoFixture(1, "WELCOME10", nil)
	expired := promoFixture(2, "SPRING25", func(p *db.PromoCode) { p.EndsAt = testNow.AddDate(0, 0, -1) })
	limited := promoFixture(3, "FIRST100", func(p *db.PromoCode) {
		p.MaxActivations = sql.NullInt32{Int32: 1, Valid: true}
	})
	newOnly := promoFixture(4, "HELLO", func(p *db.PromoCode) { p.Segment = SegmentNew })
	cityOnly := promoFixture(5, "KZN15", func(p *db.PromoCode) { p.CityIDs = []int32{7} })
	draft := promoFixture(6, "AUTUMN", func(p *db.PromoCode) { p.IsActive = false })

	tests := []struct {
		name    string
		userID  int64
		input   string
		seed    []db.CreateActivationParams
		wantErr error
	}{
		{"exact code", 2, "WELCOME10", nil, nil},
		{"lower case", 2, "welcome10", nil, nil},
		{"mixed case with spaces", 2, "  Welcome10 ", nil, nil},
		{"unknown code", 2, "NOSUCHCODE", nil, ErrPromoNotFound},
		{"empty input", 2, "   ", nil, ErrPromoNotFound},
		{"expired", 2, "SPRING25", nil, ErrPromoExpired},
		{"already used by user", 2, "WELCOME10", []db.CreateActivationParams{{PromoID: 1, UserID: 2}}, ErrPromoAlreadyUsed},
		{"used by another user", 2, "WELCOME10", []db.CreateActivationParams{{PromoID: 1, UserID: 3}}, nil},
		{"global limit reached", 2, "FIRST100", []db.CreateActivationParams{{PromoID: 3, UserID: 3}}, ErrPromoLimitReached},
		{"new users only, new user", 1, "HELLO", nil, nil},
		{"new users only, returning user", 2, "HELLO", nil, ErrPromoNotFound},
		{"city promo, same city", 3, "KZN15", nil, nil},
		{"city promo, other city", 2, "KZN15", nil, ErrPromoNotFound},
		{"switched off", 2, "AUTUMN", nil, ErrPromoNotFound},
	}
	for _, tt := range tests {
		t.Run(tt.name, func(t *testing.T) {
			s, q := newTestService(welcome, expired, limited, newOnly, cityOnly, draft)
			q.activations = append(q.activations, tt.seed...)

			err := s.Apply(context.Background(), tt.userID, tt.input, SourceManual)
			if !errors.Is(err, tt.wantErr) {
				t.Fatalf("Apply(%q) error = %v, want %v", tt.input, err, tt.wantErr)
			}
		})
	}
}

func TestApplyStoresSource(t *testing.T) {
	for _, src := range []Source{SourceDeeplink, SourceManual} {
		s, q := newTestService(promoFixture(1, "WELCOME10", nil))

		if err := s.Apply(context.Background(), 2, "welcome10", src); err != nil {
			t.Fatalf("Apply() error = %v", err)
		}
		if len(q.activations) != 1 {
			t.Fatalf("activations = %d, want 1", len(q.activations))
		}
		got := q.activations[0]
		if got.Source != string(src) || got.PromoID != 1 || got.UserID != 2 || !got.ActivatedAt.Equal(testNow) {
			t.Errorf("activation = %+v", got)
		}
	}
}
