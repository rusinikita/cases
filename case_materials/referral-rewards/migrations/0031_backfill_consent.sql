UPDATE rewards r
SET status = 3,
    status_changed_at = now()
WHERE r.status = 1
  AND NOT EXISTS (
      SELECT 1
      FROM program_consents c
      WHERE c.user_id = r.user_id
        AND c.terms_version >= 2
  );
