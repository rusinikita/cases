-- referral-service: schema of the rewards domain (PostgreSQL 16)
--
-- Approximate sizes, pg_class.reltuples as of 2026-10-12:
--   referrals         11.9M
--   rewards           10.5M
--   payout_accounts    6.2M
--   program_consents   3.4M

CREATE TABLE referrals (
    id            bigserial PRIMARY KEY,
    inviter_id    bigint      NOT NULL,
    invitee_id    bigint      NOT NULL,
    promo_code    text        NOT NULL,
    state         smallint    NOT NULL DEFAULT 1,
    qualified_at  timestamptz,
    created_at    timestamptz NOT NULL DEFAULT now(),
    CONSTRAINT referrals_invitee_key UNIQUE (invitee_id)
);

CREATE INDEX referrals_inviter_idx ON referrals (inviter_id, created_at DESC);
CREATE INDEX referrals_promo_code_idx ON referrals (promo_code);

CREATE TABLE rewards (
    id                 bigserial PRIMARY KEY,
    user_id            bigint      NOT NULL,
    referral_id        bigint      NOT NULL REFERENCES referrals (id),
    amount             integer     NOT NULL,            -- RUB
    status             smallint    NOT NULL DEFAULT 1,
    status_changed_at  timestamptz NOT NULL DEFAULT now(),
    payout_id          uuid,
    created_at         timestamptz NOT NULL DEFAULT now(),
    CONSTRAINT rewards_referral_key UNIQUE (referral_id),
    CONSTRAINT rewards_amount_positive CHECK (amount > 0)
);

CREATE INDEX rewards_status_changed_idx ON rewards (status, status_changed_at);
CREATE INDEX rewards_user_created_idx ON rewards (user_id, created_at DESC);
CREATE UNIQUE INDEX rewards_payout_id_key ON rewards (payout_id) WHERE payout_id IS NOT NULL;

CREATE TABLE payout_accounts (
    user_id     bigint PRIMARY KEY,
    provider    smallint    NOT NULL DEFAULT 1,
    card_token  text        NOT NULL,
    verified    boolean     NOT NULL DEFAULT false,
    updated_at  timestamptz NOT NULL DEFAULT now()
);

CREATE TABLE program_consents (
    user_id        bigint PRIMARY KEY,
    terms_version  integer     NOT NULL,
    accepted_at    timestamptz NOT NULL DEFAULT now()
);
