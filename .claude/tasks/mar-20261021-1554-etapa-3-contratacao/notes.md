# Notas de execução

## Decisões tomadas
- 2026-10-21 · plano · onda 0 — Base `task/etapa-2-dinheiro-estoque` (HEAD `49ada48`) por pedido explícito do desenvolvedor; `branches.py` dá produção `main` com fonte `padrão` (o projeto não declara as branches).
- 2026-10-21 · plano · onda 0 — Entrega preserva os commits por onda; `entrega.py` não roda (decisão do usuário).
- 2026-10-21 · plano · onda 0 — Gateway Stripe, cobrança mensal com cartão e Pix, os 4 planos do `LandingCatalog` com direitos no servidor (decisões do usuário).
- 2026-10-21 · plano · onda 0 — Resposta 1 do usuário: as 23 tasks rodam de uma vez, sem dividir em 3a/3b.
- 2026-10-21 · plano · onda 0 — Resposta 5 do usuário: o banco de dev ainda não está autorizado. O orquestrador pergunta quando chegar a Onda 6; a aplicação da 0017 e dos seeds no `centralvet` e a fumaça no navegador de T-22 dependem dessa resposta.
- 2026-10-21 · T-08 · onda 1 — Cliente HTTP próprio em vez de `stripe/stripe-php`, mantido depois de o usuário liberar o `composer.json` (resposta 2): (1) a liberação foi para o S5, e o SDK é uma dependência nova e grande, com cadência de versões própria, para auditar e implantar na hospedagem compartilhada; (2) o uso cabe em 6 chamadas (`POST /v1/customers`, `POST /v1/checkout/sessions`, `GET /v1/subscriptions/<id>`, `POST /v1/payment_intents`, `GET /v1/events`) e a assinatura do webhook é um HMAC-SHA256; (3) o `$transport` e o `FakeStripeClient` testam sem rede, sem mock de classe do SDK; (4) a versão da API fica numa constante (`2024-06-20`; o período ainda vem no objeto da assinatura e o processador lê `items.data[0]` como reserva). Custo: os parâmetros da API são montados à mão, e o `CurlStripeClientTest` cobre a montagem.
- 2026-10-21 · T-04 · onda 1 — Resposta 2 do usuário: autorizado editar `src/composer.json` (`firebase/php-jwt ^7.0`, `dompdf/dompdf ^3.1.6`) e regenerar o hash do arquivo em `framework_hashes.php` (sha512, como `AdiantiFileHashGeneratorService::generateHash`) no mesmo commit. O S5 é feito de verdade: `composer update` dessas dependências no container, código JWT ajustado à API 7 se ela mudou, e regressão de PDFs, login por token e reset. O `PasswordResetToken` recusa chave < 32 bytes com mensagem clara; se `APPLICATION_NAME . seed` tiver menos de 32 bytes, a T-04 para antes de subir o php-jwt.
- 2026-10-21 · T-05 · onda 1 — S6 resolvido desabilitando `download.php`: `src/files/` só tem `system/` (já bloqueado), `TPage::openFile` das telas de administração grava em `tmp/` (já recusado pelo endpoint) e os documentos clínicos usam mecanismo próprio. Os usos no framework (`TFile`, `THyperLink`, `TImage`, `download.js`) ficam em `src/lib/adianti`, que não pode ser editado; eles passam a receber 404.
- 2026-10-21 · T-07 · onda 1 — Quotas: Starter 2 usuários/1 unidade, Pro 10/1, Business 30/5, Enterprise sem limite. Recursos: `inventory_pos`, `finance_full` e `communication` do Pro em diante; `multi_unit` do Business em diante; `hospital` e `ai_advanced` só no Enterprise. Caixa, recebimento e contas de atendimento ficam em todos os planos, porque o `PaymentService` exige caixa aberto. Valores derivados de `LandingCatalog::plans()`/`compareRows()` e confirmados pelo usuário (resposta 3: internação e cirurgia só no Enterprise).
- 2026-10-21 · T-07 · onda 1 — Tolerância de 7 dias a partir do primeiro `invoice.payment_failed`; `accessLevel`: `active` → `full`; `past_due` dentro da tolerância → `grace`; demais → `blocked`. Estorno registra a fatura como `refunded` e não muda o acesso.
- 2026-10-21 · T-14/T-16/T-23 · ondas 3 — Resposta 4 do usuário: não se sabe se a conta aceita Pix em `send_invoice`, então vale o fallback. O cartão usa Subscription em `charge_automatically` (Checkout `mode=subscription`). O Pix usa um PaymentIntent avulso por fatura, sem Subscription no Stripe: o 1º mês sai por Checkout `mode=payment`; a cada período, T-23 emite, 3 dias antes do fim, uma fatura local (`local_key` `pix:<assinatura>:<data>`) com PaymentIntent Pix (`expires_after_seconds=864000` = 3 dias até o vencimento + 7 de tolerância, `Idempotency-Key` `pix-invoice-<id>-<n>`) e envia o link por e-mail. O `payment_intent.succeeded` com `metadata[subscription_invoice]` paga a fatura e avança o período (T-14). Fatura vencida sem pagamento → `markOverdue` (`past_due`, 7 dias de tolerância), sem mexer em `last_event_created`, para não descartar o pagamento que chegar depois. Pix expirado é regenerado pelo reconciliador (T-18).
- 2026-10-21 · T-13 · onda 3 — Administrador da clínica vai para o grupo do plano (`Central Vet - Plano <Nome>`, T-11), nunca para o grupo 1 (administração da plataforma, com `LandingLeadList`, `SystemDatabaseExplorer` etc.).
- 2026-10-21 · T-19 · onda 4 — Tenant sem linha em `subscription` (os de hoje, inclusive o da plataforma) mantém o acesso (`legacy`), para o dev e os testes continuarem como estão.
- 2026-10-21 · T-01 · onda 1 — Escopo aceito: 50b41ba tocou UserMessageTest.php para atualizar a contagem (STATIC=92, PATTERNS=110); "Amount due" e "Overdue" mantêm o pt antigo.
- 2026-10-21 · T-06 · onda 1 — Escopo aceito: 65ca55e tocou SaleFinanceReaderIntegrationTest.php (chaves tutor_name/patient_name no array exato).
- 2026-10-21 · T-03 · onda 1 — Escopo aceito: o RED 191021b tocou FakeCashSessionRepository.php fora do bloco Teste RED.
- 2026-10-21 · T-03 · onda 1 — O 1205 do teste "A segura caixa → B registra" vinha do recebível; virou Bloqueante [ruling]. Corrigido em ca64082 (B usa tenant commitado, MIN(id) e recebível próprio); sem o FOR UPDATE, Register e Close falham.
- 2026-10-21 · T-02 · onda 1 — billing_order.terms_accepted_at ganharia ON UPDATE CURRENT_TIMESTAMP no MySQL 5.7; corrigido na própria 0017 (ainda não aplicada em dev/produção) em 85e3ec7: DEFAULT CURRENT_TIMESTAMP(6) sem ON UPDATE, com checagem no .verify.sql; 0017 reaplicada só no centralvet_test.
- 2026-10-21 · T-05 · onda 1 — Mapa de usos do download.php completado em 576d8da (plano-mandou). Botões de exportação PDF/CSV/XML e pré-visualizações de upload levam a 404 (já não funcionavam); fica para task futura e para a fumaça da T-22.
- 2026-10-21 · T-04 · onda 1 — Resultado: dompdf 3.1.6, php-jwt 7.2.1, composer audit limpo, hash confere (o hash do composer.json já estava desatualizado antes). Rebuild de app/worker fica para a T-22.

