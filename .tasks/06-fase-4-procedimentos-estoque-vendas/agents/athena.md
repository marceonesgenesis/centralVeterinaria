# Athena — fundação de dados, produto e estoque

subagent_type: general-purpose
model: herdado

## Contexto
Preparar a base de dados da Fase 4 (8 tabelas), os 8 contratos de Domain que todo o resto da fase consome, e depois construir o núcleo de produto/estoque (consumo por lote na ordem de validade) e o núcleo de venda/PDV — os dois serviços que mais dependem de acertar a regra de negócio para o resto da fase funcionar.

## Tasks atribuídas
- T-01: preparar migration de produto/estoque/procedimento/venda (8 tabelas).
- T-02: criar os 8 contratos de Domain.
- T-03: produto e estoque — Domain, Repository e Application service.
- T-06: venda/PDV — Domain, Repository e Application service.

Leia a especificação completa de cada task (arquivos, Interface, critério de aceite, validação) na seção correspondente de `/var/www/html/centralvet/.tasks/06-fase-4-procedimentos-estoque-vendas/tasks.md`. Não leia as demais seções.

## Caminhos relevantes
- `src/app/database/migrations/20260922_0004_phase3_prescription_exam_vaccine.sql` e `.verify.sql` (Fase 3 — padrão exato de migration a replicar: cabeçalho de efeito/risco, placeholder de checksum de 64 hex chars, `CHECK` constraints, `schema_migrations`)
- `src/app/Core/Domain/Contract/PrescriptionRepositoryInterface.php` (Fase 3 — padrão de contrato estendendo `TenantRepositoryInterface`)
- `src/app/Core/Application/VaccinationService.php` (Fase 3 — padrão de Application service com `AuthorizationPolicyInterface::decide(...)->assertAllowed()` antes de mutação e decremento de contador de estoque, ponto de partida conceitual para `StockService::consume`)
- `src/app/Core/Persistence/AbstractTenantRepository.php`, `TenantQuery.php` (base de toda Persistence)
- `src/app/Core/Domain/Exception/` (pasta de exceções de domínio existentes, para seguir o mesmo padrão ao criar `InsufficientStockException`)

## Restrições
- Não editar arquivos fora dos listados nas tasks atribuídas.
- Não executar nenhum DDL/DML contra o MySQL — a migration é só preparada (arquivo `.sql`), nunca aplicada pelo agente.
- Respeitar literalmente os tokens de "Consome": não redefinir contratos.
- Não executar comandos destrutivos sem confirmação.
- Não devolver conteúdo integral de arquivos.

## Retorno (até 200 palavras, exatamente neste formato)
Status: concluído | bloqueado | parcial
Arquivos: <caminhos tocados>
Evidência: <comando → trecho de saída que prova o critério de aceite>
Pendências: <o que falta ou "nenhuma">
