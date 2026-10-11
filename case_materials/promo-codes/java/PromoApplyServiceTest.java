package app.veter.rides.promo;

import static org.assertj.core.api.Assertions.assertThat;
import static org.assertj.core.api.Assertions.assertThatCode;
import static org.assertj.core.api.Assertions.assertThatThrownBy;
import static org.junit.jupiter.params.provider.Arguments.arguments;

import app.veter.rides.promo.PromoApplyService.PromoAlreadyUsedException;
import app.veter.rides.promo.PromoApplyService.PromoCode;
import app.veter.rides.promo.PromoApplyService.PromoExpiredException;
import app.veter.rides.promo.PromoApplyService.PromoLimitReachedException;
import app.veter.rides.promo.PromoApplyService.PromoNotFoundException;
import app.veter.rides.promo.PromoApplyService.PromoQueries;
import app.veter.rides.promo.PromoApplyService.Source;
import app.veter.rides.promo.PromoApplyService.User;
import java.time.Clock;
import java.time.Duration;
import java.time.Instant;
import java.time.ZoneOffset;
import java.util.ArrayList;
import java.util.List;
import java.util.Map;
import java.util.Optional;
import java.util.stream.Stream;
import org.junit.jupiter.params.ParameterizedTest;
import org.junit.jupiter.params.provider.Arguments;
import org.junit.jupiter.params.provider.EnumSource;
import org.junit.jupiter.params.provider.MethodSource;
import org.junit.jupiter.params.provider.ValueSource;

class PromoApplyServiceTest {

    private static final Instant NOW = Instant.parse("2026-06-15T12:00:00Z");
    private static final Instant WEEK_AGO = NOW.minus(Duration.ofDays(7));
    private static final Instant IN_A_WEEK = NOW.plus(Duration.ofDays(7));

    private static final List<PromoCode> PROMOS = List.of(
            new PromoCode(1, "WELCOME10", 10, WEEK_AGO, IN_A_WEEK, true, null, 1, "all", List.of()),
            new PromoCode(2, "SPRING25", 10, WEEK_AGO, NOW.minus(Duration.ofDays(1)), true, null, 1, "all", List.of()),
            new PromoCode(3, "FIRST100", 10, WEEK_AGO, IN_A_WEEK, true, 1, 1, "all", List.of()),
            new PromoCode(4, "HELLO", 10, WEEK_AGO, IN_A_WEEK, true, null, 1, "new", List.of()),
            new PromoCode(5, "KZN15", 10, WEEK_AGO, IN_A_WEEK, true, null, 1, "all", List.of(7)),
            new PromoCode(6, "AUTUMN", 10, WEEK_AGO, IN_A_WEEK, false, null, 1, "all", List.of()));

    private static final Map<Long, User> USERS = Map.of(
            1L, new User(1, 1, 0),
            2L, new User(2, 1, 14),
            3L, new User(3, 7, 3));

    record Activation(long promoId, long userId, String source, Instant activatedAt) {
    }

    static class FakeQueries implements PromoQueries {
        final List<Activation> activations = new ArrayList<>();

        @Override
        public Optional<PromoCode> getPromoByCode(String code) {
            return PROMOS.stream().filter(p -> p.code().equals(code)).findFirst();
        }

        @Override
        public long countUserActivations(long promoId, long userId) {
            return activations.stream().filter(a -> a.promoId() == promoId && a.userId() == userId).count();
        }

        @Override
        public long countActivations(long promoId) {
            return activations.stream().filter(a -> a.promoId() == promoId).count();
        }

        @Override
        public void createActivation(long promoId, long userId, String source, Instant activatedAt) {
            activations.add(new Activation(promoId, userId, source, activatedAt));
        }
    }

    private final FakeQueries queries = new FakeQueries();
    private final PromoApplyService service =
            new PromoApplyService(queries, USERS::get, Clock.fixed(NOW, ZoneOffset.UTC));

    @ParameterizedTest
    @ValueSource(strings = {"WELCOME10", "welcome10", "Welcome10", "  WELCOME10", "welcome10 \n", "\tWeLcOmE10  "})
    void normalizesCode(String raw) {
        assertThat(PromoApplyService.normalizeCode(raw)).isEqualTo("WELCOME10");
    }

    static Stream<Arguments> applyCases() {
        return Stream.of(
                arguments("exact code", 2L, "WELCOME10", List.of(), null),
                arguments("lower case", 2L, "welcome10", List.of(), null),
                arguments("mixed case with spaces", 2L, "  Welcome10 ", List.of(), null),
                arguments("unknown code", 2L, "NOSUCHCODE", List.of(), PromoNotFoundException.class),
                arguments("empty input", 2L, "   ", List.of(), PromoNotFoundException.class),
                arguments("expired", 2L, "SPRING25", List.of(), PromoExpiredException.class),
                arguments("already used by user", 2L, "WELCOME10",
                        List.of(new Activation(1, 2, "manual", NOW)), PromoAlreadyUsedException.class),
                arguments("used by another user", 2L, "WELCOME10",
                        List.of(new Activation(1, 3, "manual", NOW)), null),
                arguments("global limit reached", 2L, "FIRST100",
                        List.of(new Activation(3, 3, "manual", NOW)), PromoLimitReachedException.class),
                arguments("new users only, new user", 1L, "HELLO", List.of(), null),
                arguments("new users only, returning user", 2L, "HELLO", List.of(), PromoNotFoundException.class),
                arguments("city promo, same city", 3L, "KZN15", List.of(), null),
                arguments("city promo, other city", 2L, "KZN15", List.of(), PromoNotFoundException.class),
                arguments("switched off", 2L, "AUTUMN", List.of(), PromoNotFoundException.class));
    }

    @ParameterizedTest(name = "{0}")
    @MethodSource("applyCases")
    void apply(String name, long userId, String raw, List<Activation> seed, Class<? extends Throwable> expected) {
        queries.activations.addAll(seed);

        if (expected == null) {
            assertThatCode(() -> service.apply(userId, raw, Source.MANUAL)).doesNotThrowAnyException();
        } else {
            assertThatThrownBy(() -> service.apply(userId, raw, Source.MANUAL)).isInstanceOf(expected);
        }
    }

    @ParameterizedTest
    @EnumSource(Source.class)
    void storesSource(Source source) {
        service.apply(2, "welcome10", source);

        assertThat(queries.activations).containsExactly(new Activation(1, 2, source.value(), NOW));
    }
}
