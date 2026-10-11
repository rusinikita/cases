CREATE TABLE program_consents (
    user_id        bigint PRIMARY KEY,
    terms_version  integer     NOT NULL,
    accepted_at    timestamptz NOT NULL DEFAULT now()
);
