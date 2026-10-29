# Correção cruzada — Onda 1

Status: concluído
Commit: 69e1122 (Task: T-02)
Arquivo: src/tests/Integration/StockSalesOverviewIntegrationTest.php

## Problema
SUITE com 8 FAIL em StockSalesOverviewIntegrationTest: `SQLSTATE[HY000]: 3819 Check constraint 'sale_cancellation_ck' is violated`. O helper `createSale(..., 'cancelled')` não preenchia `cancelled_at`, `cancelled_by_system_user_id` e `cancellation_reason`, exigidos pela 0016.

## Correção
Só o fixture: quando `status === 'cancelled'`, grava `cancelled_at = sold_at`, `cancelled_by_system_user_id = $this->userId` e `cancellation_reason = 'F10 fixture cancellation'`; venda concluída grava as três nulas. Intenção preservada (venda cancelada de 9999 continua ignorada nos totais). Nenhum código de produção nem migration alterados.

## Evidência
- LINT `tests/Integration/StockSalesOverviewIntegrationTest.php` → `No syntax errors detected`.
- SUITE com `StockSalesOverviewIntegrationTest|Failed:` → 8 `PASS  Integration\StockSalesOverviewIntegrationTest::...`, nenhum FAIL; última execução `Total: 1880, Passed: 1880, Failed: 0, Skipped: 0`.

## Observação
Numa das três execuções da SUITE, ExamResultAiIntegrationTest falhou com erros de FK (1451/1452) no centralvet_test; a execução seguinte passou 1880/1880. Parece concorrência de outra execução simultânea no mesmo banco de teste (intermitente, fora do escopo desta correção).
