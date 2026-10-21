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
- 2026-10-21 · T-24/T-25 · ondas 4 e 5 — A revisão da T-11 achou que `SystemUserForm` e `SystemUnitForm` não respeitam o tenant: listam o grupo 1 e dados de outras clínicas e salvam sem `tenant_id`. Liberá-las no seed dos planos deixaria uma clínica virar super admin. Decisão do usuário: essas telas de sistema saem do seed dos planos (a correção da T-11 também inclui perfil, senha e 2FA), e a gestão de equipe e de unidades por clínica entra nesta etapa como T-24 (equipe, Onda 4) e T-25 (unidades, seed, menu e inventário CSRF, Onda 5). As chaves de tradução das duas ficam na T-24, para `translations.json` não ser editado em duas tasks da mesma onda. A T-11 foi corrigida no e9915bf: os grupos de plano não têm `SystemUser*`, `SystemUnit*` nem `SystemGroup*`, só `SystemProfileView`, `SystemProfileForm` e `SystemProfile2FAForm` além dos programas clínicos, e o Business ficou igual ao Pro em programas (60). Por isso a T-19 não aplica mais quota em `SystemUserForm`/`SystemUnitForm`: as quotas valem só em `ClinicTeamService::invite`/`reactivate` (T-24) e `ClinicUnitService::create` (T-25), e a diferença de unidades entre Pro e Business vem só de `maxUnits()`.
- 2026-10-21 · T-13 · onda 3 — Administrador da clínica vai para o grupo do plano (`Central Vet - Plano <Nome>`, T-11), nunca para o grupo 1 (administração da plataforma, com `LandingLeadList`, `SystemDatabaseExplorer` etc.).
- 2026-10-21 · T-19 · onda 4 — Tenant sem linha em `subscription` (os de hoje, inclusive o da plataforma) mantém o acesso (`legacy`), para o dev e os testes continuarem como estão.
- 2026-10-21 · T-11 · onda 2 — Reprovada na rodada 1 (3 achados plano-mandou: SystemUserForm/SystemUnitForm sem tenant expunham o grupo 1 e outras clínicas; admin sem perfil, senha e 2FA). Usuário decidiu "Tela nova por clínica": telas System* de administração global fora do seed, gestão de equipe e unidades por clínica em T-24/T-25. Corrigida em e9915bf e aprovada na rodada 2.
- 2026-10-21 · T-10 · onda 2 — Reprovada na rodada 1: fakes FakeSubscription*Repository não exigiam transação nos *ForUpdate. Corrigida em 8f70d96, aprovada na rodada 2. Escopo aceito: src/tests/Unit/SubscriptionFakesTest.php fora de Arquivos prováveis (prova a recusa, pedido do orquestrador).
- 2026-10-21 · T-10 · onda 2 — DuplicateInvoice em qualquer 1062 não mascara outra violação (só há as UNIQUE local_key, stripe_invoice_id e stripe_payment_intent_id); a mensagem agora informa a chave que colidiu.
- 2026-10-21 · T-09 · onda 2 — findByCheckoutSessionIdForUpdate exige transação; claim() de evento novo grava attempts=1.
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
- [T-09] Repositórios de pedido e evento (Billing\Contract e Persistence, fakes em tests/Support): findByCheckoutSessionIdForUpdate exige transação; claim() novo grava attempts=1; markDone mantém last_error; latestEventCreated é MAX(event_created) de todos os status.
- [T-10] Repositórios de assinatura e fatura com DuplicateInvoice (qualquer 1062, mensagem traz key=); todo *ForUpdate exige transação; insert() devolve o id e não muda a entidade; fakes têm $inTransaction=true, $rows e $lockCalls.
- [T-11] seeds/plan-groups.sql (não aplicado): grupos 'Central Vet - Plano <Nome>' sem SystemUser*/SystemUnit*/SystemGroup*; Starter 41, Pro 60, Business 60, Enterprise 82 programas; exige permission.sql e initial-application-programs.sql antes.
- [T-12] AccountInviteService::send devolve o id do deliver e propaga MessageDeliveryFailed; PasswordResetToken::issue ganhou $ttlSeconds opcional (padrão 10800; <= 0 lança).

