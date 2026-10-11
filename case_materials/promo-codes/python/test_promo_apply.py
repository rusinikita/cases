from __future__ import annotations

from dataclasses import replace
from datetime import datetime, timedelta, timezone

import pytest

from .promo_apply import (
    SEGMENT_ALL,
    SEGMENT_NEW,
    PromoAlreadyUsed,
    PromoCode,
    PromoExpired,
    PromoLimitReached,
    PromoNotFound,
    PromoService,
    Source,
    User,
    normalize_code,
)

NOW = datetime(2026, 6, 15, 12, 0, tzinfo=timezone.utc)

BASE = PromoCode(
    id=1,
    code="WELCOME10",
    discount_percent=10,
    starts_at=NOW - timedelta(days=7),
    ends_at=NOW + timedelta(days=7),
    is_active=True,
    max_activations=None,
    per_user_limit=1,
    segment=SEGMENT_ALL,
    city_ids=[],
)

PROMOS = [
    BASE,
    replace(BASE, id=2, code="SPRING25", ends_at=NOW - timedelta(days=1)),
    replace(BASE, id=3, code="FIRST100", max_activations=1),
    replace(BASE, id=4, code="HELLO", segment=SEGMENT_NEW),
    replace(BASE, id=5, code="KZN15", city_ids=[7]),
    replace(BASE, id=6, code="AUTUMN", is_active=False),
]

USERS = {
    1: User(id=1, city_id=1, completed_rides=0),
    2: User(id=2, city_id=1, completed_rides=14),
    3: User(id=3, city_id=7, completed_rides=3),
}


class FakeQueries:
    def __init__(self, promos, activations=()):
        self.promos = {p.code: p for p in promos}
        self.activations = [dict(promo_id=p, user_id=u) for p, u in activations]

    def get_promo_by_code(self, code):
        return self.promos.get(code)

    def count_user_activations(self, promo_id, user_id):
        return sum(1 for a in self.activations if a["promo_id"] == promo_id and a["user_id"] == user_id)

    def count_activations(self, promo_id):
        return sum(1 for a in self.activations if a["promo_id"] == promo_id)

    def create_activation(self, promo_id, user_id, source, activated_at):
        self.activations.append(
            dict(promo_id=promo_id, user_id=user_id, source=source, activated_at=activated_at)
        )


class FakeUsers:
    def get(self, user_id):
        return USERS[user_id]


def make_service(activations=()):
    queries = FakeQueries(PROMOS, activations)
    return PromoService(queries, FakeUsers(), now=lambda: NOW), queries


@pytest.mark.parametrize(
    "raw",
    ["WELCOME10", "welcome10", "Welcome10", "  WELCOME10", "welcome10 \n", "\tWeLcOmE10  "],
)
def test_normalize_code(raw):
    assert normalize_code(raw) == "WELCOME10"


@pytest.mark.parametrize(
    "user_id, raw, activations, expected",
    [
        pytest.param(2, "WELCOME10", [], None, id="exact code"),
        pytest.param(2, "welcome10", [], None, id="lower case"),
        pytest.param(2, "  Welcome10 ", [], None, id="mixed case with spaces"),
        pytest.param(2, "NOSUCHCODE", [], PromoNotFound, id="unknown code"),
        pytest.param(2, "   ", [], PromoNotFound, id="empty input"),
        pytest.param(2, "SPRING25", [], PromoExpired, id="expired"),
        pytest.param(2, "WELCOME10", [(1, 2)], PromoAlreadyUsed, id="already used by user"),
        pytest.param(2, "WELCOME10", [(1, 3)], None, id="used by another user"),
        pytest.param(2, "FIRST100", [(3, 3)], PromoLimitReached, id="global limit reached"),
        pytest.param(1, "HELLO", [], None, id="new users only, new user"),
        pytest.param(2, "HELLO", [], PromoNotFound, id="new users only, returning user"),
        pytest.param(3, "KZN15", [], None, id="city promo, same city"),
        pytest.param(2, "KZN15", [], PromoNotFound, id="city promo, other city"),
        pytest.param(2, "AUTUMN", [], PromoNotFound, id="switched off"),
    ],
)
def test_apply(user_id, raw, activations, expected):
    service, _ = make_service(activations)

    if expected is None:
        service.apply(user_id, raw, Source.MANUAL)
    else:
        with pytest.raises(expected):
            service.apply(user_id, raw, Source.MANUAL)


@pytest.mark.parametrize("source", [Source.DEEPLINK, Source.MANUAL])
def test_apply_stores_source(source):
    service, queries = make_service()

    service.apply(2, "welcome10", source)

    assert queries.activations == [
        dict(promo_id=1, user_id=2, source=source.value, activated_at=NOW),
    ]
