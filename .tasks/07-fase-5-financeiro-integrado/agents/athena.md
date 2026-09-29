# Athena — fundação de dados, conta do atendimento e pagamento

subagent_type: general-purpose
model: herdado

## Contexto
Preparar a base de dados da Fase 5 (7 tabelas), os 7 contratos de Domain que todo o resto da fase consome, o núcleo de conta do atendimento/recebível (a peça central desta fase), e depois o serviço de pagamento — que amarra conta, caixa e lançamento financeiro juntos.

## Tasks atribuídas
- T-01: preparar migration de conta/recebível/caixa/pagamento/pagar/lançamento (7 tabelas).
- T-02: criar os 7 contratos de Domain.
- T-03: conta do atendimento e recebível — Domain, Repository e Application service.
- T-06: pagamento — Domain, Repository e Application service.

Leia a especificação completa de cada task (arquivos, Interface, critério de aceite, validação) na seção correspondente de `/var/www/html/centralvet/.tasks/07-fase-5-financeiro-integrado/tasks.md`. Não leia as demais seções.

## Caminhos relevantes
- `src/app/database/migrations/20260924_0005_phase4_procedure_stock_sale.sql` e `.verify.sql` (Fase 4 — padrão exato de migration a replicar)
- `src/app/Core/Application/SaleService.php` (Fase 4 — padrão de service que valida tudo antes de gravar qualquer coisa e desfaz gravações parciais em caso de falha; `PaymentService`/`EncounterAccountService` seguem a mesma disciplina)
- `src/app/Core/Application/ProcedureExecutionService.php` (Fase 4 — padrão de service que lê um agregado relacionado (`Encounter`) só para extrair a unidade real antes de autorizar)
- `src/app/Core/Domain/Exception/` (pasta de exceções de domínio existentes, para seguir o mesmo padrão ao criar `DiscountExceedsSubtotalException`/`OverpaymentException`)
- T-04/T-05 (Jaspion, mesma onda 3) produzem `CashSessionRepositoryInterface`/`FinancialEntryService` que T-06 consome — leia a assinatura real desses arquivos antes de escrever T-06, não confie só no texto de `tasks.md`

## Restrições
- Não editar arquivos fora dos listados nas tasks atribuídas.
- Não executar nenhum DDL/DML contra o MySQL — a migration é só preparada (arquivo `.sql`), nunca aplicada pelo agente.
- T-06 só pode começar depois que T-03, T-04 e T-05 estiverem `[x]` em `tasks.md` — confirme antes de escrever código que chama `CashSessionRepositoryInterface`/`FinancialEntryService`.
- Respeitar literalmente os tokens de "Consome": não redefinir contratos.
- Não executar comandos destrutivos sem confirmação.
- Não devolver conteúdo integral de arquivos.

## Retorno (até 200 palavras, exatamente neste formato)
Status: concluído | bloqueado | parcial
Arquivos: <caminhos tocados>
Evidência: <comando → trecho de saída que prova o critério de aceite>
Pendências: <o que falta ou "nenhuma">
