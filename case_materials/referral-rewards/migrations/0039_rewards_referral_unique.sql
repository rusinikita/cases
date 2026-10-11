-- Duplicates left by the double-submit bug in the qualification worker:
-- keep the earliest reward per referral.
DELETE FROM rewards r
USING rewards keep
WHERE r.referral_id = keep.referral_id
  AND r.id > keep.id
  AND r.status = 1;

ALTER TABLE rewards ADD CONSTRAINT rewards_referral_key UNIQUE (referral_id);
