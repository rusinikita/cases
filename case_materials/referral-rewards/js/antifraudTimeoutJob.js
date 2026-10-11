import { metrics } from '@korzina/platform-metrics';

import { RewardStatus } from './rewardStatus.js';

// How long we wait for an antifraud verdict before paying the reward anyway.
// Fail open: an antifraud outage must not block honest users.
const ANTIFRAUD_VERDICT_TIMEOUT = '24 hours';

const JOB_INTERVAL_MS = 5 * 60 * 1000;
const BATCH_SIZE = 500;

/**
 * @typedef {object} Reward
 * @property {string} id bigint, as returned by pg
 * @property {string} user_id
 * @property {number} amount RUB
 * @property {number} status
 * @property {Date} status_changed_at
 */

/**
 * Pays out rewards whose antifraud check did not finish in time.
 */
export class AntifraudTimeoutJob {
  /**
   * @param {object} deps
   * @param {import('pg').Pool} deps.db
   * @param {{pay(request: {idempotencyKey: string, userId: string, amount: number}): Promise<string>}} deps.payouts
   *   transfers money to the user's linked card, resolves to the payout id
   * @param {import('pino').Logger} deps.logger
   */
  constructor({ db, payouts, logger }) {
    this.db = db;
    this.payouts = payouts;
    this.logger = logger;
    this.timer = null;
  }

  /** Runs the job immediately and then every JOB_INTERVAL_MS. */
  start() {
    const tick = async () => {
      try {
        await this.run();
      } catch (err) {
        this.logger.error({ err }, 'antifraud timeout job failed');
      }
      this.timer = setTimeout(tick, JOB_INTERVAL_MS);
    };
    void tick();
  }

  stop() {
    clearTimeout(this.timer);
  }

  async run() {
    let lastId = '0';
    let paid = 0;

    for (;;) {
      const batch = await this.overdue(lastId);
      if (batch.length === 0) {
        break;
      }
      for (const reward of batch) {
        lastId = reward.id;
        try {
          await this.payOut(reward);
          paid += 1;
        } catch (err) {
          // Left in place, the next run picks it up again.
          this.logger.warn({ reward_id: reward.id, err }, 'fail-open payout failed');
        }
      }
    }

    if (paid > 0) {
      this.logger.info({ count: paid }, 'antifraud verdict timed out, rewards paid');
    }
  }

  /**
   * @param {string} afterId
   * @returns {Promise<Reward[]>}
   */
  async overdue(afterId) {
    const { rows } = await this.db.query(
      `SELECT id, user_id, amount, status, status_changed_at
       FROM rewards
       WHERE status = $1
         AND status_changed_at < now() - $2::interval
         AND id > $3
       ORDER BY id
       LIMIT $4`,
      [RewardStatus.WAITING_ANTIFRAUD_RESULT, ANTIFRAUD_VERDICT_TIMEOUT, afterId, BATCH_SIZE],
    );
    return rows;
  }

  /** @param {Reward} reward */
  async payOut(reward) {
    const payoutId = await this.payouts.pay({
      idempotencyKey: `reward-${reward.id}`,
      userId: reward.user_id,
      amount: reward.amount,
    });

    await this.db.query(
      `UPDATE rewards
       SET status = $1, status_changed_at = now(), payout_id = $2
       WHERE id = $3 AND status = $4`,
      [RewardStatus.PAID, payoutId, reward.id, RewardStatus.WAITING_ANTIFRAUD_RESULT],
    );
    metrics.inc('rewards_paid_total', { reason: 'antifraud_timeout' });
  }
}
