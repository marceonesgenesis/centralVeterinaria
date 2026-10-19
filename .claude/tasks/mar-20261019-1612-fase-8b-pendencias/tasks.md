# Tasks

Origem de cada task: `mar-20261015-2050-fase-8b-copiloto-mcp/reviews/final.md` (Revisão final — Rodada 2) e os `reviews/T-<id>.md` daquela pasta. "8B T-<id>" abaixo é a task do plano anterior; os IDs deste plano são novos.

Comandos usados em Validação (rodar de `/var/www/html/centralvet`):
- LINT = `docker compose run --rm --no-deps -T -v /var/www/html/centralvet/src:/var/www/html/src:ro app php -l <arquivo relativo a src/>` → `No syntax errors detected` (baseline `baseline/php-lint.txt`, 0 linhas).
- SUITE = `docker compose run --rm --no-deps -T -v /var/www/html/centralvet/src:/var/www/html/src:ro app php tests/run.php 2>&1 | /usr/bin/grep -E '<Classe>|Failed:'` (o runner imprime `  PASS  <Classe>::<método>` / `  FAIL  ...` e `Total: N, Passed: N, Failed: N, Skipped: N`).

| ID | Camada | Descrição | Dependências | Paralelizável | Complexidade | Agente | Status |
|---|---|---|---|---|---|---|---|
| T-01 | backend | AiInteraction: recordAnswer só copiloto, recordSuggestion só 8A; decide inválido em answered testado | — | sim | simples | Platão | [x] |
| T-02 | backend | ToolRegistry rejeita nome duplicado e expõe sameContext; validador sem ecoar chave extra; teste de allowlist exato | — | sim | média | Platão | [x] |
| T-03 | backend | AnthropicMessagesGateway: `{}` só no input de tool_use e em input_schema.properties; teste de messages sem tools | — | sim | média | Platão | [x] |
| T-04 | backend | AiConversationMessage::reconstitute descarta entrada inválida de tool_summary; teste com id reconstituído | — | sim | simples | Platão | [x] |
| T-05 | backend | buscar_paciente e obter_historico_atendimentos com truncated real (limit+1); histórico fora da unidade testado | — | sim | simples | Darwin | [x] |
| T-06 | backend | obter_prescricoes minimiza via/posologia/duracao; patientId() único em trait; fixtures de exame válidas | — | sim | média | Darwin | [x] |
| T-07 | qa | CopilotClinicalQueryIntegrationTest: unidades A e B do mesmo tenant | — | sim | simples | Darwin | [x] |
| T-08 | backend | consultar_agenda aceita profissional inativo com agendamentos; fronteiras de 90 dias e outro tenant testados | — | sim | média | Arquimedes | [x] |
| T-09 | backend | Estoque: lote vencido fora de validade/lote; escape LIKE e bordas de expiring testados | — | sim | média | Arquimedes | [x] |
| T-10 | backend | ListarDebitosTool no fuso da aplicação; paciente novo na unidade A testado | — | sim | simples | Arquimedes | [x] |
| T-11 | backend | AiCopilotAvailability::providerConfigured testável | — | sim | simples | Yoda | [x] |
| T-12 | backend | AiCopilotService loga falha do insert da interação no caminho de sucesso | — | sim | simples | Yoda | [x] |
| T-13 | qa | forContext monta as 5 ferramentas de gestão e as 7 clínicas (teste de wiring) | — | sim | simples | Yoda | [x] |
| T-14 | backend | Repositórios: insert confere unidade/usuário; mcp_tool_call.conversation_id do tenant; lacunas de teste | — | sim | média | Jaspion | [x] |
| T-15 | backend | Retenção: lote e teto injetáveis, updated_at × created_at testado, trava contra execução sobreposta | — | sim | média | Jaspion | [x] |
| T-16 | frontend | AiCopilotPanel: pergunta em branco, rascunho preservado na indisponibilidade, âncora antes de apagar, RBAC negado testado | — | sim | alta | Aang | [x] |
| T-17 | qa | CopilotTranslationsTest: toda entrada tem só en/pt | — | sim | simples | Levi | [x] |
| T-18 | qa | Flag ai_copilot_management gravada pelo FeatureFlagRepository real | — | sim | simples | Levi | [x] |
| T-19 | database | 0015 .verify.sql confere os 5 índices auxiliares de FK | — | sim | simples | Levi | [x] |
| T-20 | backend | ToolPolicy nega ToolContext de outro contexto; ToolResult exige excerptLengths ⊆ freeTextFields; docblock de knownNames | T-02, T-05, T-06 | sim | média | Spock | [ ] |
| T-21 | frontend | AiToolCallList: Motivo traduzido, negação e XSS testados | T-16 | sim | média | Hegel | [ ] |
| T-22 | frontend | Painel: foco vai para a resposta nova após o envio | T-16 | sim | média | Aang | [ ] |
| T-23 | frontend | AiCopilotPanel::hostLink único para PatientForm e EncounterView; docblock de resolveTenantContext | T-22 | sim | simples | Aang | [ ] |
| T-24 | docs | Runbooks: 0015 no 5.7, códigos ai_*, flag de gestão, débitos, ticket médio, retenção, seed, âncora RESTRICT, motivos | T-08, T-09, T-15, T-21 | sim | simples | Gandalf | [ ] |

## Detalhamento

### T-01 — AiInteraction: recordAnswer só copiloto, recordSuggestion só 8A; decide inválido em answered testado

**Camada:** backend
**Dependências:** nenhuma
**Paralelizável:** sim
**Complexidade:** simples
**Agente:** Platão

Origem: 8B T-03 ([aberta] na Rodada 2). Hoje `recordSuggestion` (AiInteraction.php:177-195) e `recordAnswer` (:202-221) só chamam `assertAwaitingProvider` (:455-460): uma interação 8A (`patient_summary`) pode terminar `answered` e uma do copiloto aceita `recordSuggestion` (gravaria texto, contra "copiloto sem texto"). `decide` (:249-281) lança 'Unknown AI decision' (:251) antes do guarda de `answered` (:255); a ordem fica, só falta teste. Consumidores: `AiAssistService.php:363` (recordSuggestion, finalidades 8A) e `AiCopilotService.php:498` (recordAnswer, copiloto).

**Arquivos prováveis**
- `src/app/Core/Domain/AiInteraction.php`
- `src/tests/Unit/AiDomainTest.php`

**Interface**
- Produz: `AiInteraction::recordAnswer(...)` lança `InvalidArgumentException('Only copilot interactions record an answer')` quando `!AiPurpose::isCopilot($this->purpose())`; `AiInteraction::recordSuggestion(...)` lança `InvalidArgumentException('Copilot interactions never record a suggestion')` quando `AiPurpose::isCopilot($this->purpose())`. Guardas antes de `assertAwaitingProvider`; assinaturas inalteradas.
- Consome: nada

**Teste RED**
- `src/tests/Unit/AiDomainTest.php` — `recordAnswer` numa interação `AiPurpose::PATIENT_SUMMARY` aguardando o provedor e `recordSuggestion` numa `AiPurpose::COPILOT_CLINICAL` lançam `InvalidArgumentException` (hoje passam) (comando: `docker compose run --rm --no-deps -T -v /var/www/html/centralvet/src:/var/www/html/src:ro app php tests/run.php 2>&1 | /usr/bin/grep -E 'AiDomainTest|Failed:'`)

**Critério de aceite**
- SUITE filtrada por `AiDomainTest` mostra `PASS` para os dois testes novos de guarda e para um teste novo em que `decide('bogus', ...)` numa interação `answered` lança `InvalidArgumentException` com 'Unknown AI decision'; `AiAssistServiceTest` e `AiCopilotServiceTest` seguem com 0 `FAIL`.

**Validação**
- SUITE com `/usr/bin/grep -E 'AiDomainTest|AiAssistServiceTest|AiCopilotServiceTest|Failed:'` (evidência: `PASS  ...AiDomainTest::` dos testes novos e nenhuma linha `FAIL` dessas classes)
- LINT de `app/Core/Domain/AiInteraction.php` (evidência: `No syntax errors detected`)

### T-02 — ToolRegistry rejeita nome duplicado e expõe sameContext; validador sem ecoar chave extra; teste de allowlist exato

**Camada:** backend
**Dependências:** nenhuma
**Paralelizável:** sim
**Complexidade:** média
**Agente:** Platão

Origem: 8B T-04 ([aberta]: chave extra ecoa o nome; nome duplicado ignorado em silêncio em `ToolRegistry::entry`, :131) e 8B T-25 (ToolPolicy não confere o ToolContext contra o contexto do registry — a T-20 consome o `sameContext` daqui). `JsonSchemaValidator.php:57` monta `$name . ': not allowed'` com a chave vinda do modelo; o único consumidor (`ToolPolicy.php:85`) só testa `!== []`. `ToolRegistryTest::testToolOutsideAllowlistIsNeverVisible` (:66) assere só `in_array`. `iterable $tools` no construtor fica (alargamento compatível, ver notes.md).

