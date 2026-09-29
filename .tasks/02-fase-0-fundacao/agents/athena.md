# Athena — arquitetura e multi-tenancy (subagent_type: general-purpose)

## Contexto
Construir a espinha dorsal modular e multi-tenant que todas as fases seguintes usarão.

## Tasks atribuídas
- T-02: ADRs, Core, PSR-4 e contratos.
- T-03: migrations de fundação, somente preparação até autorização SQL.
- T-04: TenantContext e isolamento fail-closed.

## Restrições
- Services e Repositories não podem depender de `TPage`.
- Tenant nunca é confiado a partir de parâmetro livre do cliente.
- Não executar DDL/DML sem autorização SQL específica.
- Preservar Adianti e compatibilidade com o bootstrap existente.
- Operar em contexto enxuto; retorno em aproximadamente 200 palavras.

## Entregáveis esperados
- Fundação modular, migrations auditáveis e testes do isolamento básico.

## Skills disponíveis
- `plan-exec`
- `sql-write-approval`

