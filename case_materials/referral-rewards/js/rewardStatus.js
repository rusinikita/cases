/**
 * Lifecycle state of a referral reward. Persisted in rewards.status.
 *
 * @enum {number}
 */
export const RewardStatus = Object.freeze({
  PENDING: 1,
  PAID: 2,
  WAITING_ANTIFRAUD_RESULT: 3,
  REJECTED: 4,
});

const TRANSITIONS = Object.freeze({
  [RewardStatus.PENDING]: [RewardStatus.WAITING_ANTIFRAUD_RESULT],
  [RewardStatus.WAITING_ANTIFRAUD_RESULT]: [RewardStatus.PAID, RewardStatus.REJECTED],
});

const NAMES = Object.freeze(
  Object.fromEntries(Object.entries(RewardStatus).map(([name, value]) => [value, name.toLowerCase()])),
);

/**
 * @param {number} status
 * @returns {string}
 */
export function statusName(status) {
  return NAMES[status] ?? `RewardStatus(${status})`;
}

/**
 * Whether the reward will not change its status anymore.
 *
 * @param {number} status
 */
export function isFinal(status) {
  return status === RewardStatus.PAID || status === RewardStatus.REJECTED;
}

/**
 * @param {number} from
 * @param {number} to
 */
export function canTransition(from, to) {
  return (TRANSITIONS[from] ?? []).includes(to);
}
