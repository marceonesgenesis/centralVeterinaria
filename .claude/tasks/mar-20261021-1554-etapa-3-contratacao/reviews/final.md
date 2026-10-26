# Revisão final
Branch `task/etapa-3-contratacao` (fbe280f) contra `task/etapa-2-dinheiro-estoque` (49ada48), 219 arquivos, +25403/-380.

## Bloqueantes
- [bloqueante] [plano-mandou] O checkout aceita um e-mail que já é login de outro usuário e cobra sem criar conta. Não há checagem de login em `CheckoutStartHandler` (src/app/Core/Billing/CheckoutStartHandler.php:108-146: insere o pedido e chama `POST /v1/customers` e `/v1/checkout/sessions` sem consultar `system_users`). O pagamento é confirmado (BillingEventProcessor.php:100-127: no cartão cria a `subscription` local ligada à Subscription do Stripe em `charge_automatically`). O provisionamento cai em `login_conflict` (TenantProvisioningService.php:198-203), o pedido fica `provisioning_failed` e o reconciliador desiste depois de 10 tentativas (BillingOrderRepository.php:97). Nenhum caminho cancela a Subscription nem estorna (`grep cancel|refund` em TenantProvisioningService/BillingReconciler: 0), e o Stripe segue cobrando todo mês um cliente sem acesso. O mesmo acontece se a mesma pessoa paga dois checkouts com o mesmo e-mail (cobrança dupla, sem conta duplicada). O plano mandou o resultado `provisioning_failed` sem chamar o Stripe (plan.md:306, tasks.md:467 "Nunca chama o Stripe"), e por isso o ruling é do orquestrador. Saídas mínimas para o MVP: (a) recusar em 422, no `CheckoutStartHandler`, um e-mail que já é login ou que tem pedido `paid`/`provisioned`; ou (b) cancelar ou estornar no reconciliador o pedido em `login_conflict`, ou alertar o operador.

## Pendências promovidas
- nenhuma