**Arquivos prováveis**
- `src/app/Core/Ai/Tools/ToolRegistry.php`
- `src/app/Core/Ai/Tools/JsonSchemaValidator.php`
- `src/tests/Unit/ToolRegistryTest.php`
- `src/tests/Unit/JsonSchemaValidatorTest.php`

**Interface**
- Produz: construtor de `ToolRegistry` lança `\LogicException('Duplicate tool name: <name>')` quando dois `AiTool` têm o mesmo `name()` (nome vem do código, não do modelo); `ToolRegistry::sameContext(ToolContext $context): bool` — `true` só se `$context->tenantId === TenantContext::tenantId()`, `$context->userId === TenantContext::userId()` e `$context->unitId === TenantContext::unitId()` do contexto do registry; erro de chave extra do `JsonSchemaValidator::validate` = `'unexpected property: not allowed'` (sem o nome, uma entrada por chave extra).
- Consome: nada

**Teste RED**
- `src/tests/Unit/ToolRegistryTest.php` — construir `ToolRegistry` com dois `FakeAiTool` de mesmo `name()` lança `LogicException`, e `sameContext` devolve `false` para `ToolContext` com outro `userId` (hoje o duplicado é aceito e o método não existe) (comando: `docker compose run --rm --no-deps -T -v /var/www/html/centralvet/src:/var/www/html/src:ro app php tests/run.php 2>&1 | /usr/bin/grep -E 'ToolRegistryTest|Failed:'`)

**Critério de aceite**
- SUITE: `PASS` nos testes novos de `ToolRegistryTest` (duplicado, `sameContext` igual/diferente) e de `JsonSchemaValidatorTest` (entrada `['123.456.789-09' => 1]` devolve `['unexpected property: not allowed']`, sem o texto `123.456.789-09`); `testToolOutsideAllowlistIsNeverVisible` passa a asserir `Assert::same(['buscar_paciente'], <nomes visíveis>)`; `ToolPolicyTest`, `ClinicalToolsetTest`/`ManagementToolsetTest` e `AiCopilotServiceTest` com 0 `FAIL`.

**Validação**
- SUITE com `/usr/bin/grep -E 'ToolRegistryTest|JsonSchemaValidatorTest|ToolPolicyTest|ToolsetTest|AiCopilotServiceTest|Failed:'` (evidência: testes novos em `PASS`, nenhuma linha `FAIL`)
- LINT de `app/Core/Ai/Tools/ToolRegistry.php` e `app/Core/Ai/Tools/JsonSchemaValidator.php` (evidência: `No syntax errors detected`)

### T-03 — AnthropicMessagesGateway: `{}` só no input de tool_use e em input_schema.properties; teste de messages sem tools

**Camada:** backend
**Dependências:** nenhuma
**Paralelizável:** sim
**Complexidade:** média
**Agente:** Platão

Origem: 8B T-02 ([aberta]: `jsonObjects` troca `[]` por `{}` em qualquer chave `input`/`properties` em qualquer profundidade, AnthropicMessagesGateway.php:121-137, inclusive dentro de dados; falta teste de `messages` não vazio sem `tools` + resposta `tool_use`). Testes vizinhos: `testEmptyToolInputIsSentAsJsonObject` (:439) e `testToolUseWithoutToolsIsProviderError` (:455, sem `messages`).

**Arquivos prováveis**
- `src/app/Core/Ai/AnthropicMessagesGateway.php`
- `src/tests/Unit/AnthropicMessagesGatewayTest.php`

**Interface**
- Produz: o body enviado converte `[]` em `{}` somente em `messages[i].content[j].input` de bloco com `type = tool_use` e em `tools[k].input_schema.properties`; qualquer outro `[]` (inclusive `properties` ou `input` aninhados dentro do `input` de um tool_use) segue `[]`. Assinatura pública inalterada.
- Consome: nada

**Teste RED**
- `src/tests/Unit/AnthropicMessagesGatewayTest.php` — requisição com bloco tool_use cujo `input` é `['filtro' => ['properties' => []]]` serializa `"input":{"filtro":{"properties":[]}}` no body capturado pelo transporte (hoje sai `{"properties":{}}`) (comando: `docker compose run --rm --no-deps -T -v /var/www/html/centralvet/src:/var/www/html/src:ro app php tests/run.php 2>&1 | /usr/bin/grep -E 'AnthropicMessagesGatewayTest|Failed:'`)

**Critério de aceite**
- SUITE: `PASS` no teste RED, em `testEmptyToolInputIsSentAsJsonObject` (input vazio segue `{}`) e num teste novo com `messages` não vazio, sem `tools`, cuja resposta tem `stop_reason: tool_use` → resultado com `ai_provider_error`; `AnthropicMessagesGatewayTest` com 0 `FAIL`.

**Validação**
- SUITE com `/usr/bin/grep -E 'AnthropicMessagesGatewayTest|StubAiGatewayScriptTest|Failed:'` (evidência: `PASS` dos três testes citados, nenhum `FAIL`)
- LINT de `app/Core/Ai/AnthropicMessagesGateway.php` (evidência: `No syntax errors detected`)

### T-04 — AiConversationMessage::reconstitute descarta entrada inválida de tool_summary; teste com id reconstituído

**Camada:** backend
**Dependências:** nenhuma
**Paralelizável:** sim
**Complexidade:** simples
**Agente:** Platão

Origem: 8B T-05 ([aberta]: `reconstitute` (:81-84) reaplica `fitSummary`, que lança em :186-188 com entrada sem `tool`/`label`; uma linha corrompida derruba `lastMessages()` em `AiConversationRepository.php:156`. `AiConversationDomainTest.php:72` usa `toRow() + ['id' => 5]`, que não sobrescreve `'id' => null`).

**Arquivos prováveis**
- `src/app/Core/Domain/AiConversationMessage.php`
- `src/tests/Unit/AiConversationDomainTest.php`

**Interface**
- Produz: `AiConversationMessage::reconstitute(array $row)` ignora entradas de `tool_summary` que não são array com `tool` e `label` (mantém as válidas, na ordem) e nunca lança por `tool_summary`; a criação (`fitSummary` no caminho de escrita) continua lançando `InvalidArgumentException` para entrada inválida.
- Consome: nada

**Teste RED**
- `src/tests/Unit/AiConversationDomainTest.php` — `reconstitute` de uma linha com `tool_summary = '[{"tool":"obter_exames","label":"Exames","count":2},{"foo":1}]'` devolve mensagem com só a entrada `obter_exames` (hoje lança `InvalidArgumentException`) (comando: `docker compose run --rm --no-deps -T -v /var/www/html/centralvet/src:/var/www/html/src:ro app php tests/run.php 2>&1 | /usr/bin/grep -E 'AiConversationDomainTest|Failed:'`)

**Critério de aceite**
- SUITE: `PASS` no teste RED e no round-trip da mensagem reescrito com `array_merge($assistant->toRow(), ['id' => 5])`, que assere `id() === 5`; `AiConversationDomainTest` e `CopilotRepositoryIntegrationTest` com 0 `FAIL`.

**Validação**
- SUITE com `/usr/bin/grep -E 'AiConversationDomainTest|CopilotRepositoryIntegrationTest|Failed:'` (evidência: `PASS` dos testes citados, nenhum `FAIL`)
- LINT de `app/Core/Domain/AiConversationMessage.php` (evidência: `No syntax errors detected`)

### T-05 — buscar_paciente e obter_historico_atendimentos com truncated real (limit+1); histórico fora da unidade testado

**Camada:** backend
**Dependências:** nenhuma
**Paralelizável:** sim
**Complexidade:** simples
**Agente:** Darwin

Origem: 8B T-10 ([aberta]: `truncated` sempre `false` em BuscarPacienteTool.php:111 e ObterHistoricoAtendimentosTool.php:218-220; a query recebe exatamente `$limit`, :92 e :208). Modelo `limit + 1`: `ListarDebitosTool.php:93-94`, `ObterPrescricoesTool.php:84-85`. Fake: `FakeCopilotClinicalQuery::list()` faz `array_slice(seeded, 0, max(1, $limit))` — nada a mudar nele. Falta caso de histórico com paciente fora da unidade (só `obter_paciente` :180 e `obter_prontuario` :218 têm).

**Arquivos prováveis**
- `src/app/Core/Ai/Tools/Clinical/BuscarPacienteTool.php`
- `src/app/Core/Ai/Tools/Clinical/ObterHistoricoAtendimentosTool.php`
- `src/tests/Unit/ClinicalToolsPatientTest.php`

**Interface**
- Produz: `BuscarPacienteTool::execute` chama `searchPatients($term, $limit + 1)` e `ObterHistoricoAtendimentosTool::execute` chama `encounters($patientId, $limit + 1)`; com mais de `$limit` linhas devolvem só `$limit` e `ToolResult->truncated === true`; com até `$limit`, `false`.
- Consome: nada

**Teste RED**
- `src/tests/Unit/ClinicalToolsPatientTest.php` — `buscar_paciente` com 3 pacientes semeados no fake e `limite` 2 devolve 2 linhas e `truncated === true`; idem `obter_historico_atendimentos` com 3 atendimentos e `limite` 2 (hoje `truncated === false`) (comando: `docker compose run --rm --no-deps -T -v /var/www/html/centralvet/src:/var/www/html/src:ro app php tests/run.php 2>&1 | /usr/bin/grep -E 'ClinicalToolsPatientTest|Failed:'`)