## Pendências
- T-01: sugestões da revisão (chave própria "Invoice amount due" na T-21; relatório devia rodar só as 3 classes do critério).
- T-02: sugestões (contagem esperada de colunas no comentário do .verify.sql).
- T-03: catch dedicado de CashSessionRequiredException no PaymentForm; corrida estorno × fechamento sem teste de duas conexões.
- T-04: sem teste automatizado do login por token (fumaça da T-22); doc cita só a 7.0.0 do php-jwt; usar MIN_KEY_BYTES na mensagem.
- T-05: README.md:169-170 e docs/security/csrf-inventario.md:172 desatualizados quanto ao download.php; botões PDF/CSV/XML e pré-visualizações de upload levam a 404.
- T-06: selo "Cancelled" duplicado na venda cancelada; sem teste de mesmo tenant nos JOINs de tutor/paciente; testes por texto-fonte frágeis.
- T-07: regras sem teste (exceção de status desconhecido, attachCheckout, markInvoicePaid em cancelled); applyProviderStatus('active') reativa cancelled; suspended mantém grace vencido; markPaidByPaymentIntent aceita fatura void/uncollectible/draft; needsPaymentIntent com expiresAt nulo.
- T-08: sem teste da redação de sk_/rk_/pk_ na mensagem de erro HTTP; vários t= no cabeçalho vale o último.
@@ nesta onda.
- T-09: teste do LogicException de findByCheckoutSessionIdForUpdate; FakeBillingOrderRepository::save sem unicidade de stripe_checkout_session_id; deadlock 1213 possível em claim concorrente (tratar em T-17 ou SELECT FOR UPDATE antes do INSERT); claim devolve evento received, então T-14 deve garantir idempotência do processador.
- T-10: ficaram de fora os ramos extras de listOpenPix/listPixDueForIssue (due_at <= now com PI vivo; past_due incluída, suspended/cancelled excluídas), teste da guarda dos outros 4 *ForUpdate reais e o tipo da exceção do save() dos fakes.
- T-11: até a T-24 e a T-25, o admin da clínica não tem gestão de usuários nem de unidades; sem asserção fixa de Hospitalization/Surgery/Bed/Ai só no Enterprise; idempotência do seed só conferida no texto (aplicar 2x no centralvet_test com autorização); citar no cabeçalho o risco de MAX+1 com nome/controller duplicado.
- T-12: sem teste de issue() com TTL <= 0 nem de send() propagando MessageDeliveryFailed.

## Riscos
- RISCO Pix: o limite de `payment_method_options[pix][expires_after_seconds]` do PaymentIntent no Stripe precisa aceitar 864000 s (10 dias). Se a API recusar, T-23 usa o máximo aceito, e o reconciliador regenera o Pix expirado, o que já está previsto. A renovação Pix depende do cron do reconciliador (a cada 15 min): sem o cron, nenhuma fatura de renovação é emitida.
- O php-jwt 7 e o dompdf novo mudam o `src/vendor`: o :8081 só reflete com rebuild de `app` e `worker`, e um `framework_hashes.php` desatualizado faz o `AdiantiFileHashGeneratorService::compare` acusar o `composer.json` alterado.
- Credenciais: só placeholders vazios `STRIPE_*` em `.env.example` e `docker-compose.yml`; nunca chave real em commit, log, relatório ou prompt; escrita no `.env` exige confirmação do usuário. O `git grep` dos critérios gerais confere.
- `billing_event.payload` guarda o JSON do Stripe (inclui e-mail do cliente): dado pessoal sob LGPD; retenção fica para a Etapa 4.
- Provisionamento grava em `system_*` com MAX+1 sob `FOR UPDATE`: dois provisionamentos simultâneos serializam no bloqueio do índice; o teste de T-13 cobre a retomada, não a corrida entre pedidos diferentes.
- O guard de T-19 lê `subscription` a cada 60 s por sessão: sem a 0017 aplicada no banco, o login quebra. Aplicar a 0017 antes de subir o código em qualquer ambiente.
- Testes de bloqueio com fixture não commitada pegam o lock implícito do INSERT (pendência da Etapa 2): T-03 complementa com o teste unitário da ordem bloqueio → escrita.
- Escopo grande (25 tasks, 6 ondas), executado de uma vez por decisão do usuário.

## Retomada
- Pasta: `.claude/tasks/mar-20261021-1554-etapa-3-contratacao/`
- Sessões: fc92cc27-9f66-4ad7-bfe3-efed43d88248
- Branch de trabalho: task/etapa-3-contratacao (base: task/etapa-2-dinheiro-estoque)
- BASE da onda 1: 49ada48
- Commits por onda:
  - Onda 1: BASE 49ada48 → HEAD 85e3ec7 (987537b, 7ab22ba, ae00071, 7415ce2, 7c864ba, 5964a0a, 95c4c4a, e736ac6, 1a877e7, 65ca55e, 191021b, db59257, 50b41ba, d74868c, d833d45, 576d8da, ca64082, 85e3ec7)
  - Onda 2: BASE df3992e → HEAD e9915bf (13374df, c2b4cc1, fb2558c, 99e580c, 8f70d96, 3d7d4ab, 55a1cac, 33e4972, b07c578, e9915bf)
- Último status conhecido: onda 2 concluída (T-09 a T-12 [x], SUITE 2110/2110); T-13 a T-25 pendentes
- Próxima onda recomendada: onda 3