## Triagem
- [aberta] T-01: chave própria "Invoice amount due" e o relatório das 3 classes. → cosmético
- [aberta] T-02: contagem de colunas no comentário do .verify.sql. → cosmético
- [aberta] T-03: o catch dedicado de CashSessionRequiredException no PaymentForm e o teste em duas conexões de estorno × fechamento. → o código trava os dois lados (CashSessionService::close com findByIdForUpdate; PaymentReversalService com findOpenBySystemUnitForUpdate; PaymentService com findByIdForUpdate), então resta só a cobertura
- [aberta] T-04: login por token sem teste automatizado; a doc cita só a 7.0.0; MIN_KEY_BYTES fora da mensagem. → não afeta dinheiro nem isolamento
- [aberta] T-05: README.md:169-170 e docs/security/csrf-inventario.md:179 ainda dizem que downloads passam por `download.php`, que agora devolve 404 (src/download.php). → só documentação
- [aberta] T-06: selo "Cancelled" duplicado (SaleCancelForm.php:97); sem teste de mesmo tenant nos JOINs. → sugestão
- [aberta] T-07: regras sem teste; applyProviderStatus('active') reativa cancelled (o Stripe é a fonte); markPaidByPaymentIntent em void/draft. → o valor vem do PI criado pelo servidor, sem risco de forja
- [aberta] T-08: sem teste da redação de sk_/rk_/pk_; com vários t= vale o último. → sugestão
- [aberta] T-09: o deadlock 1213 do claim está resolvido (BillingEventIngestor::retrying, 1205/1213, até 3x); restam o teste do LogicException e a unicidade no fake
- [aberta] T-10: ramos extras de listOpenPix/listPixDueForIssue sem teste. → sugestão
- [resolvida] T-11: o admin ganhou gestão de equipe e unidades (ClinicUser*/ClinicUnit* em plan-groups.sql e no menu.xml, T-24/T-25); a dupla aplicação do seed é registrada em notes.md:41-42 e não foi reproduzida aqui
- [aberta] T-12: issue() com TTL <= 0 e send() propagando MessageDeliveryFailed sem teste. → sugestão
- [aberta] T-13: convite fora da trava, com envio duplo possível. → o token é de uso único pela impressão da senha; dois links válidos até o 1º uso; não é risco de segurança
- [aberta] T-14: o rollback de StripeApiException depois de orders->save está resolvido (a transação 2 do ingestor desfaz tudo, BillingEventIngestor.php:70-87); restam amount_received sem comparação (o PI é do servidor) e a barreira por `created`
- [aberta] T-15: reason `full` com a assinatura suspensa; consulta antes do ALWAYS_ALLOWED; quota sem trava. → as inserções em system_users já serializam por `MAX(id)+1 FOR UPDATE`; exceder a quota em 1 não é risco de dinheiro
- [aberta] T-16: CHECKOUT_RATE_LIMIT_* fora do .env.example (confirmado); token consumido antes do Stripe; o RuntimeException de priceIdFor escapa do catch de StripeApiException (CheckoutStartHandler.php:145, StripeConfig.php:55), o checkout.php:105 responde 500 e o pedido fica pending. → nada é cobrado
- [resolvida] T-17 (webhook inexistente): a Stripe-Signature é conferida antes de qualquer escrita (BillingWebhookHandler.php:57-66, e o PDO só é usado no ingest); a exceção está documentada em csrf-inventario.md:182; há rollback e retry de 1213
- [aberta] T-18: a fatura sem PI está resolvida (Idempotency-Key `pix-invoice-<local_key>-<attempts+1>` persistida, PixInvoiceIssuer.php:121); restam a repetição de 24 h do 500 com a mesma chave e o deadlock entre provisionamentos
- [aberta] T-23: ramo DuplicateInvoice e resposta sem expires_at sem teste. → sugestão
- [aberta] T-17: markFailed sem guarda de status pode trocar `processed` por `failed` numa corrida. → o reprocessamento é idempotente (markPaid e markPaidByPaymentIntent devolvem false e saem `ignored`), sem cobrança dupla
- [aberta] T-19: fail-open sem cache; ordem contra a sessão concorrente; wiring test sem message_key; link Sair sem static=1. → o fail-open é ruling registrado
- [aberta] T-20: retry depois do 502 dá 403; TOKEN compartilhado; bfcache. → UX
- [aberta] T-21: money() duplicado; sem teste de wiring da view. → sugestão
- [aberta] T-24: o e-mail de convite falho só vai ao error_log; reactivate sem guard; "login exists" global enumera logins de outras clínicas, mas só para o admin de uma clínica, com CSRF e RBAC. → não vaza dados A×B
- [aberta] T-22: itens da fumaça no navegador (403, endpoint HTTP, 44 px, nginx /checkout.php). → não verificado nesta revisão; notes.md:41 registra a fumaça a..p
- [aberta] T-18: o log de MAX_EVENT_PAGES está resolvido (BillingReconciler.php:134-135); restam o teste da guarda PHP_SAPI, as 3 tentativas do e-mail Pix e o contador compartilhado
- [aberta] T-25: rename sem consulta própria ao guard. → o guard de requisição (enforceSubscriptionAccess) já bloqueia ClinicUnitForm com a assinatura suspensa; resta o teste de findUnit de outro tenant e os cabeçalhos com contagens antigas
- [aberta] T-25/T-11: seeds conferidos só no texto. → a aplicação no dev e a dupla no test estão em notes.md:41-42, não reproduzidas aqui
- [aberta] Ambiente: 1213/1452 intermitentes no centralvet_test. → ruído de ambiente
- [aberta] T-22 (onda 6): mensagem de tenant em inglês; remetente de e-mail no dev; roteiro no scratchpad; `calls[1]` frágil. → sugestão
- [aberta] T-08 (onda 6): `$` sem `D` aceita `sk_test_x\n` no construtor direto. → a chave vem do ambiente; sugestão
- [resolvida] T-15 (onda 6): divergência entre a classe da query e a do POST. → SubscriptionAccessGuard::requestTarget usa MutationRequestPolicy::dispatchTarget, e o divergente é negado em ApplicationAuthenticationService.php:375-379
- [aberta] T-18 (onda 6): PI criado sem o vencimento registrado; Stripe fora do ar durante toda a tolerância. → raro, e o pagamento que chegar é creditado
- [aberta] T-25 (onda 6): critério do diff imprime 2 (aceito); e-mail do Pix sem retentativa. → sugestão

