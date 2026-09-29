# Athena — arquitetura e persistência

subagent_type: general-purpose
model: herdado

## Contexto
Estender a fundação da Fase 0/1 com o schema e o Core do agregado Encounter — a base de dados e de domínio sobre a qual a tela única de atendimento (T-06) vai ser construída.

## Tasks atribuídas
- T-01: preparar migration de `encounter` (não aplicada).
- T-02: contratos de Encounter e do assistente de IA.
- T-03: Encounter — Domain, Repository e Application service.

Leia a especificação completa de cada task (arquivos, Interface, critério de aceite, validação) na seção correspondente de `/var/www/html/centralvet/.tasks/04-fase-2-nucleo-clinico/tasks.md`. Não leia as demais seções.

## Caminhos relevantes
- `src/app/database/migrations/20260921_0002_phase1_clinic_core.sql` e `README.md` (convenção de migration)
- `src/app/Core/Persistence/`, `src/app/Core/Tenancy/`, `src/app/Core/Domain/Contract/` (Fase 0, padrão a seguir)
- `src/app/Core/Application/AppointmentService.php`, `QueueEntryService.php` (Fase 1 — padrão de integração com `AuthorizationPolicyInterface`, a replicar em `EncounterService`)
- `src/app/Core/Audit/` (ADR 0003 — `audit_log`, usado por `timeline()`)

## Restrições
- Não editar arquivos fora dos listados nas tasks atribuídas.
- Respeitar literalmente os tokens de "Consome": não redefinir contratos.
- Services e Repositories não podem depender de `TPage` (ADR 0001).
- Toda query nasce com `TenantQuery::forTenant(...)` (ADR 0002); tenant nunca é confiado por parâmetro do cliente.
- `EncounterService::start()`/`finish()` precisam integrar `AuthorizationPolicyInterface` desde já (não deixar para depois, como aconteceu na Fase 1).
- Não executar DDL/DML nem aplicar a migration de T-01 — apenas prepará-la.
- Não executar comandos destrutivos sem confirmação.
- Não devolver conteúdo integral de arquivos.

## Retorno (até 200 palavras, exatamente neste formato)
Status: concluído | bloqueado | parcial
Arquivos: <caminhos tocados>
Evidência: <comando → trecho de saída que prova o critério de aceite>
Pendências: <o que falta ou "nenhuma">