**Critério de aceite**
- SUITE: `PASS` nos testes de truncamento e num teste novo em que `obter_historico_atendimentos` com paciente fora da unidade lança `ToolEntityNotFoundException` em `assertInScope`; `ClinicalToolsPatientTest` e `ToolPolicyTest` com 0 `FAIL`.

**Validação**
- SUITE com `/usr/bin/grep -E 'ClinicalToolsPatientTest|ToolPolicyTest|Failed:'` (evidência: testes novos em `PASS`, nenhum `FAIL`)
- LINT dos dois arquivos de ferramenta (evidência: `No syntax errors detected`)

### T-06 — obter_prescricoes minimiza via/posologia/duracao; patientId() único em trait; fixtures de exame válidas

**Camada:** backend
**Dependências:** nenhuma
**Paralelizável:** sim
**Complexidade:** média
**Agente:** Darwin

Origem: 8B T-11 ([aberta]: `via`/`posologia`/`duracao` — `prescription_item.route/frequency/duration`, texto livre — fora de `freeTextFields`, ObterPrescricoesTool.php:94-96 e :103; fixture de exame com `status = 'received'`, inexistente no domínio (`ExamRequest.php:42-43`: `requested`/`result_available`), ClinicalToolsRecordsTest.php:103 e :220; faltam casos `requested` com `pending_review = null` e âncora fora da unidade) e a duplicação de `patientId()` (Achados da Rodada 2): a variante com `is_int`/`> 0` + `patientInUnit` é idêntica em ObterPrescricoesTool.php:107-118, ObterExamesTool.php:110 e ConsultarVacinacaoTool.php:97. A variante simples de ObterPacienteTool/ObterHistoricoAtendimentosTool fica.

**Arquivos prováveis**
- `src/app/Core/Ai/Tools/Clinical/ResolvesPatientInUnit.php`
- `src/app/Core/Ai/Tools/Clinical/ObterPrescricoesTool.php`
- `src/app/Core/Ai/Tools/Clinical/ObterExamesTool.php`
- `src/app/Core/Ai/Tools/Clinical/ConsultarVacinacaoTool.php`
- `src/tests/Unit/ClinicalToolsRecordsTest.php`

**Interface**
- Produz: `trait CentralVet\Ai\Tools\Clinical\ResolvesPatientInUnit` com `private function patientId(ToolContext $context, array $input): int` — mesmo corpo da variante de ObterPrescricoesTool.php:107-118 (patient_id inteiro > 0 ou âncora; sem nenhum → `ToolInputException`; fora da unidade → `ToolEntityNotFoundException`); usado pelas 3 ferramentas, que perdem a cópia local. `ObterPrescricoesTool` devolve `ToolResult` com `freeTextFields` contendo `via`, `posologia` e `duracao` (além dos que já tiver).
- Consome: nada

**Teste RED**
- `src/tests/Unit/ClinicalToolsRecordsTest.php` — `obter_prescricoes` devolve `ToolResult->freeTextFields` contendo `via`, `posologia` e `duracao` (hoje `[]`) (comando: `docker compose run --rm --no-deps -T -v /var/www/html/centralvet/src:/var/www/html/src:ro app php tests/run.php 2>&1 | /usr/bin/grep -E 'ClinicalToolsRecordsTest|Failed:'`)

**Critério de aceite**
- SUITE: `PASS` no teste RED; fixtures com `status = 'received'` trocadas por `result_available`; testes novos `PASS`: exame `requested` com `pending_review = null` aparece com o status `requested`, e `obter_exames`/`obter_prescricoes` com âncora fora da unidade lançam `ToolEntityNotFoundException`; `/usr/bin/grep -c 'function patientId' src/app/Core/Ai/Tools/Clinical/ObterPrescricoesTool.php src/app/Core/Ai/Tools/Clinical/ObterExamesTool.php src/app/Core/Ai/Tools/Clinical/ConsultarVacinacaoTool.php` → `0` em cada; `ClinicalToolsRecordsTest`, `ToolPolicyTest` e `ClinicalToolsetTest` com 0 `FAIL`.

**Validação**
- SUITE com `/usr/bin/grep -E 'ClinicalToolsRecordsTest|ToolPolicyTest|ClinicalToolset|Failed:'` (evidência: testes citados em `PASS`, nenhum `FAIL`)
- LINT dos 4 PHP de ferramenta/trait (evidência: `No syntax errors detected`)

### T-07 — CopilotClinicalQueryIntegrationTest: unidades A e B do mesmo tenant

**Camada:** qa
**Dependências:** nenhuma
**Paralelizável:** sim
**Complexidade:** simples
**Agente:** Darwin

Origem: 8B T-06 ([aberta]: fixture `otherUnitId()` (:490-505) pega qualquer `system_unit` ≠ A, possivelmente de outro tenant, e `unitA` (:53) é a primeira unidade do banco, também sem vínculo com `tenantA`). Os itens "superseded estreito" e "draft como ativa" ficam por ruling (notes.md).

**Arquivos prováveis**
- `src/tests/Integration/CopilotClinicalQueryIntegrationTest.php`

**Interface**
- Produz: nada (só fixture: `unitA` e `unitB` são `system_unit` novas com `tenant_id = tenantA`, `id = MAX(id)+1` e `+2` — `system_unit.id` não é AUTO_INCREMENT —, criadas dentro da transação do teste)
- Consome: nada

**Teste RED**
- sem teste: correção só de fixture de teste; os casos existentes passam antes e depois, e a prova é a asserção nova de que `system_unit.tenant_id` de `unitA` e `unitB` é `tenantA`

**Critério de aceite**
- SUITE: `CopilotClinicalQueryIntegrationTest` com o mesmo número de `PASS` de antes mais o teste novo, que assere `system_unit.tenant_id = tenantA` para `unitA` e `unitB`, e 0 `FAIL`; as unidades da fixture somem no rollback (o teste novo conta `system_unit` com o nome da fixture fora da transação: 0).

**Validação**
- SUITE com `/usr/bin/grep -E 'CopilotClinicalQueryIntegrationTest|Failed:'` antes e depois da edição (evidência: contagem de `PASS` igual + 1, nenhum `FAIL` — casos existentes preservados)

### T-08 — consultar_agenda aceita profissional inativo com agendamentos; fronteiras de 90 dias e outro tenant testados

**Camada:** backend
**Dependências:** nenhuma
**Paralelizável:** sim
**Complexidade:** média
**Agente:** Arquimedes

Origem: 8B T-19 ([aberta]: `profissional_id` de usuário inativo que ainda tem agendamentos vira `ToolEntityNotFoundException` em `consultar_agenda`, ConsultarAgendaTool.php:77-82 → `CopilotAgendaQuery::professionalExists` :172-181 filtra `u.active = 'Y'`) e 8B T-27 ([aberta]: sem teste da fronteira exata de 90 dias nem de agendamento no dia seguinte; `schedulableProfessionals` sem asserção de usuário de outro tenant — CopilotAgendaQueryIntegrationTest.php:141-177 usa -30 e -91 dias). `verificar_horarios` continua exigindo profissional ativo (não oferece horário a inativo).

**Arquivos prováveis**
- `src/app/Core/Domain/Contract/CopilotAgendaQueryInterface.php`
- `src/app/Core/Persistence/CopilotAgendaQuery.php`
- `src/app/Core/Ai/Tools/Management/ConsultarAgendaTool.php`
- `src/tests/Support/FakeCopilotAgendaQuery.php`
- `src/tests/Unit/AgendaToolsTest.php`
- `src/tests/Integration/CopilotAgendaQueryIntegrationTest.php`

**Interface**
- Produz: `CopilotAgendaQueryInterface::professionalExists(int $id, bool $includeInactive = false): bool` — com `true`, aceita usuário membro do tenant com qualquer `active`; com `false` (padrão), só `active = 'Y'`, como hoje. `ConsultarAgendaTool::assertInScope` chama `professionalExists($id, true)`; `VerificarHorariosTool` fica com a chamada de um argumento. `FakeCopilotAgendaQuery::professionalExists` considera também as linhas semeadas em `seed('inactive_professionals', [...])` quando `$includeInactive` é `true`.
- Consome: nada

**Teste RED**
- `src/tests/Unit/AgendaToolsTest.php` — `ConsultarAgendaTool::assertInScope` com `profissional_id` semeado só em `inactive_professionals` não lança, e `VerificarHorariosTool::assertInScope` com o mesmo id lança `ToolEntityNotFoundException` (hoje os dois lançam) (comando: `docker compose run --rm --no-deps -T -v /var/www/html/centralvet/src:/var/www/html/src:ro app php tests/run.php 2>&1 | /usr/bin/grep -E 'AgendaToolsTest|Failed:'`)