## Bloqueios
- nenhum

## Descobertas
- `composer.json`, `engine.php`, `index.php` e `init.php` estão em `src/app/config/framework_hashes.php`; `download.php`, `lead.php`, `ApplicationAuthenticationService.php`, `SystemUserForm.php`, `SystemUnitForm.php` e `src/menu.xml` não estão.
- As tabelas `system_*` (`permission.sql`) não têm AUTO_INCREMENT; o seed usa `COALESCE(MAX(id),0)+1`. `tenant_user` liga usuário e tenant; `system_unit.tenant_id` existe desde a 0001.
- `PasswordResetToken` é de uso único pela impressão digital do hash da senha (`matchesPassword`); o link de reset é `index.php?class=SystemPasswordResetForm&method=onLoad&jwt=...`.
- `src/vendor` não está no git (`.gitignore`); o SUITE usa o vendor do host montado em `src/`.
- [T-02] 0017 aplicada no centralvet_test; colunas sem NULL na Interface são NOT NULL; defaults novos em amount_paid_cents, currency e received_at.
- [T-04] src/vendor do host: dompdf 3.1.6, php-jwt 7.2.1; PasswordResetToken com chave < 32 bytes lança InvalidArgumentException (testes usem chave maior).
- [T-01] translations.json segue ordem case-insensitive (strcasecmp); "Amount due"="Saldo a receber" e "Overdue"="Vencida" reutilizados com o pt antigo.
- [T-06] SaleFinanceReader::receiptSummary devolve também tutor_name e patient_name; Payment::METHOD_LABELS é a fonte única dos rótulos de forma de pagamento.
- [T-08] Cliente Stripe em CentralVet\Billing\Stripe (interface, cURL, config, verificador de assinatura) e FakeStripeClient em tests/Support.
- [T-03] CashSessionRepositoryInterface ganhou findByIdForUpdate e findOpenBySystemUnitForUpdate (exigem transação aberta); novos implementadores da interface precisam dos dois.
- [T-07] toRow() de BillingOrder, Subscription e SubscriptionInvoice omite created_at/updated_at; extras (constantes METHOD_*/ACCESS_*, getters) só acrescentam; status Stripe desconhecido e transição inválida lançam exceção.

