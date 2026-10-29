-- Central Vet Pro - Etapa 3 (contracting), final review fix: a paid order whose
-- responsible e-mail is already a login gets a terminal "refunded" state
-- Migration: 20261026_0018_billing_order_refund
-- Status: PREPARED ONLY. Do not execute without a fresh backup and explicit SQL approval.
-- Target: MySQL 8.0.x, database centralvet, after 20261021_0017_etapa3_billing has been
-- applied (billing_order and schema_migrations already exist).
--
-- Effects:
--   * adds billing_order.subscription_cancelled_at (when the Stripe Subscription of a
--     card order was cancelled), billing_order.stripe_refund_id (the Stripe refund) and
--     billing_order.refunded_at (when the order reached 'refunded'), all NULL-able,
--     after invite_sent_at;
--   * widens billing_order_status_ck with 'refunded' (DROP CHECK followed by ADD
--     CONSTRAINT in separate statements, so the MySQL 5.7 preparer can drop and recreate
--     its triggers);
--   * in total: 3 new columns on an existing table and 1 widened CHECK
--     (billing_order_status_ck); no new table, UNIQUE or foreign key.
--
-- No data is seeded by this migration. Existing rows are preserved: every existing order
-- gets the 3 columns NULL and keeps a status the previous CHECK accepted, which the
-- widened one still accepts.
--
-- Risk: the ALTER TABLE on billing_order may rebuild the table (small; run in a
-- maintenance window). Between the DROP CHECK and the ADD CONSTRAINT of
-- billing_order_status_ck the table is briefly unconstrained. Which orders are refunded
-- and the order of the steps (cancel the subscription, record the refund, mark refunded)
-- are Application rules (BillingOrder). No new PII category is introduced:
-- stripe_refund_id is a Stripe reference. The Etapa 3 code writes the 3 columns on every
-- INSERT/UPDATE of billing_order: apply this migration before deploying it.
--
-- Rollback: restore the backup taken before applying, or write a numbered reverse
-- migration (see docs/runbooks/migration-rollback.md). Do not drop the new columns by
-- hand once a refund has been recorded.
--
-- MySQL DDL commits implicitly. On failure, stop and inspect; do not rerun blindly.
-- The literal migration checksum below MUST be replaced with the approved artifact's
-- SHA-256 by the migration executor before this file is applied.

ALTER TABLE billing_order
    ADD COLUMN subscription_cancelled_at timestamp(6) NULL AFTER invite_sent_at,
    ADD COLUMN stripe_refund_id varchar(64) NULL AFTER subscription_cancelled_at,
    ADD COLUMN refunded_at timestamp(6) NULL AFTER stripe_refund_id;

ALTER TABLE billing_order DROP CHECK billing_order_status_ck;

ALTER TABLE billing_order
    ADD CONSTRAINT billing_order_status_ck CHECK (status IN ('pending', 'checkout_open', 'paid', 'provisioned', 'provisioning_failed', 'expired', 'refunded'));

INSERT INTO schema_migrations (version, checksum)
VALUES ('20261026_0018_billing_order_refund',
        '0000000000000000000000000000000000000000000000000000000000000000' /* 64-char PLACEHOLDER: replace before applying */);