**Critério de aceite**
- SUITE: `PASS` no teste RED; em `CopilotAgendaQueryIntegrationTest`, testes novos `PASS`: usuário inativo do tenant → `professionalExists($id)` `false` e `professionalExists($id, true)` `true`; agendamento em `dia - 90 dias 00:00` entra e no dia seguinte `00:00` não entra em `schedulableProfessionals`; usuário de outro tenant com agendamento na unidade não aparece; `AgendaToolsTest` e `CopilotAgendaQueryIntegrationTest` com 0 `FAIL`.

**Validação**
- SUITE com `/usr/bin/grep -E 'AgendaToolsTest|CopilotAgendaQueryIntegrationTest|ManagementToolsetTest|Failed:'` (evidência: testes novos em `PASS`, nenhum `FAIL`)
- LINT dos 4 PHP de produção/fake tocados (evidência: `No syntax errors detected`)

### T-09 — Estoque: lote vencido fora de validade/lote; escape LIKE e bordas de expiring testados

**Camada:** backend
**Dependências:** nenhuma
**Paralelizável:** sim
**Complexidade:** média
**Agente:** Arquimedes

Origem: 8B T-20 ([aberta]: teste de escape LIKE não prova o escape — `'100%'` sem escape também não casaria nada; `expiring` sem bordas; `withNextExpiry` (CopilotStockQuery.php:103-122) só filtra `quantity > 0 AND expiry_date IS NOT NULL`, então `abaixo_minimo` e `termo` mostram lote já vencido como "validade"). Escape atual: `addcslashes($term, '\\%_')` (:79). `expiring` (:46-60) é `BETWEEN [hoje, hoje+N]` e já exclui vencidos.

**Arquivos prováveis**
- `src/app/Core/Persistence/CopilotStockQuery.php`
- `src/tests/Integration/CopilotStockQueryIntegrationTest.php`

**Interface**
- Produz: `withNextExpiry` acrescenta `AND b.expiry_date >= CURDATE()` (data da sessão MySQL, a mesma usada nas gravações); `belowMinimum` e `search` passam a mostrar como validade/lote o próximo lote não vencido, ou nulo quando só há lote vencido. Assinaturas de `CopilotStockQueryInterface` inalteradas.
- Consome: nada

**Teste RED**
- `src/tests/Integration/CopilotStockQueryIntegrationTest.php` — produto abaixo do mínimo com lote vencido ontem (`CURDATE() - INTERVAL 1 DAY`) e lote válido em `CURDATE() + INTERVAL 60 DAY` aparece em `belowMinimum` com a validade do lote de +60 dias (hoje vem a de ontem) (comando: `docker compose run --rm --no-deps -T -v /var/www/html/centralvet/src:/var/www/html/src:ro app php tests/run.php 2>&1 | /usr/bin/grep -E 'CopilotStockQueryIntegrationTest|Failed:'`)

**Critério de aceite**
- SUITE: `PASS` no teste RED e nos testes novos: `search('Gaze_A')` com produtos `Gaze_A` e `GazeXA` semeados devolve só `Gaze_A` (prova o escape de `_`); `expiring($hoje, 30, ...)` inclui lote vencendo hoje e em +30, exclui lote vencido ontem e lote de produto inativo; `CopilotStockQueryIntegrationTest` e `StockToolTest` com 0 `FAIL`.

**Validação**
- SUITE com `/usr/bin/grep -E 'CopilotStockQueryIntegrationTest|StockToolTest|Failed:'` (evidência: testes novos em `PASS`, nenhum `FAIL`)
- LINT de `app/Core/Persistence/CopilotStockQuery.php` (evidência: `No syntax errors detected`)

### T-10 — ListarDebitosTool no fuso da aplicação; paciente novo na unidade A testado

**Camada:** backend
**Dependências:** nenhuma
**Paralelizável:** sim
**Complexidade:** simples
**Agente:** Arquimedes

Origem: 8B T-21 ([aberta]: ListarDebitosTool.php:91 faz só `setTime(0, 0)`, sem `setTimezone(new DateTimeZone(date_default_timezone_get()))` como IndicadoresClinicaTool.php:106; relógio injetado em outro fuso desloca `today` e `dias_em_atraso`; CopilotFinanceQueryIntegrationTest não cobre paciente com atendimento anterior só na unidade B e primeiro atendimento na A no período — deve contar como novo na A, query :90-93). Vencimento no dia e `ticket_medio` com receita sem atendimento ficam para o runbook (T-24).

**Arquivos prováveis**
- `src/app/Core/Ai/Tools/Management/ListarDebitosTool.php`
- `src/tests/Unit/FinanceToolsTest.php`
- `src/tests/Integration/CopilotFinanceQueryIntegrationTest.php`

**Interface**
- Produz: `ListarDebitosTool::execute` calcula `$today = ($this->clock)()->setTimezone(new DateTimeZone(date_default_timezone_get()))->setTime(0, 0)` antes de chamar `openReceivables`.
- Consome: nada

**Teste RED**
- `src/tests/Unit/FinanceToolsTest.php` — com `date_default_timezone_set('America/Fortaleza')` (restaurado no fim) e relógio `2031-03-11 01:30:00 UTC`, `listar_debitos` chama `openReceivables` com o dia `2031-03-10` (hoje passa `2031-03-11`) (comando: `docker compose run --rm --no-deps -T -v /var/www/html/centralvet/src:/var/www/html/src:ro app php tests/run.php 2>&1 | /usr/bin/grep -E 'FinanceToolsTest|Failed:'`)

**Critério de aceite**
- SUITE: `PASS` no teste RED e num teste novo de `CopilotFinanceQueryIntegrationTest` em que um paciente com atendimento anterior só na unidade B e o primeiro na A dentro do período conta 1 em pacientes novos da A; `FinanceToolsTest` e `CopilotFinanceQueryIntegrationTest` com 0 `FAIL`.

**Validação**
- SUITE com `/usr/bin/grep -E 'FinanceToolsTest|CopilotFinanceQueryIntegrationTest|Failed:'` (evidência: testes novos em `PASS`, nenhum `FAIL`)
- LINT de `app/Core/Ai/Tools/Management/ListarDebitosTool.php` (evidência: `No syntax errors detected`)

### T-11 — AiCopilotAvailability::providerConfigured testável

**Camada:** backend
**Dependências:** nenhuma
**Paralelizável:** sim
**Complexidade:** simples
**Agente:** Yoda

Origem: 8B T-26 ([aberta]: a closure de configuração de `AiCopilotAvailability::forContext` (:64-76) — stub → true; anthropic só com apiKey; AiConfig inválido → false — não tem teste; `AiCopilotAvailabilityTest` injeta fakes). A duplicação `reason` × `AiCopilotService::availability` já foi resolvida (o serviço delega, :296-299).

**Arquivos prováveis**
- `src/app/Core/Application/AiCopilotAvailability.php`
- `src/tests/Unit/AiCopilotAvailabilityTest.php`

**Interface**
- Produz: `public static function providerConfigured(AiConfig $config): bool` em `AiCopilotAvailability` — `$config->provider() === 'stub' || ($config->provider() === 'anthropic' && $config->apiKey() !== null)`; a closure de `forContext` passa a chamá-lo depois de `AiConfig::fromEnvironment()` (o `catch` com `error_log` só da classe fica).
- Consome: nada

**Teste RED**
- `src/tests/Unit/AiCopilotAvailabilityTest.php` — `providerConfigured(AiConfig::fromArray(['AI_PROVIDER' => 'stub']))` é `true`, `providerConfigured(AiConfig::fromArray([]))` é `false` e `providerConfigured(AiConfig::fromArray(['ANTHROPIC_API_KEY' => 'sk-ant-test-f8b']))` é `true` (hoje o método não existe) (comando: `docker compose run --rm --no-deps -T -v /var/www/html/centralvet/src:/var/www/html/src:ro app php tests/run.php 2>&1 | /usr/bin/grep -E 'AiCopilotAvailabilityTest|Failed:'`)

**Critério de aceite**
- SUITE: `PASS` no teste RED; `AiCopilotAvailabilityTest`, `CopilotHostButtonsIntegrationTest` e `AiCopilotPanelIntegrationTest` com 0 `FAIL`.

**Validação**
- SUITE com `/usr/bin/grep -E 'AiCopilotAvailabilityTest|CopilotHostButtonsIntegrationTest|AiCopilotPanelIntegrationTest|Failed:'` (evidência: `PASS` do teste novo, nenhum `FAIL`)
- LINT de `app/Core/Application/AiCopilotAvailability.php` (evidência: `No syntax errors detected`)

### T-12 — AiCopilotService loga falha do insert da interação no caminho de sucesso

**Camada:** backend
**Dependências:** nenhuma
**Paralelizável:** sim
**Complexidade:** simples
**Agente:** Yoda

Origem: 8B T-28 ([aberta]: `ai_interaction` é inserida em `finally` depois de `appendMessage` + `touch` (AiCopilotService.php:460-475); se o insert falha com a resposta já gravada (`$completed = true`), o `catch` (:512-515) relança sem log, e o uso some da cota sem rastro). Decisão: manter a ordem (ruling T-28 · onda 9) e logar com `correlation_id` antes de relançar. Padrão de captura de `error_log` em teste: `PatientServiceTest.php:446-454` (`ini_set('error_log', $arquivoTemporario)` e restauração).

