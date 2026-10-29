# T-23 — fix loop, rodada 1 (bloqueantes do gate E2E, roteiro A)

Status: concluído

## Commits
RED (falharam pelos motivos certos, 11 FAIL: `Total: 954, Passed: 943, Failed: 11`):
- e6fc64a `Task: T-16 (RED)` — MessageTemplateScreensIntegrationTest: o próprio `<a>` de Editar/Desativar leva `cv-touch-target`.
- 0686df5 `Task: T-17 (RED)` — CommunicationMessageScreensIntegrationTest::testStatusChangeReloadsTheDetailRightAway.
- 85e5c65 `Task: T-10 (RED)` — MessageServiceTest (3 casos novos + 1 ajustado) e SenderNamesQueryIntegrationTest (3 casos).
- eef05aa `Task: T-21 (RED)` — UserMessageTest: STATIC 61→62 e a nova mensagem catalogada.

Correções:
- 9f741e0 `Task: T-16` — MessageTemplateList.
- 08b48ad `Task: T-17` — CommunicationMessageView (+ ajuste da asserção do toast no teste: o TToast manda o texto em base64 via `__adianti_show_toast64`, a asserção original procurava o texto puro).
- 87cd91c `Task: T-10` — MessageService, SenderNamesQueryInterface (novo), SenderNamesQuery (novo).
- feaea9b `Task: T-18` — CommunicationComposeForm liga o SenderNamesQuery.
- 67a3b46 `Task: T-21` — UserMessage::STATIC e translations.json (1267→1268, ordem por `en` mantida, json.tool 0).

## 1. Alvo de toque em MessageTemplateList
Causa: a ação do TDataGrid põe a classe (`cv-touch-target`) só num `<span>` interno. O `<a>`, que é o elemento inline, ficava com a altura da linha (19 px). Correção: as ações viraram uma coluna com links próprios, no padrão do CommunicationMessageList: `<a class="btn btn-sm btn-outline-secondary cv-touch-target" generator="adianti">`. Editar → `MessageTemplateForm&method=onEdit&id=`; Ativar/Desativar → `onToggle&active=1|0&static=1`. A URL leva só ids. As condições de exibição são as mesmas (`canActivate`/`canDeactivate`).

## 2. Ficha da mensagem após "Sim"
Causa: `runChange`/`onRetry` mostravam `TMessage('info', ..., reloadAction)`, e a ficha só recarregava no OK. Correção: `reloadAfterChange($id, $success)` = `TToast::show('success', ...)` + `__adianti_goto_page('index.php?class=CommunicationMessageView&id=<id>')`, o padrão de SurgeryMaterialForm e HospitalizationEventForm. Vale para Marcar como enviado, Descartar e Reenviar. Os erros continuam em TMessage.

## 3. Marcadores na mensagem manual (decisão)
- `{{unit_name}}` vem da unidade ativa (`system_unit.name`) e `{{clinic_name}}` do tenant da sessão (`COALESCE(NULLIF(trade_name,''), legal_name)`). É a mesma regra do ReminderSourceQuery. A consulta só faz leitura: `SenderNamesQueryInterface::namesForUnit(int $unitId)` e `Persistence\SenderNamesQuery` (TenantContext + PDO). O MessageService recebe a consulta como último argumento opcional do construtor (`?SenderNamesQueryInterface $senderNames = null`), então o contrato antigo continua compatível. Só o CommunicationComposeForm a passa.
- **Decisão sobre data/hora do agendamento e demais marcadores sem fonte:** a mensagem manual não tem agendamento, vacina nem recebível, e o compose não escolhe a fonte. Por isso `renderTemplate` deixa qualquer marcador sem valor **visível como `{{nome}}`** no texto, para o atendente trocá-lo pelo texto real. Isso cobre `appointment_date`, `appointment_time`, `vaccine_name`, `due_date`, `amount_due` e também `patient_name` sem paciente e um nome vazio. Em seguida, `MessageService::compose` **recusa** um corpo (ou o assunto do e-mail) que ainda tenha um marcador da lista fechada, com a mensagem `Replace the template placeholders before sending the message` → pt "Troque os marcadores {{...}} do modelo pelo texto antes de enviar a mensagem". Nada sai com lacuna ("aqui é a ."). Os templates continuam reutilizáveis na automação, que preenche tudo pela fonte. Não foi preciso criar seletor de agendamento no compose, o que ficaria fora do MVP do plano. `{{token}}` fora da lista continua sendo texto livre. A automação (ReminderGenerationService) não passa por `MessageService::compose` e não muda.

