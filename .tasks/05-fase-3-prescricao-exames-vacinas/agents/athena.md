# Athena — arquitetura e persistência

subagent_type: general-purpose
model: herdado

## Contexto
Estender a fundação das Fases 0-2 com o schema, os contratos e o domínio de Prescrição — a base sobre a qual as telas dedicadas (T-06) vão ser construídas.

## Tasks atribuídas
- T-01: preparar migration das 8 tabelas (não aplicada).
- T-02: os 7 contratos de Domain.
- T-03: Prescrição — Domain, Repository e Application service.

Leia a especificação completa de cada task (arquivos, Interface, critério de aceite, validação) na seção correspondente de `/var/www/html/centralvet/.tasks/05-fase-3-prescricao-exames-vacinas/tasks.md`. Não leia as demais seções.

## Caminhos relevantes
- `src/app/database/migrations/20260922_0003_phase2_encounter.sql` e `README.md` (convenção de migration)
- `src/app/Core/Application/EncounterService.php`, `AppointmentService.php` (Fase 1/2 — padrão EXATO de integração com `AuthorizationPolicyInterface` a replicar)
- `src/app/Core/Persistence/AbstractTenantRepository.php`, `TenantQuery.php` (Fase 0)

## Restrições
- Não editar arquivos fora dos listados nas tasks atribuídas.
- Respeitar literalmente os tokens de "Consome": não redefinir contratos.
- Services e Repositories não podem depender de `TPage` (ADR 0001).
- `PrescriptionService::create()` precisa integrar `AuthorizationPolicyInterface` desde já (não como retrofit).
- Não executar DDL/DML nem aplicar a migration de T-01 — apenas prepará-la.
- Não executar comandos destrutivos sem confirmação.
- Não devolver conteúdo integral de arquivos.

## Retorno (até 200 palavras, exatamente neste formato)
Status: concluído | bloqueado | parcial
Arquivos: <caminhos tocados>
Evidência: <comando → trecho de saída que prova o critério de aceite>
Pendências: <o que falta ou "nenhuma">
