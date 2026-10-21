# Board — mar-20261021-1145-etapa-2-dinheiro-estoque

Log append-only de fatos que afetam outras tasks desta execução: contrato divergente, símbolo renomeado, arquivo compartilhado alterado, decisão que outra task precisa conhecer. Uma linha por fato, acrescentada por append com heredoc (abaixo; o delimitador entre aspas aceita qualquer caractere no fato); nunca edite ou remova linhas. Leia antes de começar uma task e antes de usar cada `Consome`. O fechador consolida as linhas em `notes.md § Descobertas`.

Formato: `- [T-NN] <fato>`

Append:

```bash
cat >> "/var/www/html/centralvet/.claude/tasks/mar-20261021-1145-etapa-2-dinheiro-estoque/board.md" <<'EOF'
- [T-NN] <fato>
EOF
```
- [T-01] UserMessage::STATIC agora tem 89 entradas; UserMessageTest::testCatalogHasExactlyTheContractEntries fixa count 89 (quem adicionar mensagens de domínio depois precisa ajustar). Chaves da Interface de T-01 disponíveis em translations.json (commit 7c198b6).
- [T-02] 0016 aplicada no centralvet_test (commit 28f0fd2): receivable.sale_id + receivable_origin_ck, encounter_account_id NULL; sale.cancelled_at/cancelled_by_system_user_id/cancellation_reason + sale_cancellation_ck; tabelas payment_reversal e submission_key; stock_movement_reason_ck aceita 'sale_cancellation'. Índices extras por FK: sale_cancelled_by_idx, payment_reversal_{tenant,cash_session,system_user}_idx, submission_key_system_user_idx.
- [T-04] `ApplicationAuthenticationService::resolveDispatchTarget` removido; a regra do class_alias (nome curto byte a byte) vive em `MutationRequestPolicy::resolveCanonical` e o guard chama `decideFromRequest($_SERVER, $_REQUEST, $token)`. Telas novas não precisam passar resolvedor.
- [T-03] `SearchParameterGuard` nega `orderColumn` fora de `key`/itens de `column` (`orderColumn is not a searched column`): TDBUniqueSearch/TDBMultiSearch novos (T-16) devem ordenar pela coluna exibida ou omitir orderColumn.
- [T-03] Suíte completa: `StockSalesOverviewIntegrationTest` falha com 3819 `sale_cancellation_ck` no centralvet_test (fixture insere `sale` sem os campos do CHECK da 0016) — dono provável T-02/T-09.
- [cruzada-onda-1] Fixture createSale do StockSalesOverviewIntegrationTest preenche colunas de cancelamento (sale_cancellation_ck); SUITE 1880/1880 (commit 69e1122). ExamResultAiIntegrationTest teve FK 1451/1452 intermitente numa execução (provável concorrência no centralvet_test).
