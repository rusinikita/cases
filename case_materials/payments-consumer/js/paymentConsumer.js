import { metrics } from '@korzina/platform-metrics';

import { hasPromoSku } from './promoSkus.js';

const NEXT_ORDER_CAMPAIGN = 'next_order_speclist';
const NEXT_ORDER_DISCOUNT_PERCENT = 20;
const NEXT_ORDER_DISCOUNT_TTL_MS = 14 * 24 * 60 * 60 * 1000;

export class OrderNotFoundError extends Error {}
export class OrderAlreadyPaidError extends Error {}

/**
 * Payload of the payments.succeeded topic.
 *
 * @typedef {object} PaymentSucceeded
 * @property {string} event_id
 * @property {string} payment_id
 * @property {number} order_id
 * @property {number} user_id
 * @property {number} amount minor units
 * @property {string} currency
 * @property {string} country
 * @property {string} method
 * @property {string} paid_at ISO 8601
 * @property {Array<{sku: string, qty: number, price: number}>} items
 */

export class PaymentConsumer {
  /**
   * @param {object} deps
   * @param {{markPaid(ctx, orderId: number, paymentId: string, paidAt: Date): Promise<void>}} deps.orders
   * @param {{send(ctx, receipt: object): Promise<void>}} deps.receipts
   * @param {{grantDiscount(ctx, grant: object): Promise<void>}} deps.promo
   * @param {import('pino').Logger} deps.logger
   * @param {() => Date} [deps.now]
   */
  constructor({ orders, receipts, promo, logger, now = () => new Date() }) {
    this.orders = orders;
    this.receipts = receipts;
    this.promo = promo;
    this.logger = logger;
    this.now = now;
  }

  /**
   * Processes one payments.succeeded message. A rejected promise makes
   * the consumer redeliver the message according to its retry policy.
   *
   * @param {import('@korzina/platform-kafka').Context} ctx
   * @param {import('@korzina/platform-kafka').Message} message
   */
  async handle(ctx, message) {
    /** @type {PaymentSucceeded} */
    let evt;
    try {
      evt = JSON.parse(message.value.toString('utf8'));
    } catch (err) {
      // Redelivery will not fix a broken payload.
      this.logger.error(
        { partition: message.partition, offset: message.offset, err },
        'malformed payment event',
      );
      metrics.inc('payment_consumer_skipped_total', { reason: 'malformed' });
      return;
    }
    if (!evt?.order_id || !evt.user_id || !evt.payment_id) {
      this.logger.error(
        { event_id: evt?.event_id, partition: message.partition, offset: message.offset },
        'incomplete payment event',
      );
      metrics.inc('payment_consumer_skipped_total', { reason: 'incomplete' });
      return;
    }

    const paidAt = new Date(evt.paid_at);
    const items = evt.items ?? [];

    try {
      await this.orders.markPaid(ctx, evt.order_id, evt.payment_id, paidAt);
    } catch (err) {
      if (err instanceof OrderAlreadyPaidError) {
        this.logger.info({ order_id: evt.order_id, event_id: evt.event_id }, 'order already paid');
      } else if (err instanceof OrderNotFoundError) {
        this.logger.warn(
          { order_id: evt.order_id, event_id: evt.event_id },
          'payment for unknown order',
        );
        metrics.inc('payment_consumer_skipped_total', { reason: 'unknown_order' });
        return;
      } else {
        throw err;
      }
    }

    await this.receipts.send(ctx, {
      idempotencyKey: evt.payment_id,
      orderId: evt.order_id,
      userId: evt.user_id,
      amount: evt.amount,
      currency: evt.currency,
      items,
      paidAt,
    });

    if (hasPromoSku(items)) {
      await this.promo.grantDiscount(ctx, {
        userId: evt.user_id,
        campaign: NEXT_ORDER_CAMPAIGN,
        percent: NEXT_ORDER_DISCOUNT_PERCENT,
        expiresAt: new Date(this.now().getTime() + NEXT_ORDER_DISCOUNT_TTL_MS),
        sourceOrderId: evt.order_id,
      });
      metrics.inc('payment_consumer_discounts_granted_total', { campaign: NEXT_ORDER_CAMPAIGN });
    }
  }
}