**Arquivos prováveis**
- `src/app/Core/Application/AiCopilotService.php`
- `src/tests/Unit/AiCopilotServiceTest.php`
- `src/tests/Support/FakeAiInteractionRepository.php`

**Interface**
- Produz: no `catch` de `recordInteraction`, com `$completed === true`, `error_log('AiCopilotService: interaction not recorded after the answer (' . $e::class . ', correlation_id=' . $correlationId . ')')` e depois `throw $e`; nenhum outro dado no log. `FakeAiInteractionRepository::failNextInsert(\Throwable $e): void` (se ainda não houver meio de falhar o insert no fake).
- Consome: nada

**Teste RED**
- `src/tests/Unit/AiCopilotServiceTest.php`, `src/tests/Support/FakeAiInteractionRepository.php` — `ask` respondido com o insert da interação falhando relança a exceção e o `error_log` capturado contém `interaction not recorded after the answer` e o `correlation_id` da pergunta, sem o texto da pergunta nem da resposta (hoje não há log) (comando: `docker compose run --rm --no-deps -T -v /var/www/html/centralvet/src:/var/www/html/src:ro app php tests/run.php 2>&1 | /usr/bin/grep -E 'AiCopilotServiceTest|Failed:'`)

**Critério de aceite**
- SUITE: `PASS` no teste RED; `AiCopilotServiceTest`, `AiCopilotServiceForContextIntegrationTest` e `AiCopilotPanelIntegrationTest` com 0 `FAIL`.

**Validação**
- SUITE com `/usr/bin/grep -E 'AiCopilotServiceTest|AiCopilotServiceForContextIntegrationTest|AiCopilotPanelIntegrationTest|Failed:'` (evidência: teste novo em `PASS`, nenhum `FAIL`)
- LINT de `app/Core/Application/AiCopilotService.php` e do fake (evidência: `No syntax errors detected`)

### T-13 — forContext monta as 5 ferramentas de gestão e as 7 clínicas (teste de wiring)

**Camada:** qa
**Dependências:** nenhuma
**Paralelizável:** sim
**Complexidade:** simples
**Agente:** Yoda

Origem: 8B T-23 ([aberta]: nenhum teste falha se `ManagementToolset::create(...)` sair de `AiCopilotService::forContext` (:152-204); o registry é `private` (:122), sem getter). O teste lê o registry por `ReflectionProperty` (padrão já usado em `PatientServiceTest.php:566`) e consulta `ToolRegistry::registered($family, $name)` (:107), que ignora RBAC e flags; código de produção não muda.

**Arquivos prováveis**
- `src/tests/Integration/AiCopilotServiceForContextIntegrationTest.php`

**Interface**
- Produz: nada
- Consome: nada

**Teste RED**
- sem teste: teste de regressão sobre wiring existente, que passa ao ser escrito; a sensibilidade é provada removendo à mão, sem commitar, a linha `ManagementToolset::create` do `forContext`, rodando o teste (falha colada em `## RED` do relatório) e desfazendo a edição à mão

**Critério de aceite**
- SUITE: `PASS` no teste novo, que assere `registered('management', $n) !== null` para os 5 nomes de gestão (`consultar_agenda`, `verificar_horarios`, `consultar_estoque`, `listar_debitos`, `indicadores_clinica`), `registered('clinical', $n) !== null` para os 7 clínicos e `registered('clinical', 'listar_debitos') === null`; `git -C /var/www/html/centralvet diff --stat 14139af -- src/app/Core/Application/AiCopilotService.php` não mostra mudança desta task.

**Validação**
- SUITE com `/usr/bin/grep -E 'AiCopilotServiceForContextIntegrationTest|Failed:'` (evidência: `PASS` do teste novo, nenhum `FAIL`)
- `/usr/bin/grep -o "'[a-z_]*'" src/app/Core/Ai/Tools/ToolFamily.php` (evidência: os 12 nomes usados no teste existem na allowlist da família)

### T-14 — Repositórios: insert confere unidade/usuário; mcp_tool_call.conversation_id do tenant; lacunas de teste

**Camada:** backend
**Dependências:** nenhuma
**Paralelizável:** sim
**Complexidade:** média
**Agente:** Jaspion

Origem: 8B T-09 ([aberta]: `AiConversationRepository::insert` (:38) e `McpToolCallRepository::insert` (:35) só chamam `assertTenant`; `system_unit_id`/`system_user_id` não são conferidos com o contexto e `conversation_id` (sem FK) não é validado; lacunas: `lastMessages` sem caso "outro tenant", `deleteOwned` sem "outra unidade"). `search` tenant-wide é intencional (ruling, notes.md). `TenantContext`: `userId()` :46, `unitId()` :51, `requireUnitId()` :56; `assertTenant` lança `TenantBoundaryViolation`.

**Arquivos prováveis**
- `src/app/Core/Persistence/AiConversationRepository.php`
- `src/app/Core/Persistence/McpToolCallRepository.php`
- `src/tests/Integration/CopilotRepositoryIntegrationTest.php`

**Interface**
- Produz: `AiConversationRepository::insert` e `McpToolCallRepository::insert` lançam `TenantBoundaryViolation('Resource does not belong to the authenticated user or unit')` quando `systemUnitId() !== $this->context->requireUnitId()` ou `systemUserId() !== $this->context->userId()`; `McpToolCallRepository::insert` com `conversationId() !== null` lança a mesma exceção se não existe `ai_conversation` com esse id no tenant do contexto (o dono não é exigido: a retenção e a exclusão pelo autor não afetam a auditoria já gravada).
- Consome: nada

**Teste RED**
- `src/tests/Integration/CopilotRepositoryIntegrationTest.php` — insert de `AiConversation` com `systemUserId` diferente do usuário do contexto e de `McpToolCall` com `conversation_id` de outro tenant lançam `TenantBoundaryViolation` e não gravam linha (hoje gravam) (comando: `docker compose run --rm --no-deps -T -v /var/www/html/centralvet/src:/var/www/html/src:ro app php tests/run.php 2>&1 | /usr/bin/grep -E 'CopilotRepositoryIntegrationTest|Failed:'`)

**Critério de aceite**
- SUITE: `PASS` no teste RED e nos testes novos de `lastMessages` com conversa de outro tenant (lista vazia) e `deleteOwned` de conversa de outra unidade (`false`, linha continua em `ai_conversation`); `CopilotRepositoryIntegrationTest`, `AiCopilotServiceForContextIntegrationTest`, `AiCopilotPanelIntegrationTest`, `AiCopilotListsIntegrationTest` e `AiConversationRetentionIntegrationTest` com 0 `FAIL`.

**Validação**
- SUITE com `/usr/bin/grep -E 'CopilotRepositoryIntegrationTest|AiCopilotServiceForContextIntegrationTest|AiCopilotPanelIntegrationTest|AiCopilotListsIntegrationTest|AiConversationRetentionIntegrationTest|Failed:'` (evidência: testes novos em `PASS`, nenhum `FAIL`)
- LINT dos dois repositórios (evidência: `No syntax errors detected`)

### T-15 — Retenção: lote e teto injetáveis, updated_at × created_at testado, trava contra execução sobreposta

**Camada:** backend
**Dependências:** nenhuma
**Paralelizável:** sim
**Complexidade:** média
**Agente:** Jaspion

Origem: 8B T-12 ([aberta]: a fixture grava `created_at = updated_at` (:85-100), então filtrar por `created_at` também passaria; o laço de lotes, a parada por lote curto e `MAX_BATCHES` não são exercidos — constantes públicas :26-27 não injetáveis; o bin não tem trava contra duas execuções sobrepostas). Padrão de trava do projeto: `src/bin/worker.php:129-135` (`flock(LOCK_EX | LOCK_NB)` em arquivo de `sys_get_temp_dir()`, sai 0 quando travado).

**Arquivos prováveis**
- `src/app/Core/Application/AiConversationRetention.php`
- `src/bin/ai-conversation-retention.php`
- `src/tests/Integration/AiConversationRetentionIntegrationTest.php`

**Interface**
- Produz: `AiConversationRetention::forConnection(PDO $connection, ?Closure $clock = null, int $batchSize = self::BATCH_SIZE, int $maxBatches = self::MAX_BATCHES): self` (ambos ≥ 1, senão `InvalidArgumentException`); `runOnce()` usa os valores injetados e mantém o retorno `{deleted, batches}`. O bin abre `sys_get_temp_dir() . '/centralvet-ai-conversation-retention-' . md5(__DIR__) . '.lock'` com `flock(LOCK_EX | LOCK_NB)`; travado → imprime `{"skipped":"locked"}` e sai 0, sem DELETE.
- Consome: nada

