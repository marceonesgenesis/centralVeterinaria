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
