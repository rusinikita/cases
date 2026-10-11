import assert from 'node:assert/strict';
import { describe, it } from 'node:test';

import {
  PromoAlreadyUsedError,
  PromoExpiredError,
  PromoLimitReachedError,
  PromoNotFoundError,
  PromoService,
  Segment,
  Source,
  normalizeCode,
} from './promoApply.js';

const NOW = new Date('2026-06-15T12:00:00Z');
const DAY_MS = 24 * 60 * 60 * 1000;

const base = {
  id: 1,
  code: 'WELCOME10',
  discount_percent: 10,
  starts_at: new Date(NOW.getTime() - 7 * DAY_MS),
  ends_at: new Date(NOW.getTime() + 7 * DAY_MS),
  is_active: true,
  max_activations: null,
  per_user_limit: 1,
  segment: Segment.ALL,
  city_ids: [],
};

const promos = [
  base,
  { ...base, id: 2, code: 'SPRING25', ends_at: new Date(NOW.getTime() - DAY_MS) },
  { ...base, id: 3, code: 'FIRST100', max_activations: 1 },
  { ...base, id: 4, code: 'HELLO', segment: Segment.NEW },
  { ...base, id: 5, code: 'KZN15', city_ids: [7] },
  { ...base, id: 6, code: 'AUTUMN', is_active: false },
];

const users = {
  1: { id: 1, cityId: 1, completedRides: 0 },
  2: { id: 2, cityId: 1, completedRides: 14 },
  3: { id: 3, cityId: 7, completedRides: 3 },
};

function makeService(seed = []) {
  const activations = seed.map(([promoId, userId]) => ({ promoId, userId }));
  const queries = {
    getPromoByCode: async (code) => promos.find((p) => p.code === code) ?? null,
    countUserActivations: async (promoId, userId) =>
      activations.filter((a) => a.promoId === promoId && a.userId === userId).length,
    countActivations: async (promoId) => activations.filter((a) => a.promoId === promoId).length,
    createActivation: async (activation) => {
      activations.push(activation);
    },
  };
  const service = new PromoService({
    queries,
    users: { get: async (id) => users[id] },
    logger: { info() {} },
    now: () => NOW,
  });
  return { service, activations };
}

describe('normalizeCode', () => {
  for (const raw of ['WELCOME10', 'welcome10', 'Welcome10', '  WELCOME10', 'welcome10 \n', '\tWeLcOmE10  ']) {
    it(JSON.stringify(raw), () => {
      assert.equal(normalizeCode(raw), 'WELCOME10');
    });
  }
});

describe('PromoService.apply', () => {
  const cases = [
    ['exact code', 2, 'WELCOME10', [], null],
    ['lower case', 2, 'welcome10', [], null],
    ['mixed case with spaces', 2, '  Welcome10 ', [], null],
    ['unknown code', 2, 'NOSUCHCODE', [], PromoNotFoundError],
    ['empty input', 2, '   ', [], PromoNotFoundError],
    ['expired', 2, 'SPRING25', [], PromoExpiredError],
    ['already used by user', 2, 'WELCOME10', [[1, 2]], PromoAlreadyUsedError],
    ['used by another user', 2, 'WELCOME10', [[1, 3]], null],
    ['global limit reached', 2, 'FIRST100', [[3, 3]], PromoLimitReachedError],
    ['new users only, new user', 1, 'HELLO', [], null],
    ['new users only, returning user', 2, 'HELLO', [], PromoNotFoundError],
    ['city promo, same city', 3, 'KZN15', [], null],
    ['city promo, other city', 2, 'KZN15', [], PromoNotFoundError],
    ['switched off', 2, 'AUTUMN', [], PromoNotFoundError],
  ];

  for (const [name, userId, raw, seed, expected] of cases) {
    it(name, async () => {
      const { service } = makeService(seed);

      if (expected === null) {
        await service.apply(userId, raw, Source.MANUAL);
      } else {
        await assert.rejects(service.apply(userId, raw, Source.MANUAL), expected);
      }
    });
  }

  for (const source of [Source.DEEPLINK, Source.MANUAL]) {
    it(`stores source ${source}`, async () => {
      const { service, activations } = makeService();

      await service.apply(2, 'welcome10', source);

      assert.deepEqual(activations, [{ promoId: 1, userId: 2, source, activatedAt: NOW }]);
    });
  }
});
