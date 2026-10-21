-- Central Vet Pro - Etapa 3 (contratação): purchase order from the landing page, monthly
-- subscription paid by card (Stripe Subscription) or Pix (one PaymentIntent per period),
-- its invoices and the authenticated Stripe events log
-- Migration: 20261021_0017_etapa3_billing
-- Status: PREPARED ONLY. Do not execute without a fresh backup and explicit SQL approval.
-- Target: MySQL 8.0.x, database centralvet, after 20261021_0016_etapa2_sale_finance has
-- been applied (tenant and schema_migrations already exist).
--
-- Effects:
--   * creates billing_order (one purchase started on the landing page: plan, price taken
--     from the server, responsible person, terms acceptance, payment method, Stripe
--     checkout references, provisioning progress) with UNIQUEs billing_order_public_id_uq
--     and billing_order_checkout_uq, FK billing_order_tenant_fk -> tenant (NULL until the
--     tenant is provisioned) and CHECKs billing_order_plan_ck, billing_order_method_ck and
--     billing_order_status_ck;
--   * creates subscription (one per billing order and at most one per tenant) with
--     UNIQUEs subscription_billing_order_uq, subscription_tenant_uq and
--     subscription_stripe_uq (NULL on Pix), FKs subscription_billing_order_fk and
--     subscription_tenant_fk and CHECKs subscription_plan_ck, subscription_status_ck and
--     subscription_method_ck;
--   * creates subscription_invoice (a Stripe invoice on card, a local invoice keyed by
--     local_key 'pix:<subscription_id>:<period_start Y-m-d>' with its own PaymentIntent on
--     Pix) with UNIQUEs subscription_invoice_stripe_uq, subscription_invoice_local_key_uq
--     and subscription_invoice_pi_uq, FK subscription_invoice_subscription_fk and CHECKs
--     subscription_invoice_status_ck and subscription_invoice_ref_ck (an invoice has a
--     Stripe id or a local key);
--   * creates billing_event (every Stripe event received by the webhook or fetched by the
--     reconciler, once per provider/event id) with UNIQUE billing_event_provider_event_uq
--     and CHECK billing_event_status_ck;
--   * in total: 4 new tables, 9 CHECK constraints, 9 UNIQUE constraints and 4 foreign
--     keys.
--
-- No data is seeded by this migration and no existing table is altered. Existing tenants
-- have no subscription row (the Application treats them as legacy, with full access).
--
-- Risk: billing_order holds personal data of the responsible person (name, e-mail,
-- phone, IP of the terms acceptance) and billing_event.payload holds the raw Stripe JSON
-- (it includes the customer e-mail): both are personal data under LGPD; retention is
-- defined in Etapa 4. No card data is stored (Stripe Checkout collects it). The login
-- guard reads subscription: apply this migration before deploying the Etapa 3 code.
--
-- Rollback: restore the backup taken before applying, or write a numbered reverse
-- migration (see docs/runbooks/migration-rollback.md). Do not drop the new tables by
-- hand once orders, subscriptions, invoices or events exist.
--
-- MySQL DDL commits implicitly. On failure, stop and inspect; do not rerun blindly.
-- The literal migration checksum below MUST be replaced with the approved artifact's
-- SHA-256 by the migration executor before this file is applied.