## Rulings
- plano · onda 0 — Base task/etapa-2-dinheiro-estoque (49ada48); produção main (padrão).
- plano · onda 0 — Entrega preserva os commits por onda; sem entrega.py.
- plano · onda 0 — Stripe, mensal com cartão e Pix, 4 planos do LandingCatalog com direitos no servidor.
- plano · onda 0 — 23 tasks de uma vez, sem 3a/3b.
- plano · onda 0 — Banco de dev só com autorização na Onda 6.
- T-08 · onda 1 — Cliente HTTP próprio (cURL) em vez de stripe-php; API 2024-06-20.
- T-04 · onda 1 — composer.json liberado (php-jwt ^7.0, dompdf ^3.1.6) e hash sha512 regenerado.
- T-05 · onda 1 — S6 resolvido desabilitando download.php (404).
- T-07 · onda 1 — Quotas: Starter 2/1, Pro 10/1, Business 30/5, Enterprise ilimitado; recursos por plano.
- T-07 · onda 1 — Tolerância de 7 dias desde o 1º payment_failed; estorno não muda o acesso.
- T-14/T-16/T-23 · onda 3 — Cartão por Subscription; Pix por PI avulso por fatura com Idempotency-Key e regeneração pelo reconciliador.
- T-24/T-25 · ondas 4 e 5 — System* globais fora do seed dos planos; equipe e unidades por clínica em telas próprias; quotas só nos serviços novos.
- T-13 · onda 3 — Admin da clínica vai para o grupo do plano, nunca para o grupo 1.
- T-19 · onda 4 — Tenant sem subscription = legacy (acesso mantido).
- T-11 · onda 2 — Reprovada na rodada 1; "Tela nova por clínica"; corrigida em e9915bf.
- T-10 · onda 2 — Fakes exigem transação nos *ForUpdate (8f70d96); SubscriptionFakesTest aceito fora do escopo.
- T-10 · onda 2 — DuplicateInvoice em qualquer 1062, com a chave na mensagem.
- T-09 · onda 2 — findByCheckoutSessionIdForUpdate exige transação; claim novo com attempts=1.
- T-01 · onda 1 — Escopo aceito: UserMessageTest (92/110).
- T-06 · onda 1 — Escopo aceito: SaleFinanceReaderIntegrationTest.
- T-03 · onda 1 — Escopo aceito: FakeCashSessionRepository no RED.
- T-03 · onda 1 — 1205 do recebível virou bloqueante [ruling]; corrigido em ca64082.
- T-02 · onda 1 — terms_accepted_at sem ON UPDATE (85e3ec7).
- T-05 · onda 1 — Mapa de usos do download.php (576d8da); exportações PDF/CSV/XML com 404 aceitas.
- T-04 · onda 1 — dompdf 3.1.6, php-jwt 7.2.1, audit limpo, hash confere.
- T-13 · onda 3 — freeSlug com desempate e SAVEPOINT; teste de corrida dispensado.
- T-14 · onda 3 — Pix que avança o período usa max(created, last_event_created); brecha residual aceita.
- T-15 · onda 3 — ALWAYS_ALLOWED legacy/grace/full; tenant provisionado nunca sem assinatura.
- T-17/T-24 · onda 4 — Retomada sem perda depois da interrupção.
- T-20 · onda 4 — Escopo aceito: LandingPage::entryFor e os testes de cache buster (bc0b08f).
- T-19 · onda 4 — Página 403 autônoma no lugar da tela em branco (a84b76c).
- T-19 · onda 4 — Fail-open na falha de leitura da assinatura, com error_log e cache de 60 s.
- T-24 · onda 4 — Escopo aceito: UserMessageTest 92→99; seed, menu e CSRF passam para a T-25.
- T-17 · onda 4 — attempts sobe 2 por entrega; asserções do RED ajustadas.
- T-18 · onda 5 — Travamentos de lote viraram bloqueante [ruling]; PI duplicado na falha do e-mail corrigido (52ca85a).
- T-18 · onda 5 — Escopo autorizado fora dos Arquivos prováveis; guarda PHP_SAPI.
- T-18 · onda 5 — E-mail do Pix com 3 tentativas na hora, sem retentativa entre rodadas.
- T-22 · onda 6 — Fumaça no dev pelo orquestrador, com autorização direta do usuário.
- T-22 · onda 6 — Limpeza do centralvet_test e reaplicação dupla.
- T-22 · onda 6 — Reparo do .git e recommit 3bb0850.
- T-08 · onda 6 — Correções pós-onda aceitas sem fix loop (a80a2ef, fb7ecd7, 3bb0850, 9323c8e, df743de, 94008c4).
- T-19 · onda 6 — Fail-open vigente e comunicado ao usuário.

