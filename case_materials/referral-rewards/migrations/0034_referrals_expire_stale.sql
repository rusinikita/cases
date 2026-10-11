UPDATE referrals
SET state = 4
WHERE state = 1
  AND qualified_at IS NULL
  AND created_at < now() - interval '90 days';
