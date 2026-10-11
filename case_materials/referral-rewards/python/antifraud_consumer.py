from __future__ import annotations

import logging
from datetime import datetime

from psycopg.rows import class_row
from psycopg_pool import ConnectionPool
from pydantic import BaseModel, ValidationError

from korzina_platform import metrics
from korzina_platform.kafka import Message

from .antifraud_timeout_job import PayoutClient, PayoutRequest, Reward
from .status import RewardStatus

logger = logging.getLogger(__name__)

DECISION_OK = "ok"
DECISION_FRAUD = "fraud"


class AntifraudVerdict(BaseModel):
    """Payload of the antifraud.reward-verdicts topic."""

    check_id: str
    reward_id: int
    decision: str
    reason: str = ""
    decided_at: datetime


class AntifraudConsumer:
    """Applies antifraud verdicts to rewards that wait for them."""

    def __init__(self, pool: ConnectionPool, payouts: PayoutClient) -> None:
        self._pool = pool
        self._payouts = payouts

    def handle(self, message: Message) -> None:
        """Processes one verdict. Any exception raised from here makes the
        consumer redeliver the message."""
        try:
            verdict = AntifraudVerdict.model_validate_json(message.value)
        except ValidationError:
            logger.exception(
                "malformed antifraud verdict",
                extra={"partition": message.partition, "offset": message.offset},
            )
            metrics.inc("antifraud_verdicts_skipped_total", reason="malformed")
            return

        reward = self._load(verdict.reward_id)
        if reward is None:
            logger.warning(
                "verdict for unknown reward",
                extra={"reward_id": verdict.reward_id, "check_id": verdict.check_id},
            )
            metrics.inc("antifraud_verdicts_skipped_total", reason="unknown_reward")
            return

        if reward.status != RewardStatus.WAITING_ANTIFRAUD_RESULT:
            # Late or duplicate verdict: the reward has already been settled,
            # e.g. by the timeout job.
            logger.info(
                "verdict ignored, reward is not waiting",
                extra={"reward_id": reward.id, "status": reward.status, "check_id": verdict.check_id},
            )
            metrics.inc("antifraud_verdicts_skipped_total", reason="not_waiting")
            return

        if verdict.decision == DECISION_OK:
            self._approve(reward)
        elif verdict.decision == DECISION_FRAUD:
            self._reject(reward, verdict)
        else:
            logger.error(
                "unknown antifraud decision",
                extra={"reward_id": reward.id, "decision": verdict.decision, "check_id": verdict.check_id},
            )
            metrics.inc("antifraud_verdicts_skipped_total", reason="unknown_decision")

    def _load(self, reward_id: int) -> Reward | None:
        with self._pool.connection() as conn, conn.cursor(row_factory=class_row(Reward)) as cur:
            cur.execute(
                """
                SELECT id, user_id, amount, status, status_changed_at
                FROM rewards
                WHERE id = %s
                """,
                (reward_id,),
            )
            return cur.fetchone()

    def _approve(self, reward: Reward) -> None:
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
        metrics.inc("rewards_paid_total", reason="antifraud_ok")

    def _reject(self, reward: Reward, verdict: AntifraudVerdict) -> None:
        with self._pool.connection() as conn:
            conn.execute(
                """
                UPDATE rewards
                SET status = %s, status_changed_at = now()
                WHERE id = %s AND status = %s
                """,
                (RewardStatus.REJECTED, reward.id, RewardStatus.WAITING_ANTIFRAUD_RESULT),
            )
        logger.info(
            "reward rejected by antifraud",
            extra={
                "reward_id": reward.id,
                "user_id": reward.user_id,
                "check_id": verdict.check_id,
                "reason": verdict.reason,
            },
        )
        metrics.inc("rewards_rejected_total", reason="antifraud_fraud")
