<?php

declare(strict_types=1);

namespace Veter\Rides\Promo;

use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;

enum Source: string
{
    case Deeplink = 'deeplink';
    case Manual = 'manual';
}

class PromoException extends \RuntimeException
{
}

final class PromoNotFoundException extends PromoException
{
    public function __construct()
    {
        parent::__construct('promo not found');
    }
}

final class PromoExpiredException extends PromoException
{
    public function __construct()
    {
        parent::__construct('promo expired');
    }
}

final class PromoAlreadyUsedException extends PromoException
{
    public function __construct()
    {
        parent::__construct('promo already used');
    }
}

final class PromoLimitReachedException extends PromoException
{
    public function __construct()
    {
        parent::__construct('promo activation limit reached');
    }
}

/**
 * Row of promo_codes.
 */
final readonly class PromoCode
{
    /**
     * @param list<int> $cityIds
     */
    public function __construct(
        public int $id,
        public string $code,
        public int $discountPercent,
        public \DateTimeImmutable $startsAt,
        public \DateTimeImmutable $endsAt,
        public bool $isActive,
        public ?int $maxActivations,
        public int $perUserLimit,
        public string $segment,
        public array $cityIds,
    ) {
    }
}

final readonly class User
{
    public function __construct(
        public int $id,
        public int $cityId,
        public int $completedRides,
    ) {
    }
}

/**
 * Subset of the queries used by the service (see queries.sql).
 */
interface PromoQueriesInterface
{
    public function getPromoByCode(string $code): ?PromoCode;

    public function countUserActivations(int $promoId, int $userId): int;

    public function countActivations(int $promoId): int;

    public function createActivation(int $promoId, int $userId, string $source, \DateTimeImmutable $activatedAt): void;
}

interface UserProviderInterface
{
    public function get(int $userId): User;
}

final class PromoApplyService
{
    public const SEGMENT_ALL = 'all';
    public const SEGMENT_NEW = 'new';
    public const SEGMENT_RETURNING = 'returning';

    public function __construct(
        private readonly PromoQueriesInterface $queries,
        private readonly UserProviderInterface $users,
        private readonly LoggerInterface $logger,
        private readonly ClockInterface $clock,
    ) {
    }

    public static function normalizeCode(string $raw): string
    {
        return mb_strtoupper(trim($raw));
    }

    /**
     * Activates the promo code for the user. The discount itself is applied
     * by pricing when the next ride is created.
     *
     * @throws PromoException
     */
    public function apply(int $userId, string $raw, Source $source): void
    {
        $promo = $this->queries->getPromoByCode(self::normalizeCode($raw));
        if ($promo === null) {
            $this->logger->info('promo not found', [
                'input' => $raw,
                'user_id' => $userId,
                'source' => $source->value,
            ]);

            throw new PromoNotFoundException();
        }

        $now = $this->clock->now();
        if (!$promo->isActive || $now < $promo->startsAt) {
            throw $this->hide($promo, $userId, $source, 'inactive');
        }
        if ($now >= $promo->endsAt) {
            throw new PromoExpiredException();
        }

        $user = $this->users->get($userId);
        if (!self::segmentMatches($promo->segment, $user)) {
            throw $this->hide($promo, $userId, $source, 'segment');
        }
        if ($promo->cityIds !== [] && !in_array($user->cityId, $promo->cityIds, true)) {
            throw $this->hide($promo, $userId, $source, 'city');
        }

        $used = $this->queries->countUserActivations($promo->id, $userId);
        if ($used >= $promo->perUserLimit) {
            throw new PromoAlreadyUsedException();
        }

        if ($promo->maxActivations !== null) {
            $total = $this->queries->countActivations($promo->id);
            if ($total >= $promo->maxActivations) {
                throw new PromoLimitReachedException();
            }
        }

        try {
            $this->queries->createActivation($promo->id, $userId, $source->value, $now);
        } catch (UniqueConstraintViolationException) {
            // Double tap or a concurrent request from another device.
            throw new PromoAlreadyUsedException();
        }

        $this->logger->info('promo applied', [
            'promo_id' => $promo->id,
            'user_id' => $userId,
            'source' => $source->value,
        ]);
    }

    /**
     * Makes a promo the user is not eligible for look non-existent:
     * targeted and not yet launched campaigns must not leak to everyone else.
     */
    private function hide(PromoCode $promo, int $userId, Source $source, string $reason): PromoNotFoundException
    {
        $this->logger->info('promo hidden', [
            'promo_id' => $promo->id,
            'user_id' => $userId,
            'source' => $source->value,
            'reason' => $reason,
        ]);

        return new PromoNotFoundException();
    }

    private static function segmentMatches(string $segment, User $user): bool
    {
        return match ($segment) {
            self::SEGMENT_NEW => $user->completedRides === 0,
            self::SEGMENT_RETURNING => $user->completedRides > 0,
            default => true,
        };
    }
}
