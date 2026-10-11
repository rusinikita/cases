CREATE INDEX CONCURRENTLY rewards_user_created_idx ON rewards (user_id, created_at DESC);