## Achados
- [bloqueante] [plano-mandou] E-mail que já é login: cobrança, recorrente no cartão, sem conta, cancelamento nem estorno (ver § Bloqueantes). → src/app/Core/Billing/CheckoutStartHandler.php:108; src/app/Core/Billing/TenantProvisioningService.php:198-203; src/app/Core/Persistence/BillingOrderRepository.php:97
- [sugestão] Isolamento intra-clínica: as opções de grupo do convite são os grupos de `tenant_group` (ClinicTeamService.php:86-87), e o provisionamento só liga o grupo do plano. Com isso, todo membro convidado recebe o grupo que contém ClinicUserForm/ClinicUnitForm e pode gerir a equipe. Não verifiquei se existem papéis por clínica em outro mecanismo. Não é vazamento A×B. → src/app/Core/Tenancy/ClinicTeamService.php:86
- [sugestão] Evento de outra integração na mesma conta Stripe (checkout.session.completed sem pedido, charge.refunded sem fatura) lança BillingEventOutOfOrder: 500 até o Stripe desistir, e falha de novo a cada rodada do reconciliador. → src/app/Core/Billing/BillingEventProcessor.php:88,296
- [sugestão] notes.md:92 tem a linha solta "@@ nesta onda." (resto de edição). → .claude/tasks/mar-20261021-1554-etapa-3-contratacao/notes.md:92

## Verificado (com evidência)
- Stripe-Signature (HMAC, hash_equals, tolerância de 300 s, segredo vazio recusado) antes de json_decode e de qualquer escrita: BillingWebhookHandler.php:57-66, StripeSignatureVerifier.php:23-53.
- Deduplicação: UNIQUE (provider, event_id) com o claim FOR UPDATE e os status DONE devolvendo null (BillingEventRepository::claim). Fora de ordem: BillingEventOutOfOrder → markFailed → 500 → reenvio. Rollback de toda a transação 2.
- Idempotency-Key: `customer-<public_id>`, `checkout-<public_id>` (CheckoutStartHandler.php:134,141) e `pix-invoice-<local_key>-<n>` persistido (PixInvoiceIssuer.php:121).
- Preço do servidor: o cartão usa o price_id da config e o Pix usa `unit_amount` de `order->priceCents()` (CheckoutStartHandler.php:181,194). Limitador antes do token (linha 76) e token de uso único (linha 104).
- Chave Stripe inválida ou vazia falha antes da requisição (CurlStripeClient.php:35).
- Guard: fonte canônica do método (requestTarget → dispatchTarget), fail-open com error_log (ApplicationAuthenticationService.php:388-396), cache de 60 s por tenant|chave.
- Provisionamento retomável: pedido FOR UPDATE, cada passo com busca prévia, rollback na exceção, convite depois do commit.
- S5: composer.json só com as 2 linhas; o sha512 de framework_hashes.php confere com o arquivo (d1083ca7…). Nenhum diff em src/lib/adianti, engine.php, index.php e init.php. `git grep` de segredos sem saída.
- S6: download.php responde 404 e grava log.
- T-03: FOR UPDATE no pagamento, no fechamento e no estorno.
- CLAUDE.md: nenhum `text-align:right|center` novo; th com `text-align:left`; landing com `--cv-touch-target: 44px`; as telas novas usam `cv-touch-target`.

## Não verificado
- SUITE completa e LINT (não rodados nesta revisão).
- Conteúdo dos seeds (plan-groups.sql, 1632 linhas) e da migration 0017 além do diff --stat.
- landing.js/translations.js, wiring das telas ClinicUser*/ClinicUnit*, SubscriptionStatusView em detalhe.
- Concorrência de quotas e da corrida de provisionamentos de pedidos diferentes.
- invoiceEvent/subscriptionEvent/chargeRefunded em detalhe e markOverdue × pagamento tardio além do Review Focus.

## Decisões que tomei
- Não promovi nenhuma pendência. Nenhuma das 34 agrava risco de dinheiro, segurança ou isolamento A×B na branch inteira: o fail-open e o reprocessamento idempotente estão cobertos por rulings ou pelo código.
- Classifiquei o login_conflict pago como achado novo [bloqueante] [plano-mandou], e não como pendência promovida, porque não constava em § Pendências e porque o resultado `provisioning_failed` sem Stripe foi mandado pelo plano (plan.md:306).
- Marquei como "aberta (não verificado)" os itens cuja prova é a fumaça do orquestrador (notes.md:41-42), porque ela não aparece no diff.

