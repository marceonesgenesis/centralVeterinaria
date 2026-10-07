# Board — mar-20261001-2231-landing-publica-carrinho

Log append-only de fatos que afetam outras tasks desta execução: contrato divergente, símbolo renomeado, arquivo compartilhado alterado, decisão que outra task precisa conhecer. Uma linha por fato, acrescentada por append com heredoc (abaixo; o delimitador entre aspas aceita qualquer caractere no fato); nunca edite ou remova linhas. Leia antes de começar uma task e antes de usar cada `Consome`. O fechador consolida as linhas em `notes.md § Descobertas`.

Formato: `- [T-NN] <fato>`

Append:

```bash
cat >> "/var/www/html/centralvet/.claude/tasks/mar-20261001-2231-landing-publica-carrinho/board.md" <<'EOF'
- [T-NN] <fato>
EOF
```
- [T-01] Contrato pronto (2023e96): `LandingCatalog::publicJson()` tem `consent` como objeto `{"version": CONSENT_VERSION, "text": CONSENT_TEXT}` e `vets` como objeto chave→rótulo (VETS_OPTIONS); `ufs` em ordem alfabética (AM antes de AP, diferente do rascunho).
- [T-01] `LeadSubmission::fromPayload`: `city` e `uf` ausentes no payload contam como `''` (opcionais); os demais campos ausentes ou não-string são inválidos. Chave de erro é o nome do campo do payload (`plan`, não `planId`).
- [T-04] `LeadRepository` pronto (a0f40da): `insert` grava `created_at` = `consentAt` (mesma string `Y-m-d H:i:s.u`, fuso APP_TIMEZONE); `search` devolve `id`/`plan_price_cents` int, demais colunas string (datas `Y-m-d H:i:s.uuuuuu`); `limit` limitado a 1..500, `offset` < 0 vira 0.
- [T-05] `POST /lead.php` pronto (9c20c9d): `src/lead.php` instancia `CentralVet\Persistence\LeadRepository($pdo)` do T-04; header do token lido como `x-cv-lead-token` (minúsculas); limitador com bucket `lead|<REMOTE_ADDR>` e prefixo `centralvet:lead-throttle:`; 422 devolve `{"accepted":false,"error":"validation","fields":{...}}` sem consumir o token.
- [T-06] Cookie de sessão do app é `PHPSESSID_centralvet` (session_name() via AdiantiApplicationConfig), não `PHPSESSID`: gate curl de sessão admin/inexistente deve usar esse nome. `landing.php` em `src/landing.php`, assets em `/landing/landing.css` e `/landing/landing.js` (b28043f).
- [T-08] LandingLeadList pronto (a903dff RED, 9a16203): filtros por parâmetro de requisição (plan_id, from/to em d/m/Y no POST ou Y-m-d na paginação/CSV); CSV em engine.php?class=LandingLeadList&method=onExport&static=1; xmllint ausente no host e no container (menu.xml validado com simplexml).
- [T-09] Validação final ok (485/485, lint 23 arquivos, nginx, curl, navegador anônimo/admin, contagens, escopo vazio); /favicon.ico cai em index.php e cria sessão para anônimo (pré-existente).
