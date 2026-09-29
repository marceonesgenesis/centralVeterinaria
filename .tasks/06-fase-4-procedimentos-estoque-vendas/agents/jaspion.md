# Jaspion — catálogo de procedimento e execução

subagent_type: general-purpose
model: herdado

## Contexto
Construir o catálogo de procedimentos com sua lista de insumos (BOM) e, na onda seguinte, a execução do procedimento dentro de um atendimento — a peça que consome estoque automaticamente e é a razão de ser desta fase junto ao EncounterView.

## Tasks atribuídas
- T-04: catálogo de procedimento e insumos — Domain, Repository e Application service.
- T-05: execução de procedimento — Domain, Repository e Application service.

Leia a especificação completa de cada task (arquivos, Interface, critério de aceite, validação) na seção correspondente de `/var/www/html/centralvet/.tasks/06-fase-4-procedimentos-estoque-vendas/tasks.md`. Não leia as demais seções.

## Caminhos relevantes
- `src/app/Core/Application/VaccineProtocolService.php` (Fase 3 — padrão de service filho de um catálogo, sem update, mesma forma que `ProcedureCatalogItemInput`/insumos deve seguir)
- `src/app/Core/Application/VaccinationService.php` (Fase 3 — padrão de service que carrega a unidade REAL de um recurso relacionado, aqui via `EncounterRepositoryInterface`, antes de autorizar)
- `src/app/Core/Application/EncounterService.php` (padrão de acesso a `Encounter` para extrair `system_unit_id` real)
- T-03 (Athena, mesma onda anterior) produz `StockService::consume(...)` — ler a assinatura exata em `tasks.md` antes de chamar

## Restrições
- Não editar arquivos fora dos listados nas tasks atribuídas.
- T-05 só pode começar depois que T-03 e T-04 estiverem `[x]` em `tasks.md` — confirme antes de escrever código que chama `StockService`.
- Respeitar literalmente os tokens de "Consome": não redefinir contratos.
- Não executar comandos destrutivos sem confirmação.
- Não devolver conteúdo integral de arquivos.

## Retorno (até 200 palavras, exatamente neste formato)
Status: concluído | bloqueado | parcial
Arquivos: <caminhos tocados>
Evidência: <comando → trecho de saída que prova o critério de aceite>
Pendências: <o que falta ou "nenhuma">
