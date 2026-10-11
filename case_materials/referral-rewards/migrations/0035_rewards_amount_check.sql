ALTER TABLE rewards
    ADD CONSTRAINT rewards_amount_positive CHECK (amount > 0) NOT VALID;

ALTER TABLE rewards VALIDATE CONSTRAINT rewards_amount_positive;
