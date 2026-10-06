# Revisão final
Rodada 2 — branch feat/fase-7a-comunicacao @ adac9e3 contra feat/fase-6b-cirurgia @ bf2178d. Desde a rodada 1 (b735de1): T-24 (2b6927c RED + fdc0d02), T-25 (fcb802c RED + 224a35d), chore bec04e8/5331320 e adac9e3 (style, pedido direto do usuário, fora do plano). Sem mudança em src/app/database desde b735de1 (`git diff b735de1..HEAD -- src/app/database` vazio). SUITE não reexecutada: adac9e3 só toca CSS/docs; vale o gate da onda 7 em bec04e8 (968/968, reviews/T-25.md § Gate onda 7).
## Triagem
- [aberta] Envio SMTP real só com credenciais do usuário → fora dos gates (driver log)
- [aberta] Herdadas da 6B (QueueEntryView::onAdvance; admissões simultâneas) → fora do escopo 7A
- [aberta] T-01: dedupe_key NULL-ável sem CHECK por origin; par source_type/source_id sem CHECK; retenção; índice da varredura; verify sem índices não únicos → migration 0012 inalterada
- [aberta] T-02: compose não amarra legal_basis a legalBasisFor; TOKEN_PATTERN → MessageService.php:90; 0012:183
- [resolvida] T-03: __debugInfo → RED eb4832d (PendingItem::DEEP_LINK_KEYS)
- [aberta] T-05: wa.me com telefone/corpo na URL (por desenho); SMTP none com credenciais; FROM inválido; #[\SensitiveParameter]; testes CRLF/STARTTLS → SmtpConfig/SmtpEmailProvider
- [aberta] T-06: borda de 10 min e insertIfNew do fake → tests/Support/FakeOutboundMessageRepository.php
- [aberta] T-07: fuso sessão MySQL × PHP; fábrica sem teste de atributos → PdoConnectionFactory.php
- [aberta] T-08: fuso; appointmentsBetween sem índice (tenant_id, scheduled_at); failed sem failed_at → unidade com fixture (CommunicationReadModelIntegrationTest.php:44-81), restante segue
- [resolvida] Onda 2 (validador): COUNT antes/depois → sql/T-23-cleanup.sql:18
- [resolvida] Onda 3 (validador): grep de dado pessoal e contagens → gates das ondas 4, 5, 6 e 7
- [aberta] T-09: um ativo por finalidade/canal sem lock/UNIQUE → MessageTemplateService.php
- [aberta] T-10: compose/renderTemplate aceitam template inativo; sem teste de resourceUnitId persistido → MessageService.php
- [aberta] T-11: candidato envenenado aborta a geração do tenant; sem paginação → ReminderGenerationService.php
- [aberta] T-12: retorno de markSent ignorado; cancel do worker exige só queued → MessageDeliveryService.php:102 (não agravada: cancelQueuedForTutor exclui claimed_at não nulo, OutboundMessageRepository.php:240)
- [aberta] T-13: dois now e limite 200 sem aviso → PendingCenterService.php
- [resolvida] T-14: ligação em onScheduleFollowUp → roteiro A (sql/T-23-cleanup.sql:35)
- [aberta] T-15: tick síncrono × heartbeat; varredura republica e-mail em backoff → OutboundMessageRepository.php listStaleQueuedEmailIds
- [aberta] T-16: formulário novo sempre ativo; status no THidden → MessageTemplateForm.php
- [aberta] T-17: filtros sem teste; ações de estado por GET sem CSRF (dívida transversal)
- [aberta] T-18: onChangeChannel/onChangeTemplate só capturam Exception; carga engole permissão negada; error_log com getMessage() → TutorCommunicationForm.php:217
- [resolvida] T-19: assunto como código sem _t → 1905709 (T-21)
- [aberta] T-19: filtro "Só meus" e contagem dos cards sem teste de tela → PendingCenterIntegrationTest
- [aberta] T-20: teste das ações do TutorForm procura string no fonte → CommunicationNavigationIntegrationTest
- [resolvida] Onda 4 (validador): agendador no container, "Resolver" e ficha na Unit A → ruling T-15 onda 5 e gate T-23
- [resolvida] Onda 4: i18n e colisão "Open" → fe67d5c + 1905709
- [aberta] T-22: retenção/expurgo de recipient e body_text "não definidos" → docs/runbooks/comunicacao.md
- [resolvida] T-22: variáveis DB/Redis do cron → docs/runbooks/shared-hosting-mysql57.md:253
- [resolvida] Onda 5 (validador): sem pendências de escopo
- [aberta] Onda 6/7: sql/T-23-cleanup.sql (agora com message #42 e template #7, 2.5 confere '1,...,7' e '...,36,42') preparado e não executado → depende de aprovação SQL do usuário
- [aberta] T-23: oráculo de existência; sandbox attempt_count 0; chip do AppointmentForm (pré-existente) → MessageService requireMessage
- [aberta] T-23: itens não rodados no E2E (negação da Central, base legal vacina/cobrança, `<script>` na Central) → só cobertura automatizada (o E2E da onda 7 cobriu só o compose)
- [resolvida] T-23: validação cruzada sem log/contagens → gate da onda 7: grep logs app/worker = 0 e contagens antes→depois (reviews/T-25.md § Gate onda 7)
- [aberta] T-23: toast por base64; cabeçalho do SQL de limpeza; template F7A fora do prefixo → mitigado pela seção 2.5
- [aberta] T-24: RED pela ausência do gancho; LEFT JOIN com unit_name vazio e Assert::notNull sempre verdadeiro; `?array`/`=== null` morto → ReminderSourceQuery.php:216; CommunicationReadModelIntegrationTest.php:267; CommunicationComposeForm.php:180-183,205-227
- [aberta] T-25: lote sem teste de outro tutor; `$messages` opcional; autor inconsistente entre unidades; loadData cancela sob ACTION_READ num GET e corrida perdida cai no catch genérico → CommunicationRepositoryIntegrationTest.php:224-256; CommunicationPreferenceService.php:45,95; CommunicationMessageView.php:263-311
- [aberta] Revisão final rodada 1 (sugestões): compose manual de legítimo interesse; sha256 truncado no log sandbox; lock em sys_get_temp_dir() → ver § Achados
- [resolvida] Onda 7 (validador): PYTEST57 não rodado → migration 0012 inalterada desde b735de1, onde PYTEST57 deu OK (rodada 1); onda 7 só escreve communication_message
## Rulings
- plano · onda 0 — Escopo 7A = Comunicação (PRD §8.20) + Central de Pendências (§8.23), MVP; documentos/PDF e workflows na 7B
- plano · onda 0 — Canais: SMTP por env (.env.example/compose), WhatsApp wa.me com status manual, MessageChannelProviderInterface, driver log padrão
- plano · onda 0 — Status queued/sent/failed/manual + cancelled
- plano · onda 0 — Central de Pendências sem tabela própria (8 fontes por consulta)
- plano · onda 0 — Idempotência por dedupe_key UNIQUE por tenant com tratamento de 1062 (sem INSERT IGNORE); claim condicional + UPDATE ... WHERE status com rowCount
- plano · onda 0 — Publicação só após commit + varredura de e-mails queued presos > 10 min; payload só type + message_id
- plano · onda 0 — Agendador sem cron novo: tick no worker + bin/communication-scheduler.php; D-1 em tenant.timezone
- plano · onda 0 — Worker com PDO por job/tick e TenantContext com COMMUNICATION_SYSTEM_USER_ID; services do worker não autorizam
- plano · onda 0 — Preferência por canal em communication_preference com origem e autor; auditoria sem contato
- plano · onda 0 — Retorno via appointment_followup gravada por EncounterView::onScheduleFollowUp; cirurgia por surgery.followup_appointment_id; Appointment*/SurgeryCompletionService intocados
- plano · onda 0 — Cobrança por idade do recebível (7 dias) e unidade de encounter_account
- plano · onda 0 — Templates por tenant, placeholders fechados, um ativo por finalidade/canal, defaults pt-BR, texto puro
- plano · onda 0 — Nomes communication_message e controllers Communication* em app/control/clinic
- plano · onda 0 — Branch feat/fase-7a-comunicacao, base feat/fase-6b-cirurgia @ bf2178d
- plano · onda 0 — Gates econômicos por onda (Ondas 1-3 LINT+SUITE; 4 rebuild+smoke; 5 fetch pt; 6 E2E em dois disparos)
- plano · onda 0 — Usuário (1) RBAC: 7 telas para grupos 1, 2, 4, 5; grupo 3 nada; 28 concessões
- plano · onda 0 — Usuário (2) LGPD: confirmação/retorno por legítimo interesse, vacina/cobrança por opt-in, opt-out sempre respeitado; legal_basis por mensagem; custom/document_ready = consent e confirmação/retorno manuais = legitimate_interest (decisão do planejador)
- T-01 · onda 1 — 4 UNIQUEs na 0012 (Interface define 4; critério dizia 3)
- T-03 · onda 1 — Deep-link com allowlist fechada PendingItem::DEEP_LINK_KEYS (eb4832d + 7bd7b70)
- T-02 · onda 1 — CommunicationPreferenceRepositoryInterface e AppointmentFollowupRepositoryInterface não estendem TenantRepositoryInterface
- T-07 · onda 2 — Quatro diferenças PDO × Fake aceitas (save só insere, link valida tenant, varredura por claimed_at, link idempotente só no fake)
- T-08 · onda 2 — Consultas sem AbstractTenantRepository (TenantQuery::forTenant por SQL); instantes em date_default_timezone_get()
- T-15 · onda 4 — Etapas services/generate/sweep/publish isoladas por tenant (4721664 + 80a83bc); re-revisão aprovada
- T-19 · onda 4 — Central vazia no gate por unidade ativa Unit B; medida na T-23
- T-20 · onda 4 — Sem xmllint; parse do XML coberto por teste
- T-20 · onda 4 — Relay para T-18: TutorForm liga por href; telas leem tutor_id no construtor
- orquestrador · onda 4 — Login admin refeito pelo orquestrador
- T-21 · onda 5 — Correção de textos visíveis em controllers autorizada (fe67d5c + 1905709)
- T-22 · onda 5 — Worker one-shot --once/--max-seconds/--max-jobs com flock (08471bd + 244821e, Task: T-15; QueueWorkerLoop.php autorizado) e cron nos runbooks (dbdfab6)
- T-15 · onda 5 — Agendador corrigido rodado no container
- T-10 · onda 6 — SenderNamesQuery(+Interface/teste) fora dos Arquivos prováveis aceito; compose recusa marcador sem valor
- T-18 · onda 6 — feaea9b sem RED próprio aceito
- T-23 · onda 6 — Marcadores sem valor exibidos como {{nome}} e envio recusado até troca
- T-23 · onda 6 — Profissional "vazio" no AppointmentForm não é defeito
- T-23 · onda 6 — Itens não rodados no E2E aceitos como pendência (cobertos por testes)
- T-24 · onda 7 — LEFT JOIN de system_unit com condição de tenant (não INNER) em ReminderSourceQuery aceito; unidade de outro tenant vira unit_name vazio
- T-23 · onda 7 — SQL de limpeza ampliado para message #42 e template #7 (bec04e8); segue não executado, depende de aprovação SQL do usuário
## Achados
- [bloqueante] XSS pelo nome do template no combo da composição (não visto na rodada 1): onChangeChannel faz TCombo::reload com `name — finalidade` (CommunicationComposeForm.php:156,447); TCombo::reload aplica só htmlspecialchars e põe o valor num literal JS entre aspas simples (src/lib/adianti/widget/form/TCombo.php:239,247), sem escapar `\`; tcombo_add_option concatena o valor em HTML e passa a `$()` do jQuery 3.7.1 (lib/adianti/include/components/tcombo/tcombo.js:48,51). O nome do template só tem limite de tamanho (MessageTemplate.php:221-225), então um nome `<img src=x onerror=alert(1)>`, gravado por quem tem MessageTemplateForm (grupos 1, 2, 4, 5), executa no navegador de quem troca o canal na composição. Reproduzido: htmlspecialchars do PHP + avaliação do literal no node → `<option value="7"><img src=x onerror=alert(1)> — Confirmação</option>`. Mesmo ator e severidade do bloqueante XSS da rodada 1 (enviar as opções como JSON com JSON_HEX_* e criar com `new Option(texto, valor)`, ou recusar `\` no nome; teste com `<` no nome)
- [sugestão] plano-mandou — Compose manual com finalidade de legítimo interesse e corpo livre sai sem opt-in → CommunicationComposeForm.php:99-100 + MessageService.php:90
- [sugestão] UPDATEs condicionais e findById de mensagem sem system_unit_id; isolamento por unidade depende de MessageService::requireMessage → OutboundMessageRepository.php:307
- [sugestão] AppointmentFollowupService::link sem AuthorizationRequest própria nem conferência de unidade appointment × encounter → AppointmentFollowupService.php
- [sugestão] Construtores leem tutor/pacientes sem decide() → CommunicationComposeForm.php:338,360; TutorCommunicationForm.php:259
- [sugestão] Sandbox log grava sha256 truncado do e-mail → src/app/Core/Communication/LogEmailProvider.php:34
- [sugestão] Lock do --once em sys_get_temp_dir() compartilhado → src/bin/worker.php:110
- [sugestão] PDOException fora do try vai verbatim ao dead-letter → QueueWorkerLoop.php:153-158
- [sugestão] error_log com getMessage() nos controllers → CommunicationMessageList.php:84; CommunicationMessageView.php:179,229,286; TutorCommunicationForm.php:217
- [sugestão] adac9e3: o seletor global `label { text-align:left !important }` também pega `label.btn` dos grupos com setUseButton (ex.: SystemScheduleForm.php:46,89) e todo TLabel, que renderiza `<label>` (TLabel.php:65), e passa por cima de alinhamento inline sem !important; `th.tdatagrid_col` alinha à esquerda cabeçalhos de colunas 'center' (ex.: SystemRequestLogList.php:74-82). Nenhum TLabel com centro/direita encontrado no app. Sem efeito em acessibilidade (nome acessível, foco, contraste e ordem inalterados; texto à esquerda atende WCAG 1.4.8). Preferir limitar a `.control-label, .col-form-label, .form-label` e excluir `.btn` → src/app/templates/adminbs5/custom.css:628-638
## Rodada 2
### Bloqueantes da rodada 1
- resolvido: XSS em contexto de script no onChangeTemplate → fdc0d02: sendTemplateData com json_encode JSON_HEX_TAG|AMP|APOS|QUOT (CommunicationComposeForm.php:236-251); conferido `php -r json_encode("Olá </script><img ...>   \\u003c", flags)` → `"Olá <\/script><img ...>   \\u003c"` (sem `<`, e `<` literal continua texto); sink tform_send_data → `.val()` para TText/TCombo (tform.js:41-90); teste discrimina (reviews/T-24.md); E2E onda 7 sem dialog
- resolvido: opt-out não barrava o WhatsApp manual → 224a35d: whatsAppLink e markManualSent reconferem permitsSending e cancelam com UPDATE condicional (MessageService.php:213-220,262,317-337); record com opt-out cancela em lote via tenantQuery + tutor + canal + queued + claimed_at IS NULL (CommunicationPreferenceService.php:94-102; OutboundMessageRepository.php:230-251); único gerador de wa.me é MessageService::whatsAppLink (grep); único chamador de markManualSent/whatsAppLink é CommunicationMessageView, que commita o cancelamento (:218-223, :263-287); PendingItemQuery só lista queued; E2E onda 7 (msg #42) conferido
- ReminderSourceQuery (sugestão da rodada 1): resolvida em fdc0d02, com `su.tenant_id` nos 3 JOINs (ReminderSourceQuery.php:72,125,176)
- Regressão: nenhuma encontrada. MySQL 5.7: UPDATE em lote e LEFT JOIN compatíveis; sem DDL novo
### Pendências
- Bloqueante novo: XSS pelo nome do template no TCombo::reload (§ Achados)
- Abertas: 27 itens de § Triagem (sugestões, nenhuma promovida); SQL de limpeza segue sem execução
### Decisões que tomei
- adac9e3 avaliado só quanto a layout e acessibilidade, não contra o Mapa de arquivos (pedido direto do usuário). custom.css consta em framework_hashes.php:402, mas o hash já divergia desde 9efef4e: sem regressão
- TCombo::reload classificado como bloqueante e não plano-mandou: tasks.md e plan.md não citam TCombo::reload; mesmo vetor e ator do XSS da rodada 1
- Sugestões das re-revisões T-24/T-25 não reabertas como achados; ficam na Triagem
- PYTEST57 da onda 7 dado como resolvido porque a migration não mudou desde b735de1; SUITE não reexecutada (adac9e3 sem PHP)
### Sugestões
- Ver § Achados (9 sugestões, 1 nova sobre adac9e3)