## Pendências
- T-01: sugestões da revisão (chave própria "Invoice amount due" na T-21; relatório devia rodar só as 3 classes do critério).
- T-02: sugestões (contagem esperada de colunas no comentário do .verify.sql).
- T-03: catch dedicado de CashSessionRequiredException no PaymentForm; corrida estorno × fechamento sem teste de duas conexões.
- T-04: sem teste automatizado do login por token (fumaça da T-22); doc cita só a 7.0.0 do php-jwt; usar MIN_KEY_BYTES na mensagem.
- T-05: README.md:169-170 e docs/security/csrf-inventario.md:172 desatualizados quanto ao download.php; botões PDF/CSV/XML e pré-visualizações de upload levam a 404.
- T-06: selo "Cancelled" duplicado na venda cancelada; sem teste de mesmo tenant nos JOINs de tutor/paciente; testes por texto-fonte frágeis.
- T-07: regras sem teste (exceção de status desconhecido, attachCheckout, markInvoicePaid em cancelled); applyProviderStatus('active') reativa cancelled; suspended mantém grace vencido; markPaidByPaymentIntent aceita fatura void/uncollectible/draft; needsPaymentIntent com expiresAt nulo.
- T-08: sem teste da redação de sk_/rk_/pk_ na mensagem de erro HTTP; vários t= no cabeçalho vale o último.
- ExamResultAiIntegrationTest não falhou nesta onda.

## Riscos
- RISCO Pix: o limite de `payment_method_options[pix][expires_after_seconds]` do PaymentIntent no Stripe precisa aceitar 864000 s (10 dias). Se a API recusar, T-23 usa o máximo aceito, e o reconciliador regenera o Pix expirado, o que já está previsto. A renovação Pix depende do cron do reconciliador (a cada 15 min): sem o cron, nenhuma fatura de renovação é emitida.
- O php-jwt 7 e o dompdf novo mudam o `src/vendor`: o :8081 só reflete com rebuild de `app` e `worker`, e um `framework_hashes.php` desatualizado faz o `AdiantiFileHashGeneratorService::compare` acusar o `composer.json` alterado.
- Credenciais: só placeholders vazios `STRIPE_*` em `.env.example` e `docker-compose.yml`; nunca chave real em commit, log, relatório ou prompt; escrita no `.env` exige confirmação do usuário. O `git grep` dos critérios gerais confere.
- `billing_event.payload` guarda o JSON do Stripe (inclui e-mail do cliente): dado pessoal sob LGPD; retenção fica para a Etapa 4.
- Provisionamento grava em `system_*` com MAX+1 sob `FOR UPDATE`: dois provisionamentos simultâneos serializam no bloqueio do índice; o teste de T-13 cobre a retomada, não a corrida entre pedidos diferentes.
- O guard de T-19 lê `subscription` a cada 60 s por sessão: sem a 0017 aplicada no banco, o login quebra. Aplicar a 0017 antes de subir o código em qualquer ambiente.
- Testes de bloqueio com fixture não commitada pegam o lock implícito do INSERT (pendência da Etapa 2): T-03 complementa com o teste unitário da ordem bloqueio → escrita.
- Escopo grande (23 tasks, 6 ondas), executado de uma vez por decisão do usuário.

## Retomada
- Pasta: `.claude/tasks/mar-20261021-1554-etapa-3-contratacao/`
- Sessões: fc92cc27-9f66-4ad7-bfe3-efed43d88248
- Branch de trabalho: task/etapa-3-contratacao (base: task/etapa-2-dinheiro-estoque)
- BASE da onda 1: 49ada48
- Commits por onda:
  - Onda 1: BASE 49ada48 → HEAD 85e3ec7 (987537b, 7ab22ba, ae00071, 7415ce2, 7c864ba, 5964a0a, 95c4c4a, e736ac6, 1a877e7, 65ca55e, 191021b, db59257, 50b41ba, d74868c, d833d45, 576d8da, ca64082, 85e3ec7)
- Último status conhecido: onda 1 concluída (T-01 a T-08 [x], SUITE 2075/2075); tasks T-09 a T-23 pendentes
- Próxima onda recomendada: onda 2
