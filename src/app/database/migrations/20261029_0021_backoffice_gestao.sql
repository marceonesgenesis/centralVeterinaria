-- Central Vet Pro - Área de Gestão da Plataforma (Back-office)
-- Migration: 20261029_0021_backoffice_gestao
-- Target: MySQL 8.0.x, database centralvet.
--
-- Effects:
--   * Criação de tabelas para a área de gestão da plataforma Central Vet:
--     - backoffice_user_profile (perfis fixos: admin, commercial, financial, support)
--     - backoffice_plan_offering (gestão dinâmica de planos e sincronização Stripe)
--     - backoffice_invite (convites com bônus configurável, padrão 15 dias)
--     - backoffice_support_schedule (horários semanais, feriados e canais de suporte)
--     - backoffice_marketing_campaign e backoffice_marketing_optout (leads, campanhas e LGPD)
--     - backoffice_audit_log (trilha indelével com operador, IP e diff JSON)

CREATE TABLE backoffice_user_profile (
    id bigint unsigned NOT NULL AUTO_INCREMENT,
    user_id int NOT NULL,
    profile_role varchar(20) NOT NULL,
    is_active tinyint(1) NOT NULL DEFAULT 1,
    created_at timestamp(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    updated_at timestamp(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    PRIMARY KEY (id),
    UNIQUE KEY backoffice_user_profile_user_uq (user_id),
    CONSTRAINT backoffice_profile_role_ck CHECK (profile_role IN ('admin', 'commercial', 'financial', 'support')),
    CONSTRAINT backoffice_user_fk FOREIGN KEY (user_id) REFERENCES system_users (id)
        ON UPDATE RESTRICT ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE backoffice_plan_offering (
    id bigint unsigned NOT NULL AUTO_INCREMENT,
    plan_id varchar(20) NOT NULL,
    name varchar(80) NOT NULL,
    price_cents int unsigned NOT NULL,
    stripe_price_id varchar(64) NULL,
    max_units int unsigned NOT NULL DEFAULT 1,
    max_vets int unsigned NOT NULL DEFAULT 2,
    has_hospitalization tinyint(1) NOT NULL DEFAULT 0,
    has_surgery tinyint(1) NOT NULL DEFAULT 0,
    has_documents tinyint(1) NOT NULL DEFAULT 1,
    has_communication tinyint(1) NOT NULL DEFAULT 1,
    has_ai tinyint(1) NOT NULL DEFAULT 0,
    monthly_token_quota bigint unsigned NOT NULL DEFAULT 0,
    is_active tinyint(1) NOT NULL DEFAULT 1,
    display_order int unsigned NOT NULL DEFAULT 1,
    created_at timestamp(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    updated_at timestamp(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    PRIMARY KEY (id),
    UNIQUE KEY backoffice_plan_id_uq (plan_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE backoffice_invite (
    id bigint unsigned NOT NULL AUTO_INCREMENT,
    public_token char(36) NOT NULL,
    email varchar(255) NOT NULL,
    clinic_name varchar(255) NOT NULL,
    plan_id varchar(20) NOT NULL DEFAULT 'pro',
    trial_days int unsigned NOT NULL DEFAULT 15,
    expires_at timestamp(6) NOT NULL,
    status varchar(20) NOT NULL DEFAULT 'pending',
    tenant_id bigint unsigned NULL,
    accepted_at timestamp(6) NULL,
    created_by_user_id int NULL,
    created_at timestamp(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    updated_at timestamp(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    PRIMARY KEY (id),
    UNIQUE KEY backoffice_invite_token_uq (public_token),
    KEY backoffice_invite_email_idx (email),
    KEY backoffice_invite_status_idx (status),
    CONSTRAINT backoffice_invite_status_ck CHECK (status IN ('pending', 'accepted', 'in_trial', 'converted', 'expired', 'cancelled')),
    CONSTRAINT backoffice_invite_tenant_fk FOREIGN KEY (tenant_id) REFERENCES tenant (id)
        ON UPDATE RESTRICT ON DELETE SET NULL,
    CONSTRAINT backoffice_invite_user_fk FOREIGN KEY (created_by_user_id) REFERENCES system_users (id)
        ON UPDATE RESTRICT ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE backoffice_support_schedule (
    id bigint unsigned NOT NULL AUTO_INCREMENT,
    day_of_week tinyint unsigned NOT NULL, -- 0 = Domingo, 1 = Segunda, ..., 6 = Sábado
    day_name varchar(20) NOT NULL,
    is_open tinyint(1) NOT NULL DEFAULT 1,
    open_time time NULL,
    close_time time NULL,
    is_on_call tinyint(1) NOT NULL DEFAULT 0, -- plantão
    notes varchar(255) NULL,
    updated_at timestamp(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    PRIMARY KEY (id),
    UNIQUE KEY backoffice_support_day_uq (day_of_week)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE backoffice_support_config (
    id bigint unsigned NOT NULL AUTO_INCREMENT,
    support_email varchar(255) NOT NULL DEFAULT 'suporte@centralvet.com.br',
    support_whatsapp varchar(50) NOT NULL DEFAULT '+55 (11) 98765-4321',
    out_of_hours_message text NOT NULL,
    updated_at timestamp(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE backoffice_marketing_optout (
    id bigint unsigned NOT NULL AUTO_INCREMENT,
    email varchar(255) NOT NULL,
    reason varchar(255) NULL,
    created_at timestamp(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    PRIMARY KEY (id),
    UNIQUE KEY backoffice_marketing_optout_email_uq (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE backoffice_marketing_campaign (
    id bigint unsigned NOT NULL AUTO_INCREMENT,
    title varchar(150) NOT NULL,
    subject varchar(255) NOT NULL,
    target_audience varchar(50) NOT NULL, -- 'leads_all', 'abandoned_checkout', 'trial_ending'
    content_html text NOT NULL,
    status varchar(20) NOT NULL DEFAULT 'draft',
    total_recipients int unsigned NOT NULL DEFAULT 0,
    total_delivered int unsigned NOT NULL DEFAULT 0,
    sent_at timestamp(6) NULL,
    created_by_user_id int NULL,
    created_at timestamp(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    updated_at timestamp(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    PRIMARY KEY (id),
    CONSTRAINT backoffice_campaign_status_ck CHECK (status IN ('draft', 'scheduled', 'sending', 'sent', 'cancelled')),
    CONSTRAINT backoffice_campaign_user_fk FOREIGN KEY (created_by_user_id) REFERENCES system_users (id)
        ON UPDATE RESTRICT ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE backoffice_audit_log (
    id bigint unsigned NOT NULL AUTO_INCREMENT,
    user_id int NULL,
    user_email varchar(255) NOT NULL,
    user_role varchar(20) NOT NULL,
    ip_address varchar(45) NOT NULL,
    action varchar(100) NOT NULL,
    target_type varchar(50) NOT NULL,
    target_id varchar(100) NOT NULL,
    details_json json NULL,
    created_at timestamp(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    PRIMARY KEY (id),
    KEY backoffice_audit_user_idx (user_id),
    KEY backoffice_audit_target_idx (target_type, target_id),
    KEY backoffice_audit_action_idx (action),
    KEY backoffice_audit_date_idx (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- Seeds iniciais para os 4 Planos do MVP
INSERT INTO backoffice_plan_offering (plan_id, name, price_cents, stripe_price_id, max_units, max_vets, has_hospitalization, has_surgery, has_documents, has_communication, has_ai, monthly_token_quota, is_active, display_order)
VALUES
    ('starter', 'Starter', 14900, 'price_1P_starter_mvp', 1, 2, 0, 0, 1, 1, 0, 0, 1, 1),
    ('pro', 'Pro', 29900, 'price_1P_pro_mvp', 2, 5, 1, 1, 1, 1, 0, 0, 1, 2),
    ('business', 'Business', 49900, 'price_1P_biz_mvp', 5, 20, 1, 1, 1, 1, 1, 100000, 1, 3),
    ('enterprise', 'Enterprise', 89900, 'price_1P_corp_mvp', 999, 999, 1, 1, 1, 1, 1, 1000000, 1, 4);

-- Seeds de horários de suporte (Segunda a Sexta 8h às 18h, Sábado plantão, Domingo fechado)
INSERT INTO backoffice_support_schedule (day_of_week, day_name, is_open, open_time, close_time, is_on_call, notes)
VALUES
    (0, 'Domingo', 0, NULL, NULL, 0, 'Fechado'),
    (1, 'Segunda-feira', 1, '08:00:00', '18:00:00', 0, 'Atendimento normal'),
    (2, 'Terça-feira', 1, '08:00:00', '18:00:00', 0, 'Atendimento normal'),
    (3, 'Quarta-feira', 1, '08:00:00', '18:00:00', 0, 'Atendimento normal'),
    (4, 'Quinta-feira', 1, '08:00:00', '18:00:00', 0, 'Atendimento normal'),
    (5, 'Sexta-feira', 1, '08:00:00', '18:00:00', 0, 'Atendimento normal'),
    (6, 'Sábado', 1, '08:00:00', '12:00:00', 1, 'Plantão para chamados urgentes');

INSERT INTO backoffice_support_config (support_email, support_whatsapp, out_of_hours_message)
VALUES ('suporte@centralvet.com.br', '+55 (11) 98765-4321', 'Nosso horário de atendimento é de segunda a sexta, das 8h às 18h. Seu chamado foi registrado e será atendido no próximo dia útil.');

INSERT INTO schema_migrations (version, checksum)
VALUES ('20261029_0021_backoffice_gestao',
        '0000000000000000000000000000000000000000000000000000000000000000' /* 64-char PLACEHOLDER: replace before applying */);

