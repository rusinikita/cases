from __future__ import annotations

import logging
from dataclasses import dataclass
from datetime import datetime, timedelta, timezone
from typing import Callable, Protocol

from pydantic import BaseModel, ValidationError

from korzina_platform import metrics
from korzina_platform.kafka import Context, Message

from .promo_skus import has_promo_sku

logger = logging.getLogger(__name__)

NEXT_ORDER_CAMPAIGN = "next_order_speclist"
NEXT_ORDER_DISCOUNT_PERCENT = 20
NEXT_ORDER_DISCOUNT_TTL = timedelta(days=14)


class OrderNotFoundError(Exception):
    pass


class OrderAlreadyPaidError(Exception):
    pass


class Item(BaseModel):
    sku: str
    qty: int
    price: int


class PaymentSucceeded(BaseModel):
    """Payload of the payments.succeeded topic."""

    event_id: str = ""
    payment_id: str = ""
    order_id: int = 0
    user_id: int = 0
    amount: int = 0  # minor units
    currency: str = ""
    country: str = ""
    method: str = ""
    paid_at: datetime
    items: list[Item] = []


@dataclass(frozen=True, slots=True)
class Receipt:
    idempotency_key: str
    order_id: int
    user_id: int
    amount: int
    currency: str
    items: list[Item]
    paid_at: datetime


@dataclass(frozen=True, slots=True)
class DiscountGrant:
    user_id: int
    campaign: str
    percent: int
    expires_at: datetime
    source_order_id: int


class OrderStore(Protocol):
    async def mark_paid(
        self, ctx: Context, order_id: int, payment_id: str, paid_at: datetime
    ) -> None: ...


class ReceiptSender(Protocol):
    async def send(self, ctx: Context, receipt: Receipt) -> None: ...


class PromoClient(Protocol):
    async def grant_discount(self, ctx: Context, grant: DiscountGrant) -> None: ...


class PaymentConsumer:
    def __init__(
        self,
        orders: OrderStore,
        receipts: ReceiptSender,
        promo: PromoClient,
        now: Callable[[], datetime] = lambda: datetime.now(timezone.utc),
    ) -> None:
        self._orders = orders
        self._receipts = receipts
        self._promo = promo
        self._now = now

    async def handle(self, ctx: Context, msg: Message) -> None:
        """Process one payments.succeeded message.

        Any exception raised from here makes the consumer redeliver
        the message according to its retry policy.
        """
        try:
            evt = PaymentSucceeded.model_validate_json(msg.value)
        except ValidationError:
            # Redelivery will not fix a broken payload.
            logger.exception(
                "malformed payment event",
                extra={"partition": msg.partition, "offset": msg.offset},
            )
            metrics.inc("payment_consumer_skipped_total", reason="malformed")
            return

        if not evt.order_id or not evt.user_id or not evt.payment_id:
            logger.error(
                "incomplete payment event",
                extra={
                    "event_id": evt.event_id,
                    "partition": msg.partition,
                    "offset": msg.offset,
                },
            )
            metrics.inc("payment_consumer_skipped_total", reason="incomplete")
            return

        try:
            await self._orders.mark_paid(ctx, evt.order_id, evt.payment_id, evt.paid_at)
        except OrderAlreadyPaidError:
            logger.info(
                "order already paid",
                extra={"order_id": evt.order_id, "event_id": evt.event_id},
            )
        except OrderNotFoundError:
            logger.warning(
                "payment for unknown order",
                extra={"order_id": evt.order_id, "event_id": evt.event_id},
            )
            metrics.inc("payment_consumer_skipped_total", reason="unknown_order")
            return

        await self._receipts.send(
            ctx,
            Receipt(
                idempotency_key=evt.payment_id,
                order_id=evt.order_id,
                user_id=evt.user_id,
                amount=evt.amount,
                currency=evt.currency,
                items=evt.items,
                paid_at=evt.paid_at,
            ),
        )

        if has_promo_sku(evt.items):
            await self._promo.grant_discount(
                ctx,
                DiscountGrant(
                    user_id=evt.user_id,
                    campaign=NEXT_ORDER_CAMPAIGN,
                    percent=NEXT_ORDER_DISCOUNT_PERCENT,
                    expires_at=self._now() + NEXT_ORDER_DISCOUNT_TTL,
                    source_order_id=evt.order_id,
                ),
            )
            metrics.inc(
                "payment_consumer_discounts_granted_total",
                campaign=NEXT_ORDER_CAMPAIGN,
            )
