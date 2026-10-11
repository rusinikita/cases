import { metrics } from '@korzina/platform-metrics';

import { RewardStatus, statusName } from './rewardStatus.js';

const DECISION_OK = 'ok';
const DECISION_FRAUD = 'fraud';

/**
 * Payload of the antifraud.reward-verdicts topic.
 *
 * @typedef {object} AntifraudVerdict
 * @property {string} check_id
 * @property {number} reward_id
 * @property {string} decision
 * @property {string} [reason]
 * @property {string} decided_at ISO 8601
 */

/**
 * Applies antifraud verdicts to rewards that wait for them.
 */
export class AntifraudConsumer {
  /**
   * @param {object} deps
   * @param {import('pg').Pool} deps.db
   * @param {{pay(request: {idempotencyKey: string, userId: string, amount: number}): Promise<string>}} deps.payouts
   * @param {import('pino').Logger} deps.logger
   */
  constructor({ db, payouts, logger }) {
    this.db = db;
    this.payouts = payouts;
    this.logger = logger;
  }

  /**
   * Processes one verdict. A rejected promise makes the consumer redeliver
   * the message.
   *
   * @param {import('@korzina/platform-kafka').Message} message
   */
  async handle(message) {
    /** @type {AntifraudVerdict} */
    let verdict;
    try {
      verdict = JSON.parse(message.value.toString('utf8'));
    } catch (err) {
      this.logger.error(
        { partition: message.partition, offset: message.offset, err },
        'malformed antifraud verdict',
      );
      metrics.inc('antifraud_verdicts_skipped_total', { reason: 'malformed' });
      return;
    }

    const { rows } = await this.db.query(
      `SELECT id, user_id, amount, status, status_changed_at
       FROM rewards
       WHERE id = $1`,
      [verdict.reward_id],
    );
    const reward = rows[0];
    if (!reward) {
      this.logger.warn(
        { reward_id: verdict.reward_id, check_id: verdict.check_id },
        'verdict for unknown reward',
      );
      metrics.inc('antifraud_verdicts_skipped_total', { reason: 'unknown_reward' });
      return;
    }

    if (reward.status !== RewardStatus.WAITING_ANTIFRAUD_RESULT) {
      // Late or duplicate verdict: the reward has already been settled,
      // e.g. by the timeout job.
      this.logger.info(
        { reward_id: reward.id, status: statusName(reward.status), check_id: verdict.check_id },
        'verdict ignored, reward is not waiting',
      );
      metrics.inc('antifraud_verdicts_skipped_total', { reason: 'not_waiting' });
      return;
    }

    switch (verdict.decision) {
      case DECISION_OK:
        await this.approve(reward);
        break;
      case DECISION_FRAUD:
        await this.reject(reward, verdict);
        break;
      default:
        this.logger.error(
          { reward_id: reward.id, decision: verdict.decision, check_id: verdict.check_id },
          'unknown antifraud decision',
        );
        metrics.inc('antifraud_verdicts_skipped_total', { reason: 'unknown_decision' });
    }
  }

  async approve(reward) {
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
    metrics.inc('rewards_paid_total', { reason: 'antifraud_ok' });
  }

  /**
   * @param {object} reward
   * @param {AntifraudVerdict} verdict
   */
  async reject(reward, verdict) {
    await this.db.query(
      `UPDATE rewards
       SET status = $1, status_changed_at = now()
       WHERE id = $2 AND status = $3`,
      [RewardStatus.REJECTED, reward.id, RewardStatus.WAITING_ANTIFRAUD_RESULT],
    );
    this.logger.info(
      { reward_id: reward.id, user_id: reward.user_id, check_id: verdict.check_id, reason: verdict.reason },
      'reward rejected by antifraud',
    );
    metrics.inc('rewards_rejected_total', { reason: 'antifraud_fraud' });
  }
}
