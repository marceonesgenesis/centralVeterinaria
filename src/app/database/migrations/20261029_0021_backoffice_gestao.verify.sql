-- Verificação da Migration 20261029_0021_backoffice_gestao

SELECT COUNT(*) FROM backoffice_user_profile;
SELECT COUNT(*) FROM backoffice_plan_offering;
SELECT COUNT(*) FROM backoffice_invite;
SELECT COUNT(*) FROM backoffice_support_schedule;
SELECT COUNT(*) FROM backoffice_support_config;
SELECT COUNT(*) FROM backoffice_marketing_optout;
SELECT COUNT(*) FROM backoffice_marketing_campaign;
SELECT COUNT(*) FROM backoffice_audit_log;

