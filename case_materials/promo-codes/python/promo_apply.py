from __future__ import annotations

import logging
from dataclasses import dataclass
from datetime import datetime, timezone
from enum import StrEnum
from typing import Callable, Protocol

from psycopg.errors import UniqueViolation

logger = logging.getLogger(__name__)


class Source(StrEnum):
    DEEPLINK = "deeplink"
    MANUAL = "manual"


SEGMENT_ALL = "all"
SEGMENT_NEW = "new"
SEGMENT_RETURNING = "returning"


class PromoError(Exception):
    pass


class PromoNotFound(PromoError):
    pass


class PromoExpired(PromoError):
    pass


class PromoAlreadyUsed(PromoError):
    pass


class PromoLimitReached(PromoError):
    pass


@dataclass(frozen=True, slots=True)
class PromoCode:
    """Row of promo_codes."""

    id: int
    code: str
    discount_percent: int
    starts_at: datetime
    ends_at: datetime
    is_active: bool
    max_activations: int | None
    per_user_limit: int
    segment: str
    city_ids: list[int]


@dataclass(frozen=True, slots=True)
class User:
    id: int
    city_id: int
    completed_rides: int


class Queries(Protocol):
    """Subset of the queries used by the service (see queries.sql)."""

    def get_promo_by_code(self, code: str) -> PromoCode | None: ...

    def count_user_activations(self, promo_id: int, user_id: int) -> int: ...

    def count_activations(self, promo_id: int) -> int: ...

    def create_activation(self, promo_id: int, user_id: int, source: str, activated_at: datetime) -> None: ...


class UserProvider(Protocol):
    def get(self, user_id: int) -> User: ...


def normalize_code(raw: str) -> str:
    return raw.strip().upper()


class PromoService:
    def __init__(
        self,
        queries: Queries,
        users: UserProvider,
        now: Callable[[], datetime] = lambda: datetime.now(timezone.utc),
    ) -> None:
        self._q = queries
        self._users = users
        self._now = now

    def apply(self, user_id: int, raw: str, source: Source) -> None:
        """Activates the promo code for the user. The discount itself is
        applied by pricing when the next ride is created."""
        promo = self._q.get_promo_by_code(normalize_code(raw))
        if promo is None:
            logger.info("promo not found", extra={"input": raw, "user_id": user_id, "source": source.value})
            raise PromoNotFound

        now = self._now()
        if not promo.is_active or now < promo.starts_at:
            raise self._hide(promo, user_id, source, "inactive")
        if now >= promo.ends_at:
            raise PromoExpired

        user = self._users.get(user_id)
        if not _segment_matches(promo.segment, user):
            raise self._hide(promo, user_id, source, "segment")
        if promo.city_ids and user.city_id not in promo.city_ids:
            raise self._hide(promo, user_id, source, "city")

        used = self._q.count_user_activations(promo.id, user_id)
        if used >= promo.per_user_limit:
            raise PromoAlreadyUsed

        if promo.max_activations is not None:
            total = self._q.count_activations(promo.id)
            if total >= promo.max_activations:
                raise PromoLimitReached

        try:
            self._q.create_activation(promo.id, user_id, source.value, now)
        except UniqueViolation:
            # Double tap or a concurrent request from another device.
            raise PromoAlreadyUsed from None

        logger.info("promo applied", extra={"promo_id": promo.id, "user_id": user_id, "source": source.value})

    def _hide(self, promo: PromoCode, user_id: int, source: Source, reason: str) -> PromoNotFound:
        """Makes a promo the user is not eligible for look non-existent:
        targeted and not yet launched campaigns must not leak to everyone else."""
        logger.info(
            "promo hidden",
            extra={"promo_id": promo.id, "user_id": user_id, "source": source.value, "reason": reason},
        )
        return PromoNotFound()


def _segment_matches(segment: str, user: User) -> bool:
    if segment == SEGMENT_NEW:
        return user.completed_rides == 0
    if segment == SEGMENT_RETURNING:
        return user.completed_rides > 0
    return True