---

# Revisão final — rodada 2
Branch `task/etapa-3-contratacao` (a36f3bb) contra `task/etapa-2-dinheiro-estoque` (49ada48): 243 arquivos, +28081/-385. Correção nas ondas 7 e 8, `fbe280f..a36f3bb`, com 58 arquivos e +2764/-91.

## Bloqueante da rodada 1
- [resolvida] login_conflict pago: cobrança sem conta, sem cancelamento e sem estorno. Correção em duas frentes:
  - (a) `CheckoutStartHandler` recusa com 422 o e-mail tomado (`isTaken` em CheckoutStartHandler.php:110-112). A recusa vem depois de `validate()` e antes de `formTokens->consume` (linha 125), de `orders->insert` (linha 143) e de qualquer chamada ao Stripe. A consulta está em AccountEmailReader.php:42-60: `system_users.login` ou pedido `paid|provisioned|provisioning_failed`, com a collation `_ci`.
  - (b) `LoginConflictRefundService` resolve a corrida. No cartão, faz o GET da Subscription e, se ela não estiver `canceled`, o DELETE com Idempotency-Key `cancel-<public_id>` (linha 154). Depois cria `POST /v1/refunds` com a chave `refund-<public_id>` (linha 166) e, no `charge_already_refunded`, reaproveita o estorno existente. Por fim cancela a assinatura local e marca o pedido `refunded`. Depois do commit grava o error_log `login_conflict_refunded` e manda o e-mail a `BILLING_OPERATOR_EMAIL`. Cada passo roda numa transação com FOR UPDATE e pode ser retomado.
  - O reconciliador roda a fase `refundLoginConflicts` antes de `fulfillOrders` (BillingReconciler.php:95,174). `listPendingFulfillment` exclui o login_conflict (BillingOrderRepository.php:99).
  - Testes: SUITE completo `Total: 2307, Passed: 2307, Failed: 0, Skipped: 0`. Passam `testTakenEmailAnswers422WithoutOrderNorStripeAndKeepsTheToken` (0 chamadas ao Stripe), `testLoginIsTakenIgnoringCaseAndSurroundingSpaces`, os 8 casos de `LoginConflictRefundIntegrationTest`, `testPaidLoginConflictIsRefundedByTheReconcilerWithoutProvisioningAgain` e `testScenarioQLoginTakenAfterCheckoutIsCancelledAndRefundedOnceByTheReconciler` (1 DELETE, 1 POST /v1/refunds, pedido `refunded`, assinatura `cancelled`, nenhum tenant criado; a 2ª rodada faz só `GET /v1/events`).

