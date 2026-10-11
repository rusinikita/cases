ALTER TABLE payout_accounts ADD COLUMN verified boolean NOT NULL DEFAULT false;

UPDATE payout_accounts pa
SET verified = true
WHERE EXISTS (
    SELECT 1
    FROM rewards r
    WHERE r.user_id = pa.user_id
      AND r.status = 2
);
