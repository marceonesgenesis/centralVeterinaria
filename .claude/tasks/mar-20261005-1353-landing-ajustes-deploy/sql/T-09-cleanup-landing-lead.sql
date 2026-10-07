-- T-09 — Limpeza dos leads de teste em landing_lead (banco centralvet)
-- Estado lido em 2026-10-05 (só SELECT):
--   landing_lead: COUNT(*)=3; colunas id, name, clinic_name, email, phone, vets_range, city, uf,
--                 plan_id, plan_name, plan_price_cents, consent_version, consent_at, consent_ip,
--                 created_at
--   linhas: 1 'R3 landing Ana', 2 'LP teste Ana', 3 'LP teste Replay' (todas de teste)
--   Os gates desta rodada acrescentam leads 'LP teste ...'; por isso o predicado é por nome e
--   data, não por id.
-- Execução exige aprovação explícita do usuário (skill sql-write-approval), só pelo
-- orquestrador, depois da Onda 3 e com backup (./scripts/backup.sh + gzip -t) antes.
-- Só DELETE e SELECT.
-- Guarda: anote N = número de linhas do SELECT "antes" (em 2026-10-05: 3; mais os 'LP teste'
-- dos gates). Se o DELETE informar outro número de linhas afetadas, ou se o SELECT listar
-- alguma linha que não seja de teste, PARE, rode ROLLBACK (nunca COMMIT) e reveja o predicado.

START TRANSACTION;

-- antes
SELECT COUNT(*) AS total_antes FROM landing_lead;                 -- esperado em 2026-10-05: 3
SELECT id, name, clinic_name, email, created_at
  FROM landing_lead
 WHERE (name LIKE 'LP teste%' OR name = 'R3 landing Ana')
   AND created_at >= '2026-10-01'
 ORDER BY id;                                                     -- esperado: N (3 em 2026-10-05)

DELETE FROM landing_lead WHERE (name LIKE 'LP teste%' OR name = 'R3 landing Ana') AND created_at >= '2026-10-01';
-- esperado: N linhas afetadas; se diferente, ROLLBACK

-- depois
SELECT id, name, clinic_name, email, created_at
  FROM landing_lead
 WHERE (name LIKE 'LP teste%' OR name = 'R3 landing Ana')
   AND created_at >= '2026-10-01'
 ORDER BY id;                                                     -- esperado: 0 linhas
SELECT COUNT(*) AS total_depois FROM landing_lead;                -- esperado: total_antes - N

COMMIT;

-- ---------------------------------------------------------------------------------------------
-- Resíduos Redis (só comentários; nada a executar aqui, nenhuma remoção de chave)
--   centralvet:session:deadbeef — já expirou (EXISTS = 0 em 2026-10-05).
--   centralvet:lead-throttle:<sha256('lead|<ip>')> — contadores com TTL <= 3600 s; nenhum
--     encontrado em 2026-10-05.
--   centralvet:lead-token:* — tokens com TTL <= 7200 s; 8 chaves em 2026-10-05, expiram sozinhas.
-- Conferência só leitura:
--   docker compose exec -T redis redis-cli --scan --pattern 'centralvet:lead-throttle:*'
--   docker compose exec -T redis redis-cli --scan --pattern 'centralvet:lead-token:*'
--   docker compose exec -T redis redis-cli TTL <chave>
--   docker compose exec -T redis redis-cli EXISTS centralvet:session:deadbeef
