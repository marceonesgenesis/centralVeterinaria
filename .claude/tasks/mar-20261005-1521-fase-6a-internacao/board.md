# Board — mar-20261005-1521-fase-6a-internacao

Log append-only de fatos que afetam outras tasks desta execução: contrato divergente, símbolo renomeado, arquivo compartilhado alterado, decisão que outra task precisa conhecer. Uma linha por fato, acrescentada por append com heredoc (abaixo; o delimitador entre aspas aceita qualquer caractere no fato); nunca edite ou remova linhas. Leia antes de começar uma task e antes de usar cada `Consome`. O fechador consolida as linhas em `notes.md § Descobertas`.

Formato: `- [T-NN] <fato>`

Append:

```bash
cat >> "/var/www/html/centralvet/.claude/tasks/mar-20261005-1521-fase-6a-internacao/board.md" <<'EOF'
- [T-NN] <fato>
EOF
```
- [T-03] Bed não muda ocupação: occupy/release só no repositório (fakes de T-06 devem montar o leito ocupado via Bed::reconstitute com status/current_hospitalization_id). Extras aditivos: BedUnavailableException::occupied(id), getters admittedBySystemUserId()/dischargedBySystemUserId()/createdAt()/updatedAt(); HospitalizationEvent::notesText() devolve null para texto vazio; discharge() guarda summary vazio como null.
- [T-04] Além do contrato: `HospitalizationOrder::reconstitute(id, tenantId, hospitalizationId, orderType, descriptionText, productId, quantityPerAdministration, doseText, route, frequencyHours, startsAt, endsAt, status, prescribedBySystemUserId, suspendedAt)`, `assignId()`, `prescribedBySystemUserId()`, `suspendedAt()`, `TYPES`; `suspend()` fora de `active` → `InvalidStatusTransitionException` `Order <id> is not active`; quantidade sem produto → `quantity_per_administration requires a product`; mensagens extras: `description_text is required`, `Unknown route "<r>"`, `Unknown order_type "<t>"`. `HospitalizationAdministration::reconstitute(id, tenantId, hospitalizationId, orderId, scheduledAt, status, performedAt, performedBySystemUserId, notesText)` e `assignId()`; `markDone` aceita nota vazia (vira null). `AdministrationSchedule::assertValid(startsAt, endsAt, frequencyHours)` público (usado pelo prescribe).
- [T-02] scripts/prepare-mysql57.py: `ALTER TABLE t DROP CHECK x` vira `DROP TRIGGER IF EXISTS x_bi/x_bu`; verify 5.7 usa `verification_query()` e exige literal `table_name = '<t>'` ou `table_name IN (...)` em toda consulta a check_constraints (senão ValueError).
- [T-01] 0010 preparada (743537a): 5 tabelas, 16 CHECKs (14 novos + 2 ampliados), 2 UNIQUEs, 23 FKs; provision.sh já lista a 0010 depois da 0009. Aguarda o bloqueio do orquestrador para aplicar.