## Triagem
- [aberta] T-01: chave "Invoice amount due" e o relatório das 3 classes. → cosmético
- [aberta] T-02: contagem de colunas no comentário do .verify.sql. → cosmético
- [aberta] T-03: o catch dedicado e o teste de estorno × fechamento em duas conexões. → os locks existem; falta só a cobertura
- [aberta] T-04: login por token sem teste; a doc cita só a 7.0.0; MIN_KEY_BYTES. → sugestão
- [resolvida] T-05: README.md:169,178 e csrf-inventario.md:179 dizem "desabilitado (404)" (T-30); o grep do critério sai vazio. Os botões com 404 seguem aceitos por ruling (T-05, onda 1).
- [aberta] T-06: selo "Cancelled" duplicado; sem teste de mesmo tenant nos JOINs. → sugestão
- [aberta] T-07: regras sem teste; applyProviderStatus('active') reativa cancelled; markPaidByPaymentIntent em void/draft. → o Stripe é a fonte
- [aberta] T-08: sem teste da redação de sk_/rk_/pk_; com vários t= vale o último. → sugestão
- [aberta] T-09: teste do LogicException; unicidade no fake. → o 1213 está resolvido (rodada 1)
- [aberta] T-10: ramos extras de listOpenPix/listPixDueForIssue sem teste. → sugestão
- [resolvida] T-11: gestão de equipe e unidades pela T-24 e pela T-25 (rodada 1).
- [aberta] T-12: TTL <= 0 e MessageDeliveryFailed sem teste. → sugestão
- [aberta] T-13: convite fora da trava, com envio duplo possível. → token de uso único; não é risco de segurança
- [aberta] T-14: amount_received não comparado; barreira por `created`. → o PI é do servidor
- [aberta] T-15: reason `full` na suspensa; quota sem trava. → exceder em 1 não envolve dinheiro
- [aberta] T-16: CHECKOUT_RATE_LIMIT_* (`grep -c` = 2) e o RuntimeException de priceIdFor (502 antes do Stripe, CheckoutStartHandler.php:114-122) foram resolvidos pela T-27. Restam o token consumido antes do Stripe (o retry depois do 502 dá 403), o teste com contact_consent e as mensagens 422 em pt fixo. → nada é cobrado
- [resolvida] T-17 (webhook): a assinatura é conferida antes de qualquer escrita, a exceção está documentada e há rollback e retry de 1213 (rodada 1).
- [aberta] T-18: Stripe repete por 24 h o 500 com a mesma Idempotency-Key; deadlock entre provisionamentos. → retomável
- [aberta] T-23: DuplicateInvoice e resposta sem expires_at sem teste. → sugestão
- [aberta] T-17: markFailed sem guarda de status. → o reprocessamento é idempotente
- [aberta] T-19: fail-open sem cache; ordem contra a sessão concorrente; link Sair. → o fail-open é ruling
- [aberta] T-20: retry depois do 502 dá 403; TOKEN compartilhado; bfcache. → UX
- [aberta] T-21: money() duplicado; sem teste de wiring da view. → sugestão
- [aberta] T-24: e-mail de convite só no error_log; reactivate sem guard; "login exists" enumera logins de outras clínicas. → só para o admin, com CSRF e RBAC
- [aberta] T-22 (fumaça): 403, endpoint HTTP, 44 px, nginx. → não reproduzido aqui
- [aberta] T-18: teste da guarda PHP_SAPI; e-mail do Pix com 3 tentativas; contador compartilhado. → ruling da onda 5
- [aberta] T-25: rename sem consulta própria ao guard; findUnit de outro tenant sem teste. → o guard de requisição cobre o rename
- [aberta] T-25/T-11: seeds conferidos só no texto. → aplicação registrada em notes, não reproduzida
- [aberta] Ambiente: 1213/1452 intermitentes no centralvet_test. → ruído; o SUITE completo passou aqui
- [aberta] T-22 (onda 6): a mensagem de tenant em inglês foi resolvida pela T-29 (UserMessage.php:126 → "The selected record does not belong to this clinic"). Restam o remetente no dev, o roteiro no scratchpad e `calls[1]`. → sugestão
- [aberta] T-08 (onda 6): `$` sem `D`. → a chave vem do ambiente
- [resolvida] T-15 (onda 6): divergência entre a classe da query e a do POST (rodada 1).
- [aberta] T-18 (onda 6): PI criado sem o vencimento registrado; Stripe fora do ar durante toda a tolerância. → raro
- [aberta] T-25 (onda 6): o critério do diff imprime 2; e-mail do Pix sem retentativa. → aceito
- [aberta] T-28: o teste de evento de outra integração não confere o error_log `ignored foreign`. → só cobertura
- [aberta] T-28: `charge.refunded` de uma fatura de cartão nossa ainda não registrada sai `ignored` (BillingEventProcessor.php:311-313). → só deixa de marcar a fatura local como refunded, e o estorno não muda o acesso (ruling T-07)
- [aberta] T-26: as regras novas do Fake não têm teste próprio. → os testes da T-31 usam o repositório MySQL (BillingOrderRepositoryIntegrationTest.php:197-244, LoginConflictRefundIntegrationTest)
- [aberta] T-26: recordRefund sobrescreve stripe_refund_id. → o serviço só chama quando o id é nulo (LoginConflictRefundService.php:68)
- [aberta] T-26/T-31: TenantProvisioningService ainda aceita login_conflict, e o domínio não impede markProvisioned depois de recordRefund (TenantProvisioningService.php:64,93). Nenhum caminho normal chama fulfill num login_conflict: a fila exclui o caso (BillingOrderRepository.php:99), o ingestor só chama fulfill depois de markPaid true, e PAID_STATUSES recusa o pagamento repetido. Resta a corrida webhook × reconciliador com o login liberado entre os passos. → não agravada
- [aberta] T-26: o teste usa in_array com limite 100000. → cobertura
- [aberta] T-27: o RED foi registrado só como símbolo ausente. → processo; o comportamento está coberto por testTakenEmail… e testMissingCardPrice…
- [aberta] T-27: o 422 sem consumir o token vira oráculo de conta. → plano-mandou (tasks.md T-27 Interface: "o form_token não é consumido"); limitado a 10/h por IP; risco aceito
- [aberta] T-27: BillingAcquisitionFlowIntegrationTest intermitente. → passou no SUITE completo aqui
- [aberta] Ambiente (onda 7): 1213/1412 intermitentes. → ruído
- [aberta] T-31: cartão sem `stripe_subscription_id` pula o cancelamento (LoginConflictRefundService.php:144). → inalcançável no fluxo: o pedido de cartão não chega a `paid` sem a subscription (BillingEventProcessor.php:100-102, RuntimeException antes de markPaid); é defensivo
- [aberta] T-31: LIMIT 50 por id ASC; 50 falhas permanentes travam os seguintes (BillingOrderRepository.php:121). → exige 50 sessões sem invoice/PI; sugestão: ordenar por tentativas ou contar falhas
- [aberta] T-31: o runbook cita `mark_refunded` entre os passos que falham no Stripe (shared-hosting-mysql57.md:575), mas esse passo não chama o Stripe. → só documentação