**Teste RED**
- `src/tests/Integration/AiConversationRetentionIntegrationTest.php` — com 5 conversas ociosas há 120 dias e `forConnection($pdo, $clock, 2, 2)`, `runOnce()` devolve `['deleted' => 4, 'batches' => 2]` e deixa 1 dessas conversas (hoje a assinatura não aceita lote/teto) (comando: `docker compose run --rm --no-deps -T -v /var/www/html/centralvet/src:/var/www/html/src:ro app php tests/run.php 2>&1 | /usr/bin/grep -E 'AiConversationRetentionIntegrationTest|Failed:'`)

**Critério de aceite**
- SUITE: `PASS` no teste RED e nos testes novos: lote curto para o laço (3 conversas, lote 2 → `{deleted: 3, batches: 2}`); conversa com `created_at` de 120 dias e `updated_at` de 10 dias continua em `ai_conversation`; `mcp_tool_call` e `ai_interaction` com as mesmas contagens antes e depois do `runOnce`; `AiConversationRetentionIntegrationTest` com 0 `FAIL`.

**Validação**
- SUITE com `/usr/bin/grep -E 'AiConversationRetentionIntegrationTest|Failed:'` (evidência: testes novos em `PASS`, nenhum `FAIL`)
- LINT de `app/Core/Application/AiConversationRetention.php` e `bin/ai-conversation-retention.php` (evidência: `No syntax errors detected`)
- `/usr/bin/grep -n 'LOCK_NB' src/bin/ai-conversation-retention.php` (evidência: uma linha com `flock(` e `LOCK_EX | LOCK_NB`)

### T-16 — AiCopilotPanel: pergunta em branco, rascunho preservado na indisponibilidade, âncora antes de apagar, RBAC negado testado

**Camada:** frontend
**Dependências:** nenhuma
**Paralelizável:** sim
**Complexidade:** alta
**Agente:** Aang

Origem: 8B T-14 ([aberta]: pergunta só com espaços passa no `required` (:465), vira `''` no `trim` (:89), o `InvalidArgumentException` de AiCopilotService.php:214 cai no `catch (Exception)` (:256-261) com texto em inglês e `renderMessageOnly($family, null)` perde formulário e rascunho), 8B T-29 ([aberta]: `AiUnavailableException` ao montar o serviço (:107-110, :115-120) vai a `load` → `renderPanel` (:309) sem o texto digitado, enquanto a negação o preserva em textarea somente leitura (`perform` :233-241, `renderMessageOnly` :349-363); `onDelete` apaga (:174) antes de `load()`, onde roda `patientInUnit` (:282); RBAC de abrir/apagar no painel não exercido — testes usam `FakeAuthorizationPolicy(true)`, :332/:345) e o Achado da Rodada 2 (texto perdido no `onAsk` com `AiUnavailableException`). Exclusão sem token CSRF fica fora (padrão do projeto).

**Arquivos prováveis**
- `src/app/control/clinic/AiCopilotPanel.php`
- `src/app/config/translations.json`
- `src/tests/Integration/AiCopilotPanelIntegrationTest.php`

**Interface**
- Produz: `onAsk` com `trim(question) === ''` não chama o serviço e renderiza o painel com o formulário e o aviso `_t('Type a question before sending')` (pt `Digite uma pergunta antes de enviar`, entrada nova em `translations.json` na posição ordenada por `en`); `AiUnavailableException` na montagem do serviço ou no pré-check do `onAsk` renderiza o motivo e a pergunta digitada em textarea somente leitura, pelo mesmo caminho da negação (`renderMessageOnly($family, $message, $draft)`); `onDelete` roda a checagem da âncora (`patientInUnit`, a mesma de `load`) antes de apagar: âncora fora da unidade → o mesmo tratamento de `AiSubjectNotFoundException` de hoje (TMessage `_t('Patient not found')`, pt "Paciente não encontrado", e fecha a cortina) e a conversa fica.
- Consome: nada

**Teste RED**
- `src/tests/Integration/AiCopilotPanelIntegrationTest.php` — `onAsk` com `question = '   '` devolve HTML com `Digite uma pergunta antes de enviar` e o textarea `question` do formulário, sem o texto `Copilot question must have` (hoje vem a mensagem em inglês sem formulário) (comando: `docker compose run --rm --no-deps -T -v /var/www/html/centralvet/src:/var/www/html/src:ro app php tests/run.php 2>&1 | /usr/bin/grep -E 'AiCopilotPanelIntegrationTest|Failed:'`)

**Critério de aceite**
- SUITE: `PASS` no teste RED e nos testes novos: `onAsk` com IA indisponível na montagem (flag desligada) devolve o motivo e um textarea `readonly` com a pergunta digitada; `onDelete` com âncora de paciente fora da unidade devolve "Paciente não encontrado" e `SELECT COUNT(*) FROM ai_conversation WHERE id = <conversa>` continua 1; `onOpen` e `onDelete` com `FakeAuthorizationPolicy(false)` em `makeHistory` devolvem "Permissão negada" e a conversa continua gravada; `AiCopilotPanelIntegrationTest`, `CopilotTranslationsTest` e `UserMessageTest` com 0 `FAIL`.

**Validação**
- SUITE com `/usr/bin/grep -E 'AiCopilotPanelIntegrationTest|CopilotTranslationsTest|UserMessageTest|Failed:'` (evidência: testes novos em `PASS`, nenhum `FAIL`)
- LINT de `app/control/clinic/AiCopilotPanel.php` (evidência: `No syntax errors detected`)
- `python3 -c "import json;json.load(open('/var/www/html/centralvet/src/app/config/translations.json'))"` (evidência: sem exceção)

### T-17 — CopilotTranslationsTest: toda entrada tem só en/pt

**Camada:** qa
**Dependências:** nenhuma
**Paralelizável:** sim
**Complexidade:** simples
**Agente:** Levi

Origem: 8B T-07 ([aberta]: o teste não garante que cada entrada tenha só `en`/`pt`; `ApplicationTranslator` (:47-52) indexa por posição — `$this->messages[$lang][] = $text` — e uma entrada sem `pt` desalinharia todas as seguintes; `entries()` :85-91 só confere `is_array`). Hoje as 1444 entradas estão conformes. A contagem "33 chaves" é texto do plano fechado da 8B (são 32): não se reescreve (notes.md).

**Arquivos prováveis**
- `src/tests/Unit/CopilotTranslationsTest.php`

**Interface**
- Produz: nada
- Consome: nada

**Teste RED**
- sem teste: asserção de forma sobre dados já conformes (as 1444 entradas têm exatamente `en` e `pt`), que passa ao ser escrita; a sensibilidade é provada com uma cópia temporária do JSON sem `pt` numa entrada, fora do repositório, colada em `## RED`

**Critério de aceite**
- SUITE: `PASS` num teste novo de `CopilotTranslationsTest` que falha se alguma entrada de `translations.json` não tiver exatamente as chaves `en` e `pt`, ambas strings não vazias; `CopilotTranslationsTest` com 0 `FAIL`.

**Validação**
- SUITE com `/usr/bin/grep -E 'CopilotTranslationsTest|Failed:'` (evidência: `PASS` do teste novo)

### T-18 — Flag ai_copilot_management gravada pelo FeatureFlagRepository real

**Camada:** qa
**Dependências:** nenhuma
**Paralelizável:** sim
**Complexidade:** simples
**Agente:** Levi

Origem: 8B T-22 ([aberta]: o aceite "grava `feature_flag` enabled 1/0" foi provado só com `FakeFeatureFlagRepository`, AiSettingsScreensIntegrationTest.php:247). O trilho do switch fora do centro (`.tswitch` + `cv-touch-target`) fica como verificação visual manual (notes.md). Arquivo de teste novo para não colidir com `AiSettingsScreensIntegrationTest`.

**Arquivos prováveis**
- `src/tests/Integration/AiCopilotManagementFlagIntegrationTest.php`

**Interface**
- Produz: nada
- Consome: nada

**Teste RED**
- sem teste: cobertura de integração de código existente (`AiSettingsService::setCopilotManagement(bool)` com `FeatureFlagRepository` real), que passa ao ser escrita

**Critério de aceite**
- SUITE: `PASS` no teste novo, que herda `MysqlIntegrationTestCase`, chama `setCopilotManagement(true)` e lê `feature_flag.enabled = 1` para `ai_copilot_management` no tenant da fixture, depois `setCopilotManagement(false)` → `enabled = 0`, e `overview()['copilot_management']` acompanha os dois valores.

**Validação**
- SUITE com `/usr/bin/grep -E 'AiCopilotManagementFlagIntegrationTest|Failed:'` (evidência: `PASS` dos casos novos)
- LINT de `tests/Integration/AiCopilotManagementFlagIntegrationTest.php` (evidência: `No syntax errors detected`)

### T-19 — 0015 .verify.sql confere os 5 índices auxiliares de FK

**Camada:** database
**Dependências:** nenhuma
**Paralelizável:** sim
**Complexidade:** simples
**Agente:** Levi

Origem: 8B T-01 ([aberta]: o `.verify.sql` (comentário :55-59, filtro `IN` :64-69) lista só 7 índices; faltam `ai_conversation_unit_idx`, `ai_conversation_user_idx`, `ai_conversation_anchor_patient_idx` (.sql :73-75), `ai_conversation_message_tenant_idx` (:99) e `mcp_tool_call_unit_idx` (:128)). O checksum gravado em `schema_migrations` vem do `.sql`, não do `.verify.sql`; o verify não é rodado por teste nem pelo `provision.sh`. Nenhum SQL é executado nesta task.

