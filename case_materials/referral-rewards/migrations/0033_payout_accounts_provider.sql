ALTER TABLE payout_accounts ADD COLUMN provider smallint NOT NULL DEFAULT 1;

-- Tokens issued by the new acquirer have a distinct prefix.
UPDATE payout_accounts
SET provider = 2
WHERE card_token LIKE 'tkn2_%';
