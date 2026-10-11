<?php

declare(strict_types=1);

namespace Korzina\Referral\Rewards;

use Doctrine\DBAL\Connection;
use JsonException;
use Korzina\Platform\Kafka\Message;
use Korzina\Platform\Kafka\MessageHandlerInterface;
use Korzina\Platform\Metrics\MetricsInterface;
use Psr\Log\LoggerInterface;

/**
 * Applies antifraud verdicts (topic antifraud.reward-verdicts) to rewards
 * that wait for them.
 */
final class AntifraudVerdictConsumer implements MessageHandlerInterface
{
    private const DECISION_OK = 'ok';
    private const DECISION_FRAUD = 'fraud';

    public function __construct(
        private readonly Connection $db,
        private readonly PayoutClientInterface $payouts,
        private readonly MetricsInterface $metrics,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Processes one verdict. Any exception thrown from here makes the consumer
     * redeliver the message.
     */
    public function handle(Message $message): void
    {
        try {
            /** @var array{check_id: string, reward_id: int, decision: string, reason?: string, decided_at: string} $verdict */
            $verdict = json_decode($message->value, true, 8, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            $this->logger->error('malformed antifraud verdict', [
                'partition' => $message->partition,
                'offset' => $message->offset,
                'exception' => $e,
            ]);
            $this->skipped('malformed');

            return;
        }

        $rewardId = (int) ($verdict['reward_id'] ?? 0);
        $checkId = (string) ($verdict['check_id'] ?? '');
        $decision = (string) ($verdict['decision'] ?? '');

        $row = $this->db->fetchAssociative(
            <<<'SQL'
                SELECT id, user_id, amount, status, status_changed_at
                FROM rewards
                WHERE id = :id
                SQL,
            ['id' => $rewardId],
        );
        if ($row === false) {
            $this->logger->warning('verdict for unknown reward', [
                'reward_id' => $rewardId,
                'check_id' => $checkId,
            ]);
            $this->skipped('unknown_reward');

            return;
        }
        $reward = Reward::fromRow($row);

        if ($reward->status !== RewardStatus::WaitingAntifraudResult) {
            // Late or duplicate verdict: the reward has already been settled,
            // e.g. by the timeout job.
            $this->logger->info('verdict ignored, reward is not waiting', [
                'reward_id' => $reward->id,
                'status' => $reward->status->name,
                'check_id' => $checkId,
            ]);
            $this->skipped('not_waiting');

            return;
        }

        switch ($decision) {
            case self::DECISION_OK:
                $this->approve($reward);
                break;
            case self::DECISION_FRAUD:
                $this->reject($reward, $checkId, (string) ($verdict['reason'] ?? ''));
                break;
            default:
                $this->logger->error('unknown antifraud decision', [
                    'reward_id' => $reward->id,
                    'decision' => $decision,
                    'check_id' => $checkId,
                ]);
                $this->skipped('unknown_decision');
        }
    }

    private function approve(Reward $reward): void
    {
        $payoutId = $this->payouts->pay(new PayoutRequest(
            idempotencyKey: sprintf('reward-%d', $reward->id),
            userId: $reward->userId,
            amount: $reward->amount,
        ));

        $this->db->executeStatement(
            <<<'SQL'
                UPDATE rewards
                SET status = :paid, status_changed_at = now(), payout_id = :payout_id
                WHERE id = :id AND status = :waiting
                SQL,
            [
                'paid' => RewardStatus::Paid->value,
                'payout_id' => $payoutId,
                'id' => $reward->id,
                'waiting' => RewardStatus::WaitingAntifraudResult->value,
            ],
        );
        $this->metrics->increment('rewards_paid_total', ['reason' => 'antifraud_ok']);
    }

    private function reject(Reward $reward, string $checkId, string $reason): void
    {
        $this->db->executeStatement(
            <<<'SQL'
                UPDATE rewards
                SET status = :rejected, status_changed_at = now()
                WHERE id = :id AND status = :waiting
                SQL,
            [
                'rejected' => RewardStatus::Rejected->value,
                'id' => $reward->id,
                'waiting' => RewardStatus::WaitingAntifraudResult->value,
            ],
        );
        $this->logger->info('reward rejected by antifraud', [
            'reward_id' => $reward->id,
            'user_id' => $reward->userId,
            'check_id' => $checkId,
            'reason' => $reason,
        ]);
        $this->metrics->increment('rewards_rejected_total', ['reason' => 'antifraud_fraud']);
    }

    private function skipped(string $reason): void
    {
        $this->metrics->increment('antifraud_verdicts_skipped_total', ['reason' => $reason]);
    }
}