**Arquivos prováveis**
- `src/app/database/migrations/20261015_0015_phase8b_copilot.verify.sql`

**Interface**
- Produz: o `.verify.sql` lista 12 índices no comentário e no `IN (...)`, com a contagem esperada atualizada no comentário.
- Consome: nada

**Teste RED**
- sem teste: arquivo SQL de conferência manual, não executado por teste nem script do projeto

**Critério de aceite**
- `for i in ai_conversation_unit_idx ai_conversation_user_idx ai_conversation_anchor_patient_idx ai_conversation_message_tenant_idx mcp_tool_call_unit_idx; do /usr/bin/grep -c "$i" src/app/database/migrations/20261015_0015_phase8b_copilot.verify.sql; done` imprime um número ≥ 1 em cada linha; `git -C /var/www/html/centralvet diff --stat 14139af -- src/app/database/migrations/20261015_0015_phase8b_copilot.sql` vazio.

**Validação**
- O laço do critério (evidência: cinco linhas com valor ≥ 1) e o `git diff --stat` do `.sql` (evidência: saída vazia — checksum da 0015 preservado)

### T-20 — ToolPolicy nega ToolContext de outro contexto; ToolResult exige excerptLengths ⊆ freeTextFields; docblock de knownNames

**Camada:** backend
**Dependências:** T-02, T-05, T-06
**Paralelizável:** sim
**Complexidade:** média
**Agente:** Spock

Origem: 8B T-25 ([aberta]: `ToolResult` aceita em `excerptLengths` campo fora de `freeTextFields` (:38-42), que seria cortado sem minimização; `ToolPolicy::execute` (:70) confia no RBAC decidido para o `TenantContext` do registry sem conferir o `ToolContext` recebido; forbidden × not_offered fica por ruling) e o Achado da Rodada 2: docblock de `ToolResult` (:10-11) diz que `knownNames` são nomes que a minimização "may keep", mas `PiiMinimizer::minimize` (:136-139) os mascara. Depende de T-05/T-06 porque elas mexem nos `ToolResult` das ferramentas clínicas que a validação nova confere.

**Arquivos prováveis**
- `src/app/Core/Ai/Tools/ToolPolicy.php`
- `src/app/Core/Ai/Tools/ToolResult.php`
- `src/tests/Unit/ToolPolicyTest.php`
- `src/tests/Unit/ToolResultTest.php`

**Interface**
- Produz: `ToolPolicy::execute` nega com `deny_reason` `forbidden`, sem executar nem validar a ferramenta, quando `!$this->registry->sameContext($context)` (checagem logo após minimizar a entrada); construtor de `ToolResult` lança `\InvalidArgumentException('Excerpt lengths must name free-text fields')` quando uma chave de `excerptLengths` não está em `freeTextFields`; docblock de `ToolResult` diz que `knownNames` são nomes já mostrados ao usuário que a minimização mascara.
- Consome: T-02 `ToolRegistry::sameContext(ToolContext $context): bool`

**Teste RED**
- `src/tests/Unit/ToolPolicyTest.php` — `execute` com `ToolContext` de outro `userId` que o `TenantContext` do registry devolve `ToolOutcome` negado com `forbidden`, a ferramenta fake não é executada e o `FakeMcpToolCallRepository` recebe uma linha `denied` (hoje a ferramenta roda) (comando: `docker compose run --rm --no-deps -T -v /var/www/html/centralvet/src:/var/www/html/src:ro app php tests/run.php 2>&1 | /usr/bin/grep -E 'ToolPolicyTest|Failed:'`)

**Critério de aceite**
- SUITE: `PASS` no teste RED e em `ToolResultTest` (`new ToolResult([], false, ['motivo'], [], ['resultado' => 400])` lança `InvalidArgumentException`; `['motivo' => 160]` com `freeTextFields ['motivo']` não lança); `ToolPolicyTest`, `ClinicalToolsPatientTest`, `ClinicalToolsRecordsTest`, `AgendaToolsTest`, `StockToolTest`, `FinanceToolsTest` e `AiCopilotServiceTest` com 0 `FAIL`; `/usr/bin/grep -n 'may keep' src/app/Core/Ai/Tools/ToolResult.php` sem saída.

**Validação**
- SUITE com `/usr/bin/grep -E 'ToolPolicyTest|ToolResultTest|ClinicalTools|AgendaToolsTest|StockToolTest|FinanceToolsTest|AiCopilotServiceTest|Failed:'` (evidência: testes novos em `PASS`, nenhum `FAIL`)
- LINT de `app/Core/Ai/Tools/ToolPolicy.php` e `app/Core/Ai/Tools/ToolResult.php` (evidência: `No syntax errors detected`)

### T-21 — AiToolCallList: Motivo traduzido, negação e XSS testados

**Camada:** frontend
**Dependências:** T-16
**Paralelizável:** sim
**Complexidade:** média
**Agente:** Hegel

Origem: 8B T-15 ([aberta]: coluna Motivo mostra `deny_reason` cru (AiToolCallList.php:239), contra "textos em pt"; `testToolCallListWithoutTheProgramIsDenied` (AiCopilotListsIntegrationTest.php:301-306) só afirma ausência de dado e passaria com a tela quebrada; nenhum teste de XSS em título de conversa ou nome de usuário — o código escapa com `CvFormat::e`). Códigos em `McpToolCall::DENY_REASONS` (:22). Depende da T-16 só por dividir `translations.json` (serialização).

**Arquivos prováveis**
- `src/app/control/clinic/AiToolCallList.php`
- `src/app/config/translations.json`
- `src/tests/Integration/AiCopilotListsIntegrationTest.php`

**Interface**
- Produz: `AiToolCallList::denyReasonLabel(?string $reason): string` (privado estático) — `null` → `—`; `not_offered` → `_t('Tool not offered')`, `invalid_input` → `_t('Invalid tool input')`, `forbidden` → `_t('Tool not allowed')`, `not_found` → `_t('Record not found')`, `rate_limited` → `_t('Call limit reached')`, `error` → `_t('Unexpected failure')`, `timeout` → `_t('Time limit exceeded')`; código desconhecido → o próprio código escapado. Entradas pt em `translations.json`, na posição ordenada: `Ferramenta não oferecida`, `Entrada inválida`, `Ferramenta sem permissão`, `Registro não encontrado`, `Limite de chamadas atingido`, `Falha inesperada`, `Tempo limite excedido` (se uma chave `en` já existir com outro texto, use outra chave `en` e registre no board).
- Consome: nada

**Teste RED**
- `src/tests/Integration/AiCopilotListsIntegrationTest.php` — linha negada com `deny_reason = 'not_found'` mostra `Registro não encontrado` na coluna Motivo e não a célula `<td>not_found</td>` (hoje mostra o código) (comando: `docker compose run --rm --no-deps -T -v /var/www/html/centralvet/src:/var/www/html/src:ro app php tests/run.php 2>&1 | /usr/bin/grep -E 'AiCopilotListsIntegrationTest|Failed:'`)

**Critério de aceite**
- SUITE: `PASS` no teste RED; `testToolCallListWithoutTheProgramIsDenied` passa a asserir `Permissão negada` no HTML; testes novos de XSS: conversa com título `<script>alert(1)</script>` em `AiConversationList` e usuário com nome `<img src=x onerror=alert(1)>` em `AiToolCallList` aparecem escapados (`&lt;script&gt;`, `&lt;img`) e sem a tag crua; `AiCopilotListsIntegrationTest`, `CopilotTranslationsTest` e `UserMessageTest` com 0 `FAIL`.

**Validação**
- SUITE com `/usr/bin/grep -E 'AiCopilotListsIntegrationTest|CopilotTranslationsTest|UserMessageTest|Failed:'` (evidência: testes novos em `PASS`, nenhum `FAIL`)
- LINT de `app/control/clinic/AiToolCallList.php` (evidência: `No syntax errors detected`)
- `python3 -c "import json;json.load(open('/var/www/html/centralvet/src/app/config/translations.json'))"` (evidência: sem exceção)

### T-22 — Painel: foco vai para a resposta nova após o envio

**Camada:** frontend
**Dependências:** T-16
**Paralelizável:** sim
**Complexidade:** média
**Agente:** Aang

Origem: 8B T-14 ([aberta]: após a resposta a cortina inteira é substituída, a live region renasce vazia e o foco, que estava no botão removido, cai no `body`; cv-ai-copilot.js não chama `focus()` e a live region só anuncia "pensando", :55-58, limpa em `release` :27-30). Sem texto novo (evita nova chave em `translations.json`): o leitor de tela lê o conteúdo focado. A asserção do JS continua por `str_contains` no fonte (o projeto não tem runner de JS e Playwright é proibido — notes.md).

**Arquivos prováveis**
- `src/app/control/clinic/AiCopilotPanel.php`
- `src/app/templates/adminbs5/js/cv-ai-copilot.js`
- `src/tests/Integration/AiCopilotPanelIntegrationTest.php`

