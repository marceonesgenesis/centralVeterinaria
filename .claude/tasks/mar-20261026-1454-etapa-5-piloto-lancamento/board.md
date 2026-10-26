# Board — mar-20261026-1454-etapa-5-piloto-lancamento

Log append-only de fatos que afetam outras tasks desta execução: contrato divergente, símbolo renomeado, arquivo compartilhado alterado, decisão que outra task precisa conhecer. Uma linha por fato, acrescentada por append com heredoc (abaixo; o delimitador entre aspas aceita qualquer caractere no fato); nunca edite ou remova linhas. Leia antes de começar uma task e antes de usar cada `Consome`. O fechador consolida as linhas em `notes.md § Descobertas`.

Formato: `- [T-NN] <fato>`

Append:

```bash
cat >> "/var/www/html/centralvet/.claude/tasks/mar-20261026-1454-etapa-5-piloto-lancamento/board.md" <<'EOF'
- [T-NN] <fato>
EOF
```
- [T-03] `PilotSettings`/`OfferScope` prontos conforme a Interface; preflight ganhou o achado `support_email` logo após `operator_email` (ordem dos códigos mudou). `docs/runbooks/release.md` e `docs/runbooks/observabilidade.md` listam códigos do preflight e não citam `support_email` (fora dos arquivos de T-03; T-20 pode incluir).
- [T-01] 0020 aplicada no centralvet_test (schema_migrations=20): subscription.cancel_at_period_end/cancel_requested_at/cancel_requested_by, legal_acceptance (accepted_at com DEFAULT CURRENT_TIMESTAMP(6); FK legal_acceptance_tenant_fk) e tenant_onboarding (FK tenant_onboarding_tenant_fk) disponíveis para T-08/T-09/T-10.
- [T-04] `ClinicRoleProfiles` e `src/app/database/seeds/role-groups.sql` prontos conforme a Interface (3 grupos "Central Vet - Perfil Recepção|Veterinário|Financeiro"); seed aplicado 2x no `centralvet_test` (idempotente). `SupportView` e `FirstEncounterGuideView` só são ligados depois que T-19 os registrar em `system_program` e o seed for reaplicado.
- [T-04] Risco para T-19/T-18: os grants amplos de `AiCopilotPanel`/`AiConversationList` em `initial-application-programs.sql` vão a todo grupo com `EncounterView` exceto `Central Vet - Plano %`; reaplicar o seed inicial depois de `role-groups.sql` liga IA ao "Central Vet - Perfil Veterinário". Excluir também `Central Vet - Perfil %` nesses dois grants (fora do escopo de T-04; não editado).
- [T-06] `CentralVet\Legal\LegalDocuments` e `LegalPage` prontos conforme a Interface (commit 0982a23); URLs `legal.php?doc=termos|privacidade`; versões `termos-uso-2026-10` / `privacidade-2026-10`; extra: `LegalPage::NOT_FOUND_BODY = 'Documento não encontrado'`.