## Rulings
- plano · onda 0 — Base task/etapa-2-dinheiro-estoque (49ada48); produção main (padrão).
- plano · onda 0 — Entrega preserva os commits por onda; sem entrega.py.
- plano · onda 0 — Stripe mensal com cartão e Pix; 4 planos do LandingCatalog com direitos no servidor.
- plano · onda 0 — 23 tasks de uma vez, sem 3a/3b.
- plano · onda 0 — Banco de dev só com autorização na Onda 6.
- T-08 · onda 1 — Cliente HTTP próprio (cURL) em vez de stripe-php; API 2024-06-20.
- T-04 · onda 1 — composer.json liberado (php-jwt ^7.0, dompdf ^3.1.6) e hash regenerado.
- T-05 · onda 1 — S6 resolvido desabilitando download.php (404).
- T-07 · onda 1 — Quotas e recursos por plano.
- T-07 · onda 1 — Tolerância de 7 dias; o estorno não muda o acesso.
- T-14/T-16/T-23 · onda 3 — Cartão por Subscription; Pix por PI avulso por fatura.
- T-24/T-25 · ondas 4 e 5 — System* globais fora do seed; equipe e unidades por clínica.
- T-13 · onda 3 — O admin da clínica vai para o grupo do plano, nunca para o grupo 1.
- T-19 · onda 4 — Tenant sem subscription = legacy.
- T-11 · onda 2 — Reprovada na rodada 1; "Tela nova por clínica"; corrigida em e9915bf.
- T-10 · onda 2 — Fakes exigem transação nos *ForUpdate (8f70d96).
- T-10 · onda 2 — DuplicateInvoice em qualquer 1062, com a chave na mensagem.
- T-09 · onda 2 — findByCheckoutSessionIdForUpdate exige transação; claim com attempts=1.
- T-01 · onda 1 — Escopo aceito: UserMessageTest.
- T-06 · onda 1 — Escopo aceito: SaleFinanceReaderIntegrationTest.
- T-03 · onda 1 — Escopo aceito: FakeCashSessionRepository no RED.
- T-03 · onda 1 — 1205 do recebível virou bloqueante [ruling]; corrigido em ca64082.
- T-02 · onda 1 — terms_accepted_at sem ON UPDATE (85e3ec7).
- T-05 · onda 1 — Mapa de usos do download.php; exportações com 404 aceitas.
- T-04 · onda 1 — dompdf 3.1.6, php-jwt 7.2.1, audit limpo.
- T-13 · onda 3 — freeSlug com desempate e SAVEPOINT.
- T-14 · onda 3 — Pix que avança o período usa max(created, last_event_created).
- T-15 · onda 3 — ALWAYS_ALLOWED legacy/grace/full.
- T-17/T-24 · onda 4 — Retomada sem perda depois da interrupção.
- T-20 · onda 4 — Escopo aceito: entryFor e cache busters (bc0b08f).
- T-19 · onda 4 — Página 403 autônoma (a84b76c).
- T-19 · onda 4 — Fail-open na falha de leitura da assinatura, com cache de 60 s.
- T-24 · onda 4 — Escopo aceito: UserMessageTest 92→99.
- T-17 · onda 4 — attempts sobe 2 por entrega.
- T-18 · onda 5 — Travamentos de lote como bloqueante [ruling]; PI duplicado corrigido (52ca85a).
- T-18 · onda 5 — Escopo autorizado fora dos Arquivos prováveis; guarda PHP_SAPI.
- T-18 · onda 5 — E-mail do Pix com 3 tentativas, sem retentativa entre rodadas.
- T-22 · onda 6 — Fumaça no dev pelo orquestrador, com autorização direta do usuário.
- T-22 · onda 6 — Limpeza do centralvet_test e reaplicação dupla.
- T-22 · onda 6 — Reparo do .git e recommit 3bb0850.
- T-08 · onda 6 — Correções pós-onda aceitas sem fix loop.
- T-19 · onda 6 — Fail-open vigente e comunicado ao usuário.
- plano · onda 7 — Correção da revisão final em duas ondas (7: T-26..T-30; 8: T-31); BASE fbe280f.
- plano · onda 7 — Estorno automático pelo reconciliador, retomável por passo, com Idempotency-Key cancel-/refund-<public_id>.
- plano · onda 7 — A recusa do checkout considera tomado o login ou o pedido paid/provisioned/provisioning_failed, com mensagem única.
- plano · onda 7 — "Evento nosso" = traz a marca do Central Vet; sem a marca e sem registro local → ignored.
- plano · onda 7 — A mensagem de tenant reaproveita a chave existente de translations.json.
- plano · onda 7 — A linha solta antiga de notes.md:92 foi removida.
- T-31 · onda 7 — Cancelamento e estorno automáticos com alerta ao operador (resposta do usuário).
- plano · onda 7 — Correção dividida em 7 e 8 porque a T-31 consome a T-26.
- T-26 · onda 7 — MySQL e Redis subidos com docker compose up -d.
- T-30 · onda 7 — Relatório staged incluído no chore(tasks).
- T-31 · onda 8 — Cancelamento e estorno automáticos pelo reconciliador, conforme a decisão do usuário.

