export const Source = Object.freeze({
  DEEPLINK: 'deeplink',
  MANUAL: 'manual',
});

export const Segment = Object.freeze({
  ALL: 'all',
  NEW: 'new',
  RETURNING: 'returning',
});

export class PromoError extends Error {
  constructor(message) {
    super(message);
    this.name = this.constructor.name;
  }
}

export class PromoNotFoundError extends PromoError {
  constructor() {
    super('promo not found');
  }
}

export class PromoExpiredError extends PromoError {
  constructor() {
    super('promo expired');
  }
}

export class PromoAlreadyUsedError extends PromoError {
  constructor() {
    super('promo already used');
  }
}

export class PromoLimitReachedError extends PromoError {
  constructor() {
    super('promo activation limit reached');
  }
}

const PG_UNIQUE_VIOLATION = '23505';

/**
 * Row of promo_codes.
 *
 * @typedef {object} PromoCode
 * @property {number} id
 * @property {string} code
 * @property {number} discount_percent
 * @property {Date} starts_at
 * @property {Date} ends_at
 * @property {boolean} is_active
 * @property {number|null} max_activations
 * @property {number} per_user_limit
 * @property {string} segment
 * @property {number[]} city_ids
 */

/**
 * @typedef {object} User
 * @property {number} id
 * @property {number} cityId
 * @property {number} completedRides
 */

/**
 * Subset of the queries used by the service (see queries.sql).
 *
 * @typedef {object} Queries
 * @property {(code: string) => Promise<PromoCode|null>} getPromoByCode
 * @property {(promoId: number, userId: number) => Promise<number>} countUserActivations
 * @property {(promoId: number) => Promise<number>} countActivations
 * @property {(activation: {promoId: number, userId: number, source: string, activatedAt: Date}) => Promise<void>} createActivation
 */

/**
 * @param {string} raw
 * @returns {string}
 */
export function normalizeCode(raw) {
  return raw.trim().toUpperCase();
}

export class PromoService {
  /**
   * @param {object} deps
   * @param {Queries} deps.queries
   * @param {{get(userId: number): Promise<User>}} deps.users
   * @param {import('pino').Logger} deps.logger
   * @param {() => Date} [deps.now]
   */
  constructor({ queries, users, logger, now = () => new Date() }) {
    this.q = queries;
    this.users = users;
    this.logger = logger;
    this.now = now;
  }

  /**
   * Activates the promo code for the user. The discount itself is applied
   * by pricing when the next ride is created.
   *
   * @param {number} userId
   * @param {string} raw
   * @param {string} source one of Source
   */
  async apply(userId, raw, source) {
    const promo = await this.q.getPromoByCode(normalizeCode(raw));
    if (!promo) {
      this.logger.info({ input: raw, user_id: userId, source }, 'promo not found');
      throw new PromoNotFoundError();
    }

    const now = this.now();
    if (!promo.is_active || now < promo.starts_at) {
      throw this.hide(promo, userId, source, 'inactive');
    }
    if (now >= promo.ends_at) {
      throw new PromoExpiredError();
    }

    const user = await this.users.get(userId);
    if (!segmentMatches(promo.segment, user)) {
      throw this.hide(promo, userId, source, 'segment');
    }
    if (promo.city_ids.length > 0 && !promo.city_ids.includes(user.cityId)) {
      throw this.hide(promo, userId, source, 'city');
    }

    const used = await this.q.countUserActivations(promo.id, userId);
    if (used >= promo.per_user_limit) {
      throw new PromoAlreadyUsedError();
    }

    if (promo.max_activations !== null) {
      const total = await this.q.countActivations(promo.id);
      if (total >= promo.max_activations) {
        throw new PromoLimitReachedError();
      }
    }

    try {
      await this.q.createActivation({ promoId: promo.id, userId, source, activatedAt: now });
    } catch (err) {
      if (err.code === PG_UNIQUE_VIOLATION) {
        // Double tap or a concurrent request from another device.
        throw new PromoAlreadyUsedError();
      }
      throw err;
    }

    this.logger.info({ promo_id: promo.id, user_id: userId, source }, 'promo applied');
  }

  /**
   * Makes a promo the user is not eligible for look non-existent:
   * targeted and not yet launched campaigns must not leak to everyone else.
   */
  hide(promo, userId, source, reason) {
    this.logger.info({ promo_id: promo.id, user_id: userId, source, reason }, 'promo hidden');
    return new PromoNotFoundError();
  }
}

/**
 * @param {string} segment
 * @param {User} user
 */
function segmentMatches(segment, user) {
  switch (segment) {
    case Segment.NEW:
      return user.completedRides === 0;
    case Segment.RETURNING:
      return user.completedRides > 0;
    default:
      return true;
  }
}
