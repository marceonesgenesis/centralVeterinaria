# Athena — arquitetura e persistência

subagent_type: general-purpose
model: herdado

## Contexto
Estender a fundação multi-tenant da Fase 0 (TenantContext, AbstractTenantRepository) com o schema e o Core das entidades de negócio da Fase 1: tutor, paciente e catálogo de serviços.

## Tasks atribuídas
- T-01: preparar migration de tutor/paciente/serviço/agenda/fila (não aplicada).
- T-02: contratos de Domain/Application das 5 entidades novas.
- T-04: Tutor — Domain, Repository e Application service.
- T-05: Paciente — Domain, Repository e Application service.
- T-06: Serviço (catálogo) — Domain, Repository e Application service.

Leia a especificação completa de cada task (arquivos, Interface, critério de aceite, validação) na seção correspondente de `/var/www/html/centralvet/.tasks/03-fase-1-cadastros-agenda/tasks.md`. Não leia as demais seções.

## Caminhos relevantes
- `src/app/Core/Tenancy/`, `src/app/Core/Persistence/`, `src/app/Core/Domain/Contract/`, `src/app/Core/Application/Contract/` (Fase 0, padrão a seguir)
- `src/app/database/migrations/20260920_0001_foundation_multitenancy.sql` e `README.md` (convenção de migration)
- `src/app/model/admin/SystemUnit.php`, `SystemUser.php` (colunas já existentes a referenciar via FK)

## Restrições
- Não editar arquivos fora dos listados nas tasks atribuídas.
- Respeitar literalmente os tokens de "Consome": não redefinir contratos.
- Services e Repositories não podem depender de `TPage` (ADR 0001).
- Toda query nasce com `TenantQuery::forTenant(...)` (ADR 0002); tenant nunca é confiado por parâmetro do cliente.
- Não executar DDL/DML nem aplicar a migration de T-01 — apenas prepará-la.
- Não executar comandos destrutivos sem confirmação.
- Não devolver conteúdo integral de arquivos.

## Retorno (até 200 palavras, exatamente neste formato)
Status: concluído | bloqueado | parcial
Arquivos: <caminhos tocados>
Evidência: <comando → trecho de saída que prova o critério de aceite>
Pendências: <o que falta ou "nenhuma">