**Interface**
- Produz: a última mensagem do assistente em `conversationView` sai com `tabindex="-1"` e `data-cv-copilot-latest="1"`; quando a resposta veio de um envio (`onAsk`), o painel marca o shell com `data-cv-copilot-focus="latest"`; `cv-ai-copilot.js`, ao inicializar um shell com esse atributo, chama `focus()` no elemento `[data-cv-copilot-latest="1"]` (ou no textarea `question`, se não houver resposta) e remove o atributo. Rótulos continuam alinhados à esquerda; nenhum alvo de toque novo.
- Consome: nada

**Teste RED**
- `src/tests/Integration/AiCopilotPanelIntegrationTest.php` — `onAsk` respondido devolve HTML com `data-cv-copilot-focus="latest"` e a última resposta com `data-cv-copilot-latest="1"` e `tabindex="-1"`, e o fonte de `cv-ai-copilot.js` contém `data-cv-copilot-latest` e `.focus(` (hoje nenhum dos dois) (comando: `docker compose run --rm --no-deps -T -v /var/www/html/centralvet/src:/var/www/html/src:ro app php tests/run.php 2>&1 | /usr/bin/grep -E 'AiCopilotPanelIntegrationTest|Failed:'`)

**Critério de aceite**
- SUITE: `PASS` no teste RED; `onOpen` de conversa existente sem envio não traz `data-cv-copilot-focus`; `AiCopilotPanelIntegrationTest` com 0 `FAIL`; `git -C /var/www/html/centralvet diff 14139af -- src/app/templates/adminbs5/js/cv-ai-copilot.js | /usr/bin/grep -cE 'text-align:\s*(right|center)'` → `0`.

**Validação**
- SUITE com `/usr/bin/grep -E 'AiCopilotPanelIntegrationTest|Failed:'` (evidência: teste novo em `PASS`, nenhum `FAIL`)
- LINT de `app/control/clinic/AiCopilotPanel.php` (evidência: `No syntax errors detected`)
- `docker compose run --rm --no-deps -T -v /var/www/html/centralvet/src:/var/www/html/src:ro app sh -c 'command -v node >/dev/null && node --check app/templates/adminbs5/js/cv-ai-copilot.js || echo sem-node'` (evidência: saída vazia ou `sem-node`; com `sem-node`, registre a pendência)

### T-23 — AiCopilotPanel::hostLink único para PatientForm e EncounterView; docblock de resolveTenantContext

**Camada:** frontend
**Dependências:** T-22
**Paralelizável:** sim
**Complexidade:** simples
**Agente:** Aang

Origem: 8B T-16 ([aberta]: markup do link duplicado — PatientForm.php:758-765 e EncounterView.php:1717-1729 (classe, `href`, `generator`, `data-cv-copilot`, ícone, span); docblock de `resolveTenantContext` órfão em PatientForm.php:721-725, acima do docblock de `copilotLink`) e a duplicação `copilotLink` dos Achados. "Link sem RBAC de onOpen" fica por ruling (notes.md). Depende da T-22 por dividir `AiCopilotPanel.php`.

**Arquivos prováveis**
- `src/app/control/clinic/AiCopilotPanel.php`
- `src/app/control/clinic/PatientForm.php`
- `src/app/control/clinic/EncounterView.php`

**Interface**
- Produz: `public static function hostLink(string $family, ?int $anchorPatientId): TElement` em `AiCopilotPanel` — o mesmo `<a class="btn btn-default cv-touch-target" href="<linkFor escapado>" generator="adianti" data-cv-copilot="<family>">` com `fa:comments` e `<span>Copilot</span>` de hoje; `PatientForm::copilotLink` e o link da `EncounterView` mantêm a checagem de disponibilidade e passam a devolver `AiCopilotPanel::hostLink(...)`; o docblock "Resolves the tenant context..." volta para cima de `PatientForm::resolveTenantContext`.
- Consome: nada

**Teste RED**
- sem teste: refatoração sem mudança de comportamento; `CopilotHostButtonsIntegrationTest` (markup do link nas duas telas) prova a equivalência

**Critério de aceite**
- SUITE: `CopilotHostButtonsIntegrationTest`, `AiCopilotPanelIntegrationTest` e `AiNavigationIntegrationTest` com 0 `FAIL`; `/usr/bin/grep -c "data-cv-copilot' =>" src/app/control/clinic/PatientForm.php src/app/control/clinic/EncounterView.php src/app/control/clinic/AiCopilotPanel.php` → `0`, `0` e `1`; `/usr/bin/grep -n -B1 'private static function resolveTenantContext' src/app/control/clinic/PatientForm.php` mostra o fim do docblock (`*/`) na linha anterior.

**Validação**
- SUITE com `/usr/bin/grep -E 'CopilotHostButtonsIntegrationTest|AiCopilotPanelIntegrationTest|AiNavigationIntegrationTest|Failed:'` (evidência: todas as linhas `PASS`)
- LINT dos três controllers (evidência: `No syntax errors detected`)

### T-24 — Runbooks: 0015 no 5.7, códigos ai_*, flag de gestão, débitos, ticket médio, retenção, seed, âncora RESTRICT, motivos

**Camada:** docs
**Dependências:** T-08, T-09, T-15, T-21
**Paralelizável:** sim
**Complexidade:** simples
**Agente:** Gandalf

Origem: 8B T-18 ([aberta]: shared-hosting-mysql57.md:363-376 — "rode o `.verify.sql`" ambíguo no 5.7, onde o verify original consulta `information_schema.check_constraints` (o `scripts/prepare-mysql57.py` :96-111 troca CHECK por TRIGGER e grava o verify adaptado no `manifest.json`); "ai_interaction com a mesma contagem de antes" sem dizer de quê; falta o grep da ordem DROP TRIGGER → CREATE TRIGGER dos 6 CHECKs, como a seção 0011 faz em :186-190; ia-assistente.md:361 manda aos códigos `ai_*` "da tabela acima", que é a de `deny_reason` — os `ai_*` estão em :118-131), 8B T-24 ([aberta]: ia-assistente.md:391-397 sugere que basta `ai_copilot_management`; o código exige `ai_assistant` ligada e a indisponibilidade gera `ai_terms_pending`/`ai_quota_exceeded`/`ai_not_configured`), 8B T-21 (vencimento por dia × tela de pendências no próprio dia; `ticket_medio` inclui receita sem atendimento), 8B T-12 (DELETE em lote e trava da T-15), 8B T-17 (bloco 8B do seed concede só a grupos com EncounterView — no seed só o grupo 1; 5.7 conferido por inspeção), 8B T-01 (âncora `ON DELETE RESTRICT`: paciente com conversa não pode ser excluído até o purge de 90 dias), mudanças das T-08 (inativo em `consultar_agenda`), T-09 (lote vencido) e T-21 (rótulos de Motivo).

**Arquivos prováveis**
- `docs/runbooks/ia-assistente.md`
- `docs/runbooks/shared-hosting-mysql57.md`

**Interface**
- Produz: nada
- Consome: nada

**Teste RED**
- sem teste: documentação

**Critério de aceite**
- `docs/runbooks/shared-hosting-mysql57.md` § 0015 tem: conferência pelo verify adaptado do `manifest.json` (não o `.verify.sql` original), o comando `SELECT COUNT(*) FROM ai_interaction` antes e depois com a regra "mesmo número", e o grep da ordem DROP TRIGGER → CREATE TRIGGER dos 6 CHECKs de `ai_interaction` no arquivo preparado.
- `docs/runbooks/ia-assistente.md` tem: o parágrafo de falhas do copiloto apontando a tabela de códigos `ai_*` da 8A por link de âncora; a flag de gestão descrita como "exige `ai_assistant` ligada" com os motivos reais; vencimento por dia e a diferença no próprio dia; a fórmula de `ticket_medio` com a ressalva; trava contra execução sobreposta (`{"skipped":"locked"}`) e DELETE em lotes da retenção; seed só para grupos com `EncounterView`; âncora RESTRICT e o efeito em exclusão/anonimização; profissional inativo em `consultar_agenda`; lote vencido fora da validade; coluna "Rótulo na tela" na tabela de `deny_reason` com os 7 textos pt da T-21.

**Validação**
- `/usr/bin/grep -nE 'manifest.json|COUNT\(\*\) FROM ai_interaction|DROP TRIGGER' docs/runbooks/shared-hosting-mysql57.md` (evidência: linhas na seção 0015)
- `/usr/bin/grep -nE 'ai_assistant|ticket_medio|skipped|EncounterView|RESTRICT|Registro não encontrado|inativ|vencid' docs/runbooks/ia-assistente.md` (evidência: uma linha por tema)
- `/usr/bin/grep -c 'da tabela acima' docs/runbooks/ia-assistente.md` (evidência: `0`)
- `/usr/bin/grep -rnE 'text-align:\s*(right|center)' docs/runbooks/ia-assistente.md` (evidência: sem saída)

## Legenda

- [ ] pendente
- [~] em andamento
- [x] concluído com evidência
- [!] bloqueado
