package paymentconsumer

import (
	"context"
	"encoding/json"
	"errors"
	"fmt"
	"log/slog"
	"time"

	"git.korzina.tech/platform/kafkaconsumer"
	"git.korzina.tech/platform/metrics"
)

const (
	nextOrderCampaign        = "next_order_speclist"
	nextOrderDiscountPercent = 20
	nextOrderDiscountTTL     = 14 * 24 * time.Hour
)

var (
	ErrOrderNotFound = errors.New("order not found")
	ErrAlreadyPaid   = errors.New("order already paid")
)

// PaymentSucceeded is the payload of the payments.succeeded topic.
type PaymentSucceeded struct {
	EventID   string    `json:"event_id"`
	PaymentID string    `json:"payment_id"`
	OrderID   int64     `json:"order_id"`
	UserID    int64     `json:"user_id"`
	Amount    int64     `json:"amount"` // minor units
	Currency  string    `json:"currency"`
	Country   string    `json:"country"`
	Method    string    `json:"method"`
	PaidAt    time.Time `json:"paid_at"`
	Items     []Item    `json:"items"`
}

type Item struct {
	SKU   string `json:"sku"`
	Qty   int    `json:"qty"`
	Price int64  `json:"price"`
}

type Receipt struct {
	IdempotencyKey string
	OrderID        int64
	UserID         int64
	Amount         int64
	Currency       string
	Items          []Item
	PaidAt         time.Time
}

type DiscountGrant struct {
	UserID        int64
	Campaign      string
	Percent       int
	ExpiresAt     time.Time
	SourceOrderID int64
}

type OrderStore interface {
	MarkPaid(ctx context.Context, orderID int64, paymentID string, paidAt time.Time) error
}

type ReceiptSender interface {
	Send(ctx context.Context, r Receipt) error
}

type PromoClient interface {
	GrantDiscount(ctx context.Context, g DiscountGrant) error
}

type Handler struct {
	orders   OrderStore
	receipts ReceiptSender
	promo    PromoClient
	log      *slog.Logger
	now      func() time.Time
}

func NewHandler(orders OrderStore, receipts ReceiptSender, promo PromoClient, log *slog.Logger) *Handler {
	return &Handler{
		orders:   orders,
		receipts: receipts,
		promo:    promo,
		log:      log,
		now:      time.Now,
	}
}

// Handle processes one payments.succeeded message. A non-nil error makes
// the consumer redeliver the message according to its retry policy.
func (h *Handler) Handle(ctx context.Context, msg kafkaconsumer.Message) error {
	var evt PaymentSucceeded
	if err := json.Unmarshal(msg.Value, &evt); err != nil {
		// Redelivery will not fix a broken payload.
		h.log.ErrorContext(ctx, "malformed payment event",
			"partition", msg.Partition, "offset", msg.Offset, "err", err)
		metrics.Inc("payment_consumer_skipped_total", "reason", "malformed")
		return nil
	}
	if evt.OrderID == 0 || evt.UserID == 0 || evt.PaymentID == "" {
		h.log.ErrorContext(ctx, "incomplete payment event",
			"event_id", evt.EventID, "partition", msg.Partition, "offset", msg.Offset)
		metrics.Inc("payment_consumer_skipped_total", "reason", "incomplete")
		return nil
	}

	err := h.orders.MarkPaid(ctx, evt.OrderID, evt.PaymentID, evt.PaidAt)
	switch {
	case errors.Is(err, ErrAlreadyPaid):
		h.log.InfoContext(ctx, "order already paid", "order_id", evt.OrderID, "event_id", evt.EventID)
	case errors.Is(err, ErrOrderNotFound):
		h.log.WarnContext(ctx, "payment for unknown order", "order_id", evt.OrderID, "event_id", evt.EventID)
		metrics.Inc("payment_consumer_skipped_total", "reason", "unknown_order")
		return nil
	case err != nil:
		return fmt.Errorf("mark order %d paid: %w", evt.OrderID, err)
	}

	receipt := Receipt{
		IdempotencyKey: evt.PaymentID,
		OrderID:        evt.OrderID,
		UserID:         evt.UserID,
		Amount:         evt.Amount,
		Currency:       evt.Currency,
		Items:          evt.Items,
		PaidAt:         evt.PaidAt,
	}
	if err := h.receipts.Send(ctx, receipt); err != nil {
		return fmt.Errorf("send receipt for order %d: %w", evt.OrderID, err)
	}

	if hasPromoSKU(evt.Items) {
		grant := DiscountGrant{
			UserID:        evt.UserID,
			Campaign:      nextOrderCampaign,
			Percent:       nextOrderDiscountPercent,
			ExpiresAt:     h.now().Add(nextOrderDiscountTTL),
			SourceOrderID: evt.OrderID,
		}
		if err := h.promo.GrantDiscount(ctx, grant); err != nil {
			return fmt.Errorf("grant discount to user %d: %w", evt.UserID, err)
		}
		metrics.Inc("payment_consumer_discounts_granted_total", "campaign", nextOrderCampaign)
	}

	return nil
}
