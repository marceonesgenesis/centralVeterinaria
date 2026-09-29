# ADR 0002: Contexto multi-tenant fail-closed

- Status: aceito
- Data: 2026-09-20

## Contexto

O PRD exige isolamento entre organizações e proíbe confiar em `tenant_id`
recebido do cliente. `system_unit` representa uma filial, não a organização.

## Decisão

`TenantContext` é criado exclusivamente de uma sessão autenticada contendo
`tenantid` e `userid`; a unidade ativa é opcional até um caso de uso exigi-la.
Ausência ou valor inválido interrompe a operação. Repositórios tenant-aware
nascem com o contexto injetado e toda query começa por `tenant_id =
:tenant_scope_id`. Um filtro comum não pode substituir esse predicado.

## Consequências

Após aplicar o schema, a autenticação deverá resolver os vínculos do usuário e
gravar `tenantid` na sessão. Até isso acontecer, código novo protegido falha
fechado. Administração cross-tenant exigirá um serviço explícito separado; não
há bypass implícito para `PLATFORM_ADMIN`.
