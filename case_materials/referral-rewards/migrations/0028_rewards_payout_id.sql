ALTER TABLE rewards ADD COLUMN payout_id uuid;

CREATE UNIQUE INDEX CONCURRENTLY rewards_payout_id_key
    ON rewards (payout_id)
    WHERE payout_id IS NOT NULL;