## Achados
- [sugestão] Isolamento intra-clínica (rodada 1, não tratado): todo membro convidado recebe o grupo do plano, que contém ClinicUserForm/ClinicUnitForm. Não é vazamento A×B. → src/app/Core/Tenancy/ClinicTeamService.php:86
- [sugestão] As chamadas ao Stripe (GET/DELETE da subscription, GET da sessão e da fatura, POST /v1/refunds) rodam dentro da transação que segura `billing_order` FOR UPDATE. Um Stripe lento prende a linha do pedido até o timeout do cURL. É o mesmo padrão do PixInvoiceIssuer, e o webhook desse pedido já sai `ignored`. → src/app/Core/Billing/LoginConflictRefundService.php:55-90

## Verificado (com evidência)
- SUITE completo no container: `Total: 2307, Passed: 2307, Failed: 0, Skipped: 0`.
- `grep -c "^BILLING_OPERATOR_EMAIL=$" .env.example` = 1; `grep -c CHECKOUT_RATE_LIMIT_ .env.example` = 2; `grep StripeClientInterface TenantProvisioningService.php` sem saída; `git grep` de segredos sem saída; `git diff --stat base...HEAD -- src/lib/adianti src/engine.php src/index.php src/init.php` vazio.
- 0018: as 3 colunas são NULL, o CHECK ganha `refunded` e o `.verify.sql` só tem SELECT. Não apliquei nada; as contagens antes e depois estão em reports/T-26.md e não foram reproduzidas aqui.
- T-28: `marked()` só ignora o evento sem a marca; o fora de ordem nosso continua lançando (BillingEventProcessor.php:87-92,311-316).
- CLAUDE.md: nenhuma linha de CSS/HTML nova com `text-align` em fbe280f..HEAD.

## Decisões que tomei
- Tratei o bloqueante da rodada 1 como resolvido, e não como pendência, porque os dois caminhos que ele pedia (a e b) estão no código com teste em PASS.
- Não promovi nenhuma pendência nova. As três citadas pelo orquestrador não estão agravadas: o cartão sem subscription é inalcançável, o LIMIT 50 exige falhas permanentes em massa e o login_conflict no provisionamento só aparece numa corrida com o login liberado.
- Acrescentei a rodada 2 sem sobrescrever a rodada 1, a pedido do orquestrador.
