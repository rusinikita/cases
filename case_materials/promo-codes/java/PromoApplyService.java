package app.veter.rides.promo;

import java.time.Clock;
import java.time.Instant;
import java.util.List;
import java.util.Locale;
import java.util.Optional;
import org.slf4j.Logger;
import org.slf4j.LoggerFactory;
import org.springframework.dao.DuplicateKeyException;
import org.springframework.stereotype.Service;

@Service
public class PromoApplyService {

    private static final Logger log = LoggerFactory.getLogger(PromoApplyService.class);

    public enum Source {
        DEEPLINK("deeplink"),
        MANUAL("manual");

        private final String value;

        Source(String value) {
            this.value = value;
        }

        public String value() {
            return value;
        }
    }

    public static final String SEGMENT_ALL = "all";
    public static final String SEGMENT_NEW = "new";
    public static final String SEGMENT_RETURNING = "returning";

    private final PromoQueries queries;
    private final UserProvider users;
    private final Clock clock;

    public PromoApplyService(PromoQueries queries, UserProvider users, Clock clock) {
        this.queries = queries;
        this.users = users;
        this.clock = clock;
    }

    static String normalizeCode(String raw) {
        return raw.strip().toUpperCase(Locale.ROOT);
    }

    /**
     * Activates the promo code for the user. The discount itself is applied
     * by pricing when the next ride is created.
     */
    public void apply(long userId, String raw, Source source) {
        Optional<PromoCode> found = queries.getPromoByCode(normalizeCode(raw));
        if (found.isEmpty()) {
            log.info("promo not found, input={} user_id={} source={}", raw, userId, source.value());
            throw new PromoNotFoundException();
        }
        PromoCode promo = found.get();

        Instant now = clock.instant();
        if (!promo.isActive() || now.isBefore(promo.startsAt())) {
            throw hide(promo, userId, source, "inactive");
        }
        if (!now.isBefore(promo.endsAt())) {
            throw new PromoExpiredException();
        }

        User user = users.get(userId);
        if (!segmentMatches(promo.segment(), user)) {
            throw hide(promo, userId, source, "segment");
        }
        if (!promo.cityIds().isEmpty() && !promo.cityIds().contains(user.cityId())) {
            throw hide(promo, userId, source, "city");
        }

        long used = queries.countUserActivations(promo.id(), userId);
        if (used >= promo.perUserLimit()) {
            throw new PromoAlreadyUsedException();
        }

        if (promo.maxActivations() != null) {
            long total = queries.countActivations(promo.id());
            if (total >= promo.maxActivations()) {
                throw new PromoLimitReachedException();
            }
        }

        try {
            queries.createActivation(promo.id(), userId, source.value(), now);
        } catch (DuplicateKeyException e) {
            // Double tap or a concurrent request from another device.
            throw new PromoAlreadyUsedException();
        }

        log.info("promo applied, promo_id={} user_id={} source={}", promo.id(), userId, source.value());
    }

    /**
     * Makes a promo the user is not eligible for look non-existent:
     * targeted and not yet launched campaigns must not leak to everyone else.
     */
    private PromoNotFoundException hide(PromoCode promo, long userId, Source source, String reason) {
        log.info("promo hidden, promo_id={} user_id={} source={} reason={}",
                promo.id(), userId, source.value(), reason);
        return new PromoNotFoundException();
    }

    private static boolean segmentMatches(String segment, User user) {
        return switch (segment) {
            case SEGMENT_NEW -> user.completedRides() == 0;
            case SEGMENT_RETURNING -> user.completedRides() > 0;
            default -> true;
        };
    }

    /** Row of promo_codes. */
    public record PromoCode(
            long id,
            String code,
            int discountPercent,
            Instant startsAt,
            Instant endsAt,
            boolean isActive,
            Integer maxActivations,
            int perUserLimit,
            String segment,
            List<Integer> cityIds) {
    }

    public record User(long id, int cityId, int completedRides) {
    }

    /** Subset of the queries used by the service (see queries.sql). */
    public interface PromoQueries {
        Optional<PromoCode> getPromoByCode(String code);

        long countUserActivations(long promoId, long userId);

        long countActivations(long promoId);

        void createActivation(long promoId, long userId, String source, Instant activatedAt);
    }

    public interface UserProvider {
        User get(long userId);
    }

    public static class PromoException extends RuntimeException {
        PromoException(String message) {
            super(message);
        }
    }

    public static class PromoNotFoundException extends PromoException {
        PromoNotFoundException() {
            super("promo not found");
        }
    }

    public static class PromoExpiredException extends PromoException {
        PromoExpiredException() {
            super("promo expired");
        }
    }

    public static class PromoAlreadyUsedException extends PromoException {
        PromoAlreadyUsedException() {
            super("promo already used");
        }
    }

    public static class PromoLimitReachedException extends PromoException {
        PromoLimitReachedException() {
            super("promo activation limit reached");
        }
    }
}
