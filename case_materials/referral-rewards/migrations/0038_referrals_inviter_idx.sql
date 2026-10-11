DROP INDEX CONCURRENTLY IF EXISTS referrals_inviter_id_idx;

CREATE INDEX CONCURRENTLY referrals_inviter_idx ON referrals (inviter_id, created_at DESC);
