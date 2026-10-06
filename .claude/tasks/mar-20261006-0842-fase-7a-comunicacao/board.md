# Board — mar-20261006-0842-fase-7a-comunicacao

Log append-only de fatos que afetam outras tasks desta execução: contrato divergente, símbolo renomeado, arquivo compartilhado alterado, decisão que outra task precisa conhecer. Uma linha por fato, acrescentada por append com heredoc (abaixo; o delimitador entre aspas aceita qualquer caractere no fato); nunca edite ou remova linhas. Leia antes de começar uma task e antes de usar cada `Consome`. O fechador consolida as linhas em `notes.md § Descobertas`.

Formato: `- [T-NN] <fato>`

Append:

```bash
cat >> "/var/www/html/centralvet/.claude/tasks/mar-20261006-0842-fase-7a-comunicacao/board.md" <<'EOF'
- [T-NN] <fato>
EOF
```
- [T-04] Programas RBAC prontos (seed + sql/T-04-programs*.sql, commit 3bf14d8): controllers MessageTemplateList, MessageTemplateForm, CommunicationMessageList, CommunicationMessageView, CommunicationComposeForm, TutorCommunicationForm, PendingCenter; aplicação no banco pendente do bloqueio Onda 1→2.
- [T-03] i18n-domínio: Invalid deep-link parameter <chave> (sufixo variável: nome da chave)
- [T-03] i18n-domínio: Invalid deep-link parameter key
- [T-03] i18n-domínio: Invalid deep-link class
- [T-03] i18n-domínio: Invalid pending item type
- [T-03] i18n-domínio: Invalid pending item priority
- [T-03] PendingItem valida tipo e classe de deep-link no construtor (InvalidArgumentException); deepLinkUrl aceita int > 0, string só de dígitos sem zero à esquerda, data Y-m-d válida ou 'administrations', na ordem do array; PendingItem::DEEP_LINK_CLASSES pública; ReminderCandidate::__debugInfo omite e-mail e telefone
- [T-05] Provedores prontos em `CentralVet\Communication` (52ba96d): `channel()` = `email` em `LogEmailProvider` e `SmtpEmailProvider`; `deliver` do log devolve null; SMTP devolve `getLastMessageID()` ou null. Códigos de `MessageDeliveryFailed`: `smtp_connect`, `smtp_auth`, `smtp_recipient_rejected`, `smtp_error` (sem previous encadeado).
- [T-05] i18n-domínio: Invalid phone number for WhatsApp
- [T-05] i18n-domínio: Invalid SMTP encryption
- [T-05] i18n-domínio: Unknown communication e-mail driver
- [T-05] i18n-domínio: Message delivery failed: <code>
- [T-05] `.env.example` e `docker-compose.yml` (`x-php-service.environment`) ganharam as 12 variáveis da 7A, logo depois de `QUEUE_BACKOFF_SECONDS`. Rebuild/restart do worker/app fica com o orquestrador.
- [T-02] Contratos: MessageTemplateRepositoryInterface e OutboundMessageRepositoryInterface estendem TenantRepositoryInterface (fakes/PDO implementam também tenantId() e remove()); CommunicationPreferenceRepositoryInterface e AppointmentFollowupRepositoryInterface NÃO estendem (só findForTutor/upsert e link/isFollowup), pois não têm findById/save no contrato.
- [T-02] Símbolos extras disponíveis: reconstitute(array $row) + assignId(int) + tenantId() em CommunicationPreference, MessageTemplate e OutboundMessage; CommunicationChannel::assertValid, MessagePurpose::assertValid/assertValidLegalBasis/legalBases; MessageTemplate::STATUS_ACTIVE/STATUS_INACTIVE; OutboundMessage::SOURCE_APPOINTMENT/SOURCE_VACCINATION/SOURCE_RECEIVABLE; getters extras de OutboundMessage (provider, providerMessageId, claimedAt, cancelledAt, manualSentBySystemUserId, cancelledBySystemUserId, createdBySystemUserId, updatedAt). OutboundMessage::compose ignora assunto vazio no WhatsApp (vira null) e exige source_type e source_id juntos. MessageTemplateRenderer::render deixa intacto {{token}} fora da lista.
- [T-02] i18n-domínio: Tutor <id> has not opted in to <channel> messages
- [T-02] i18n-domínio: Tutor <id> has opted out of <channel> messages
- [T-02] i18n-domínio: Unknown placeholder "<nome>" in template
- [T-02] i18n-domínio: subject is required for email templates
- [T-02] i18n-domínio: subject must be at most 190 characters
- [T-02] i18n-domínio: body must be between 1 and 2000 characters
- [T-02] i18n-domínio: name must be between 1 and 120 characters
- [T-02] i18n-domínio: Unknown communication channel "<canal>"
- [T-02] i18n-domínio: Unknown message purpose "<finalidade>"
- [T-02] i18n-domínio: Unknown legal basis "<base>"
- [T-02] i18n-domínio: Unknown consent source "<origem>"
- [T-02] i18n-domínio: Unknown communication preference status "<status>"
- [T-02] i18n-domínio: recipient must be between 1 and 190 characters
- [T-02] i18n-domínio: subject must be between 1 and 190 characters for email messages
- [T-02] i18n-domínio: body must not be empty
- [T-02] i18n-domínio: source_type and a positive source_id must be given together
- [T-01] 0012 commitada (0b40507): 4 tabelas, 18 CHECKs, 18 FKs e 4 UNIQUEs (não 3 como diz o critério; a Interface lista 4, inclusive appointment_followup_appointment_uq); colunas e nomes exatamente como na Interface da T-01.
- [T-03] Correção 1: PendingItem::DEEP_LINK_KEYS (pública) fecha as chaves por classe — ExamResultForm [exam_request_id, encounter_id], AgendaView [date], VaccinationCardView [patient_id], HospitalizationView [id, tab], CommunicationMessageView [id], PaymentForm [receivable_id]; tipo do valor fixo por chave (ids inteiro > 0, date Y-m-d, tab = administrations). Chave fora da lista lança `Invalid deep-link parameter <chave>` (mensagem já registrada). T-08 deve montar o deep-link só com essas chaves.
