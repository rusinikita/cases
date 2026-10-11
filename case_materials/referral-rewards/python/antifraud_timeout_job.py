from __future__ import annotations

import logging
from dataclasses import dataclass
from datetime import datetime, timedelta
from typing import Protocol
from uuid import UUID

from psycopg.rows import class_row
from psycopg_pool import ConnectionPool

from korzina_platform import metrics
from korzina_platform.scheduler import periodic

from .status import RewardStatus

logger = logging.getLogger(__name__)

# How long we wait for an antifraud verdict before paying the reward anyway.
# Fail open: an antifraud outage must not block honest users.
ANTIFRAUD_VERDICT_TIMEOUT = timedelta(hours=24)

JOB_INTERVAL = timedelta(minutes=5)
BATCH_SIZE = 500


@dataclass(frozen=True, slots=True)
class Reward:
    id: int
    user_id: int
    amount: int  # RUB
    status: int
    status_changed_at: datetime


@dataclass(frozen=True, slots=True)
class PayoutRequest:
    idempotency_key: str
    user_id: int
    amount: int


class PayoutClient(Protocol):
    """Transfers money to the user's linked card."""

    def pay(self, request: PayoutRequest) -> UUID: ...


class AntifraudTimeoutJob:
    """Pays out rewards whose antifraud check did not finish in time."""

    def __init__(self, pool: ConnectionPool, payouts: PayoutClient) -> None:
        self._pool = pool
        self._payouts = payouts

    @periodic(every=JOB_INTERVAL, run_on_start=True)
    def run(self) -> None:
        last_id = 0
        paid = 0
        while True:
            batch = self._overdue(last_id)
            if not batch:
                break
            for reward in batch:
                last_id = reward.id
                try:
                    self._pay_out(reward)
                except Exception:
                    # Left in place, the next run picks it up again.
                    logger.warning("fail-open payout failed", extra={"reward_id": reward.id}, exc_info=True)
                    continue
                paid += 1
        if paid:
            logger.info("antifraud verdict timed out, rewards paid", extra={"count": paid})

    def _overdue(self, after_id: int) -> list[Reward]:
        with self._pool.connection() as conn, conn.cursor(row_factory=class_row(Reward)) as cur:
            cur.execute(
                """
                SELECT id, user_id, amount, status, status_changed_at
                FROM rewards
                WHERE status = %s
                  AND status_changed_at < now() - %s
                  AND id > %s
                ORDER BY id
                LIMIT %s
                """,
                (RewardStatus.WAITING_ANTIFRAUD_RESULT, ANTIFRAUD_VERDICT_TIMEOUT, after_id, BATCH_SIZE),
            )
            return cur.fetchall()

    def _pay_out(self, reward: Reward) -> None:
        payout_id = self._payouts.pay(
            PayoutRequest(
                idempotency_key=f"reward-{reward.id}",
                user_id=reward.user_id,
                amount=reward.amount,
            )
        )
        with self._pool.connection() as conn:
            conn.execute(
                """
                UPDATE rewards
                SET status = %s, status_changed_at = now(), payout_id = %s
                WHERE id = %s AND status = %s
                """,
                (RewardStatus.PAID, payout_id, reward.id, RewardStatus.WAITING_ANTIFRAUD_RESULT),
            )
        metrics.inc("rewards_paid_total", reason="antifraud_timeout")
