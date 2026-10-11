<?php

declare(strict_types=1);

namespace Veter\Rides\Promo\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Clock\MockClock;
use Veter\Rides\Promo\PromoAlreadyUsedException;
use Veter\Rides\Promo\PromoApplyService;
use Veter\Rides\Promo\PromoCode;
use Veter\Rides\Promo\PromoExpiredException;
use Veter\Rides\Promo\PromoLimitReachedException;
use Veter\Rides\Promo\PromoNotFoundException;
use Veter\Rides\Promo\PromoQueriesInterface;
use Veter\Rides\Promo\Source;
use Veter\Rides\Promo\User;
use Veter\Rides\Promo\UserProviderInterface;

final class PromoApplyServiceTest extends TestCase
{
    private const NOW = '2026-06-15T12:00:00+00:00';

    private InMemoryPromoQueries $queries;
    private PromoApplyService $service;

    protected function setUp(): void
    {
        $now = new \DateTimeImmutable(self::NOW);
        $promo = static fn (int $id, string $code, array $override = []): PromoCode => new PromoCode(...array_merge([
            'id' => $id,
            'code' => $code,
            'discountPercent' => 10,
            'startsAt' => $now->modify('-7 days'),
            'endsAt' => $now->modify('+7 days'),
            'isActive' => true,
            'maxActivations' => null,
            'perUserLimit' => 1,
            'segment' => PromoApplyService::SEGMENT_ALL,
            'cityIds' => [],
        ], $override));

        $this->queries = new InMemoryPromoQueries([
            $promo(1, 'WELCOME10'),
            $promo(2, 'SPRING25', ['endsAt' => $now->modify('-1 day')]),
            $promo(3, 'FIRST100', ['maxActivations' => 1]),
            $promo(4, 'HELLO', ['segment' => PromoApplyService::SEGMENT_NEW]),
            $promo(5, 'KZN15', ['cityIds' => [7]]),
            $promo(6, 'AUTUMN', ['isActive' => false]),
        ]);

        $users = new class implements UserProviderInterface {
            public function get(int $userId): User
            {
                return match ($userId) {
                    1 => new User(1, 1, 0),
                    2 => new User(2, 1, 14),
                    3 => new User(3, 7, 3),
                };
            }
        };

        $this->service = new PromoApplyService($this->queries, $users, new NullLogger(), new MockClock($now));
    }

    #[DataProvider('rawCodes')]
    public function testNormalizeCode(string $raw): void
    {
        self::assertSame('WELCOME10', PromoApplyService::normalizeCode($raw));
    }

    public static function rawCodes(): iterable
    {
        yield ['WELCOME10'];
        yield ['welcome10'];
        yield ['Welcome10'];
        yield ['  WELCOME10'];
        yield ["welcome10 \n"];
        yield ["\tWeLcOmE10  "];
    }

    /**
     * @param list<array{int, int}> $seed pairs of [promoId, userId]
     * @param class-string<\Throwable>|null $expected
     */
    #[DataProvider('applyCases')]
    public function testApply(int $userId, string $raw, array $seed, ?string $expected): void
    {
        foreach ($seed as [$promoId, $seedUserId]) {
            $this->queries->createActivation($promoId, $seedUserId, 'manual', new \DateTimeImmutable(self::NOW));
        }

        if ($expected !== null) {
            $this->expectException($expected);
        }
        $this->service->apply($userId, $raw, Source::Manual);

        $this->addToAssertionCount(1);
    }

    public static function applyCases(): iterable
    {
        yield 'exact code' => [2, 'WELCOME10', [], null];
        yield 'lower case' => [2, 'welcome10', [], null];
        yield 'mixed case with spaces' => [2, '  Welcome10 ', [], null];
        yield 'unknown code' => [2, 'NOSUCHCODE', [], PromoNotFoundException::class];
        yield 'empty input' => [2, '   ', [], PromoNotFoundException::class];
        yield 'expired' => [2, 'SPRING25', [], PromoExpiredException::class];
        yield 'already used by user' => [2, 'WELCOME10', [[1, 2]], PromoAlreadyUsedException::class];
        yield 'used by another user' => [2, 'WELCOME10', [[1, 3]], null];
        yield 'global limit reached' => [2, 'FIRST100', [[3, 3]], PromoLimitReachedException::class];
        yield 'new users only, new user' => [1, 'HELLO', [], null];
        yield 'new users only, returning user' => [2, 'HELLO', [], PromoNotFoundException::class];
        yield 'city promo, same city' => [3, 'KZN15', [], null];
        yield 'city promo, other city' => [2, 'KZN15', [], PromoNotFoundException::class];
        yield 'switched off' => [2, 'AUTUMN', [], PromoNotFoundException::class];
    }

    #[DataProvider('sources')]
    public function testApplyStoresSource(Source $source): void
    {
        $this->service->apply(2, 'welcome10', $source);

        self::assertEquals(
            [['promoId' => 1, 'userId' => 2, 'source' => $source->value, 'activatedAt' => new \DateTimeImmutable(self::NOW)]],
            $this->queries->activations,
        );
    }

    public static function sources(): iterable
    {
        yield [Source::Deeplink];
        yield [Source::Manual];
    }
}

final class InMemoryPromoQueries implements PromoQueriesInterface
{
    /** @var list<array{promoId: int, userId: int, source: string, activatedAt: \DateTimeImmutable}> */
    public array $activations = [];

    /**
     * @param list<PromoCode> $promos
     */
    public function __construct(private readonly array $promos)
    {
    }

    public function getPromoByCode(string $code): ?PromoCode
    {
        foreach ($this->promos as $promo) {
            if ($promo->code === $code) {
                return $promo;
            }
        }

        return null;
    }

    public function countUserActivations(int $promoId, int $userId): int
    {
        return count(array_filter(
            $this->activations,
            static fn (array $a): bool => $a['promoId'] === $promoId && $a['userId'] === $userId,
        ));
    }

    public function countActivations(int $promoId): int
    {
        return count(array_filter($this->activations, static fn (array $a): bool => $a['promoId'] === $promoId));
    }

    public function createActivation(int $promoId, int $userId, string $source, \DateTimeImmutable $activatedAt): void
    {
        $this->activations[] = compact('promoId', 'userId', 'source', 'activatedAt');
    }
}
