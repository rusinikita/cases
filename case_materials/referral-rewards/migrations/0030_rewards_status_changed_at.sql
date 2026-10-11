ALTER TABLE rewards ADD COLUMN status_changed_at timestamptz;

UPDATE rewards SET status_changed_at = created_at WHERE status_changed_at IS NULL;

ALTER TABLE rewards
    ALTER COLUMN status_changed_at SET DEFAULT now(),
    ALTER COLUMN status_changed_at SET NOT NULL;

DROP INDEX IF EXISTS rewards_status_idx;
CREATE INDEX rewards_status_changed_idx ON rewards (status, status_changed_at);