CREATE TABLE billing_order (
    id bigint unsigned NOT NULL AUTO_INCREMENT,
    public_id char(36) NOT NULL,
    plan_id varchar(20) NOT NULL,
    price_cents int unsigned NOT NULL,
    currency char(3) NOT NULL DEFAULT 'brl',
    payment_method varchar(10) NOT NULL,
    responsible_name varchar(160) NOT NULL,
    responsible_email varchar(190) NOT NULL,
    responsible_phone varchar(30) NOT NULL,
    clinic_name varchar(190) NOT NULL,
    terms_version varchar(40) NOT NULL,
    terms_accepted_at timestamp(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    terms_ip varchar(45) NOT NULL,
    contact_consent tinyint(1) NOT NULL DEFAULT 0,
    status varchar(24) NOT NULL,
    stripe_customer_id varchar(64) NULL,
    stripe_checkout_session_id varchar(100) NULL,
    stripe_subscription_id varchar(64) NULL,
    paid_at timestamp(6) NULL,
    tenant_id bigint unsigned NULL,
    provisioning_attempts int unsigned NOT NULL DEFAULT 0,
    provisioning_error varchar(120) NULL,
    invite_sent_at timestamp(6) NULL,
    created_at timestamp(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    updated_at timestamp(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    PRIMARY KEY (id),
    UNIQUE KEY billing_order_public_id_uq (public_id),
    UNIQUE KEY billing_order_checkout_uq (stripe_checkout_session_id),
    KEY billing_order_status_idx (status),
    KEY billing_order_tenant_idx (tenant_id),
    CONSTRAINT billing_order_tenant_fk FOREIGN KEY (tenant_id) REFERENCES tenant (id)
        ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT billing_order_plan_ck CHECK (plan_id IN ('starter', 'pro', 'business', 'enterprise')),
    CONSTRAINT billing_order_method_ck CHECK (payment_method IN ('card', 'pix')),
    CONSTRAINT billing_order_status_ck CHECK (status IN ('pending', 'checkout_open', 'paid', 'provisioned', 'provisioning_failed', 'expired'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE subscription (
    id bigint unsigned NOT NULL AUTO_INCREMENT,
    billing_order_id bigint unsigned NOT NULL,
    tenant_id bigint unsigned NULL,
    plan_id varchar(20) NOT NULL,
    status varchar(20) NOT NULL,
    payment_method varchar(10) NOT NULL,
    stripe_customer_id varchar(64) NOT NULL,
    stripe_subscription_id varchar(64) NULL,
    current_period_start timestamp(6) NULL,
    current_period_end timestamp(6) NULL,
    grace_until timestamp(6) NULL,
    cancelled_at timestamp(6) NULL,
    last_event_created bigint unsigned NOT NULL DEFAULT 0,
    created_at timestamp(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    updated_at timestamp(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    PRIMARY KEY (id),
    UNIQUE KEY subscription_billing_order_uq (billing_order_id),
    UNIQUE KEY subscription_tenant_uq (tenant_id),
    UNIQUE KEY subscription_stripe_uq (stripe_subscription_id),
    KEY subscription_grace_idx (status, grace_until),
    KEY subscription_pix_due_idx (payment_method, status, current_period_end),
    CONSTRAINT subscription_billing_order_fk FOREIGN KEY (billing_order_id) REFERENCES billing_order (id)
        ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT subscription_tenant_fk FOREIGN KEY (tenant_id) REFERENCES tenant (id)
        ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT subscription_plan_ck CHECK (plan_id IN ('starter', 'pro', 'business', 'enterprise')),
    CONSTRAINT subscription_status_ck CHECK (status IN ('incomplete', 'active', 'past_due', 'suspended', 'cancelled')),
    CONSTRAINT subscription_method_ck CHECK (payment_method IN ('card', 'pix'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE subscription_invoice (
    id bigint unsigned NOT NULL AUTO_INCREMENT,
    subscription_id bigint unsigned NOT NULL,
    stripe_invoice_id varchar(64) NULL,
    local_key varchar(80) NULL,
    stripe_payment_intent_id varchar(64) NULL,
    due_at timestamp(6) NULL,
    pix_expires_at timestamp(6) NULL,
    payment_intent_attempts int unsigned NOT NULL DEFAULT 0,
    status varchar(20) NOT NULL,
    amount_due_cents int unsigned NOT NULL,
    amount_paid_cents int unsigned NOT NULL DEFAULT 0,
    amount_refunded_cents int unsigned NOT NULL DEFAULT 0,
    currency char(3) NOT NULL DEFAULT 'brl',
    period_start timestamp(6) NULL,
    period_end timestamp(6) NULL,
    hosted_invoice_url varchar(500) NULL,
    last_event_created bigint unsigned NOT NULL DEFAULT 0,
    created_at timestamp(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    updated_at timestamp(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    PRIMARY KEY (id),
    UNIQUE KEY subscription_invoice_stripe_uq (stripe_invoice_id),
    UNIQUE KEY subscription_invoice_local_key_uq (local_key),
    UNIQUE KEY subscription_invoice_pi_uq (stripe_payment_intent_id),
    KEY subscription_invoice_subscription_idx (subscription_id),
    KEY subscription_invoice_open_idx (status, due_at),
    CONSTRAINT subscription_invoice_subscription_fk FOREIGN KEY (subscription_id) REFERENCES subscription (id)
        ON UPDATE RESTRICT ON DELETE RESTRICT,
    CONSTRAINT subscription_invoice_status_ck CHECK (status IN ('draft', 'open', 'paid', 'uncollectible', 'void', 'refunded')),
    CONSTRAINT subscription_invoice_ref_ck CHECK (stripe_invoice_id IS NOT NULL OR local_key IS NOT NULL)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE billing_event (
    id bigint unsigned NOT NULL AUTO_INCREMENT,
    provider varchar(20) NOT NULL DEFAULT 'stripe',
    event_id varchar(100) NOT NULL,
    event_type varchar(80) NOT NULL,
    event_created bigint unsigned NOT NULL,
    livemode tinyint(1) NOT NULL,
    payload mediumtext NOT NULL,
    status varchar(12) NOT NULL,
    attempts int unsigned NOT NULL DEFAULT 0,
    last_error varchar(190) NULL,
    received_at timestamp(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    processed_at timestamp(6) NULL,
    PRIMARY KEY (id),
    UNIQUE KEY billing_event_provider_event_uq (provider, event_id),
    KEY billing_event_status_idx (status),
    KEY billing_event_created_idx (event_created),
    CONSTRAINT billing_event_status_ck CHECK (status IN ('received', 'processed', 'ignored', 'failed'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

INSERT INTO schema_migrations (version, checksum)
VALUES ('20261021_0017_etapa3_billing',
        '0000000000000000000000000000000000000000000000000000000000000000' /* 64-char PLACEHOLDER: replace before applying */);
