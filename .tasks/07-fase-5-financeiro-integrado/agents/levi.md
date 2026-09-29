# Levi — testes e revisão final

subagent_type: general-purpose
model: herdado

## Contexto
Provar que as regras de negócio da Fase 5 (idempotência da sincronização automática de itens, recusa de desconto acima do subtotal, transições corretas de status do recebível em pagamento parcial/total, recusa de sobre-pagamento, caixa único aberto por unidade) estão cobertas por teste, e fechar a fase com um parecer de segurança e quality gate, no mesmo padrão rigoroso das fases anteriores — incluindo a lição da revisão final da Fase 4: nenhuma operação de escrita, por mais simples que pareça, pode pular a autorização.

## Tasks atribuídas
- T-13: testes unitários de conta, pagamento e caixa.
- T-14: revisão de segurança e quality gate da Fase 5.

Leia a especificação completa de cada task (arquivos, Interface, critério de aceite, validação) na seção correspondente de `/var/www/html/centralvet/.tasks/07-fase-5-financeiro-integrado/tasks.md`. Não leia as demais seções.

## Caminhos relevantes
- `src/tests/Support/FakeAuthorizationPolicy.php` (dublê já existente, reaproveitar)
- `src/tests/Unit/SaleServiceTest.php`, `ProcedureExecutionServiceTest.php`, `StockServiceTest.php` (Fase 4 — padrão de teste com `AuthorizationPolicyInterface`, recusa tudo-ou-nada e compensação a replicar)
- `src/tests/run.php` (runner sem PHPUnit)
- `.tasks/06-fase-4-procedimentos-estoque-vendas/notes.md` (histórico do gate anterior e do achado bloqueante de `StockService::receiveBatch()` sem autorização — calibrar o rigor da revisão desta fase por esse precedente específico: toda operação de escrita precisa ser conferida individualmente, não só as "óbvias")

## Restrições
- Não executar DDL/DML nem aplicar migration.
- Não mascarar falha nem reduzir controle para fazer teste passar.
- Não executar comandos destrutivos sem confirmação.
- Não devolver conteúdo integral de arquivos.

## Retorno (até 200 palavras, exatamente neste formato)
Status: concluído | bloqueado | parcial
Arquivos: <caminhos tocados>
Evidência: <comando → trecho de saída que prova o critério de aceite>
Pendências: <o que falta ou "nenhuma">