## Investigação (só leitura): profissional vazio no AppointmentForm
- Não falta dado. Há 1 usuário, `system_users.id = 1` (Administrator, ativo), em `tenant_user` do tenant 1 e em `system_user_unit` das unidades 1 e 2. Os 15 agendamentos existentes usam `professional_system_user_id = 1`, e o tenant 1 tem 14 serviços (SELECTs só leitura).
- Causa provável: o campo é um `TDBUniqueSearch` (AppointmentForm.php:81). Ele herda `minLength = 3` do `TDBMultiSearch` (lib/adianti/widget/form/TMultiSearch.php:54) e por isso não oferece nenhuma opção antes de 3 caracteres digitados. Clicar no campo não lista ninguém. É comportamento de busca por digitação (UX), não defeito de dado ou de regra. Não verifiquei no navegador, porque Playwright é só do validador. Observação: a busca não filtra por tenant nem por unidade, uma exceção documentada no próprio código. O AppointmentService valida `isActiveMember`.
- Como criar um agendamento de teste pela UI: Agenda (AgendaView) → "Novo agendamento". No campo Paciente, digitar "F7A" e escolher o paciente F7A teste. No Serviço, escolher um da lista. No Profissional, **digitar "Adm"** e escolher Administrator. Em Data/hora, informar `dd/mm/aaaa hh:mm` de amanhã, para a confirmação D-1. Retorno: num atendimento F7A teste, usar a ação de agendar retorno do EncounterView (`onScheduleFollowUp`), que grava `appointment_followup`.

## Evidência
- SUITE final (comando padrão da SUITE): `Total: 954, Passed: 954, Failed: 0, Skipped: 0` (`MessageServiceTest`, `SenderNamesQueryIntegrationTest`, `MessageTemplateScreensIntegrationTest`, `CommunicationMessageScreensIntegrationTest` e `UserMessageTest` com PASS).
- LINT: `No syntax errors detected` nos 12 PHP tocados.
- `python3 -m json.tool src/app/config/translations.json` → 0; 1268 entradas, ordenadas.
- Rebuild: `docker compose build app worker && docker compose up -d app worker && docker compose restart nginx` → app/worker/nginx `healthy`. Na imagem: `grep -c reloadAfterChange` = 3, `actionLinks` = 2, `class_exists(SenderNamesQuery)` = true; `curl http://127.0.0.1:8081/` → 200.
- Nenhum SQL de escrita, nenhuma escrita no `.env`, sem push.

## Pendências
- Revalidar no navegador (validador): altura dos links em 820 px, ficha recarregando após "Sim" e compose com template de confirmação, que agora mostra os `{{...}}` e recusa até o atendente trocá-los.
- Sugestão: o compose poderia ter uma linha de ajuda sobre os marcadores restantes (não incluída, para não abrir chave nova de tela).
- Sugestão: BedList e SurgeryRoomList usam o mesmo padrão de ação do TDataGrid (`<span>` com a classe dentro do `<a>` inline) e devem ter o mesmo alvo de 19 px no `<a>`. Ficaram fora do escopo desta rodada.

## Rodada 2 (achado do revisor: SenderNamesQuery sem filtro de tenant)
- Causa: `SenderNamesQuery` lia `system_unit` só pelo id. O docblock dizia "no tenant column", mas `system_unit.tenant_id` existe (bigint unsigned NOT NULL, FK `system_unit_tenant_fk`, conferido por `SHOW CREATE TABLE`, só leitura).
- 3ba0471 `Task: T-10 (RED)`: SenderNamesQueryIntegrationTest. As fixtures agora criam a unidade no tenant descartável (`system_unit.id` sem AUTO_INCREMENT: MAX+1 dentro da transação, rollback no tearDown). O caso novo `testUnitOfAnotherTenantGivesNullUnitName` espera `unit_name` null para uma unidade de outro tenant. RED: `Total: 955, Passed: 954, Failed: 1` (só esse caso, "Failed asserting that array").
- b544ca8 `Task: T-10`: a consulta da unidade usa `TenantQuery::forTenant($this->context->tenantId(), 'su')->andEquals('id', $unitId, 'su')` (padrão do ReminderSourceQuery). O docblock foi corrigido.
- SUITE inteira: `Total: 955, Passed: 955, Failed: 0, Skipped: 0`. LINT: `No syntax errors detected in app/Core/Persistence/SenderNamesQuery.php`.
- Rebuild `docker compose build app worker && docker compose up -d app worker && docker compose restart nginx` → exit 0, app/worker/nginx healthy. Na imagem: `grep -c TenantQuery::forTenant` = 1. `curl http://127.0.0.1:8081/` → 200.
- As sugestões da rodada 1 (asserção do toast por base64 do texto, registro em notes.md) e o bloqueante do roteiro B (gate do validador) não fazem parte deste pedido.
