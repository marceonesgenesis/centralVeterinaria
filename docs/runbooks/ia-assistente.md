# Assistente de IA (Fase 8A)

O assistente de IA é um gateway próprio (independente do provedor, com adaptador
Claude via Anthropic Messages API) que sugere texto ao profissional. Toda
sugestão passa por revisão humana antes de ser persistida. Decisão de
arquitetura em [`ADR 0006 (ai-gateway)`](../architecture/adr/0006-ai-gateway.md).

> Nenhum passo com efeito real (DDL/DML) roda sem autorização SQL específica
> do usuário (`sql-write-approval`). Este runbook não contém chave real; a
> chave só vive em variável de ambiente.

## Casos de uso

| Finalidade | Tela | O que persiste após a revisão |
|---|---|---|
| Resumo do paciente | `EncounterView` | Colunas `ai_summary_*` do atendimento, só se o profissional aceitar. |
| Organização da anamnese (texto livre ou ditado) | `EncounterView` | `anamnesis_text` = texto já digitado + linha em branco + texto aceito; nunca substitui o digitado. |
| Resumo de exame | `ExamResultForm` | Só `ai_interaction.final_text`; o `exam_result` original não muda. |

Em todos, o profissional vê a sugestão marcada como gerada por IA, pode editá-la
e então aceitar (`accepted`), aceitar com edição (`edited`) ou descartar
(`discarded`). Nada é gravado no prontuário sem essa decisão.

### Ditado e privacidade

O ditado usa a Web Speech API do navegador. O áudio é enviado ao serviço de voz
do fabricante do navegador (por exemplo Google, Apple ou Microsoft), não ao
Central Vet. A tela mostra esse aviso junto ao botão e, desde o termo
`2026-10-v2`, a frase do ditado faz parte do próprio termo de uso;
se o profissional preferir, digita o texto. Navegador sem suporte mostra o aviso
e mantém a digitação.

## Ativação por tenant

A IA vem **desligada** para todo tenant. Para ligar, o administrador (grupo 1)
usa `AiSettingsForm` (Configurações > Assistente de IA):

1. Aceitar o termo de uso, versão `2026-10-v2` (`AiTerms::VERSION`). Mudar o
   texto do termo exige uma nova versão. A v2 inclui a frase do ditado; quem
   aceitou a versão anterior `2026-10-v1` precisa **reaceitar**: até o
   administrador aceitar de novo em `AiSettingsForm`, a geração falha com
   `ai_terms_pending` (a clínica perde o assistente nesse intervalo). Não há
   migration nem DML para isso.
2. Ligar a flag `ai_assistant` (tabela `feature_flag`; ausência = desligado).
3. Ajustar a cota mensal de tokens (`ai_tenant_setting.monthly_token_quota`,
   padrão 300000; soma de tokens de entrada e saída no mês corrente). O mês
   corre no fuso `APP_TIMEZONE` da aplicação; a sessão MySQL precisa estar no
   mesmo fuso (`time_zone`), senão o corte do mês se desloca.

Sem termo aceito a geração falha com `ai_terms_pending`; com a flag desligada,
`ai_disabled`; com a cota esgotada, `ai_quota_exceeded`. As telas
`AiSettingsForm` e `AiInteractionList` são só do grupo 1; o uso clínico segue as
permissões das telas clínicas (`EncounterView`, `ExamResultForm`), com tenant e
unidade ativa.

## Configuração (variáveis de ambiente)

| Variável | Padrão | Observação |
|---|---|---|
| `AI_PROVIDER` | `anthropic` | `anthropic` ou `stub`. `stub` é recusado com `APP_ENV=production`. |
| `ANTHROPIC_API_KEY` | vazio | Chave da API (formato `sk-ant-...`). Vazia = `ai_not_configured`. |
| `ANTHROPIC_BASE_URL` | `https://api.anthropic.com` | Sem barra final. Só `https` (a chave viaja nessa URL); outro esquema falha com `ANTHROPIC_BASE_URL must be an https URL`. |
| `AI_MODEL` | `claude-sonnet-5-5` | Modelo pedido ao provedor. |
| `AI_EFFORT` | `low` | `low`, `medium`, `high`, `xhigh` ou `max`. |
| `AI_MAX_OUTPUT_TOKENS` | `8000` | 256 a 32000. |
| `AI_TIMEOUT_SECONDS` | `30` | 5 a 60; timeout total da chamada. |
| `AI_CONNECT_TIMEOUT_SECONDS` | `5` | 1 a 15. |
| `AI_FALLBACKS` | `default` | `default` liga o fallback server-side; `off` desliga. |
| `AI_STUB_BEHAVIOR` | `ok` | `ok` ou `timeout`; só com `AI_PROVIDER=stub`. |
| `APP_ENV` | `development` | Lido para recusar o stub em produção. Sem a variável vale `development`, então defina `production` na hospedagem. |

A chave entra **somente** por variável de ambiente (`ANTHROPIC_API_KEY`): nunca
em código, migration, banco, log, auditoria ou relatório. `AiConfig` a mascara
(`*****`) em `toArray()`/`__debugInfo()` e o logger e o redator de auditoria
mascaram `x-api-key`. A chamada é síncrona (uma por requisição, sem
retentativa); falha vira aviso traduzido e a tela segue.

## Custo

Cada uso grava uma linha em `ai_interaction` com finalidade, versão do prompt,
provedor, modelo devolvido, tokens (entrada, saída, cache), `cost_micro_usd`,
latência, `stop_reason`, id do pedido, código de erro, sugestão, texto final e
decisão. Da entrada só o SHA-256 (`input_sha256`) é guardado.

O custo é calculado por `AiModelPricing` em micro-USD (US$ por milhão de tokens
= micro-USD por token):

| Modelo | Entrada | Saída |
|---|---|---|
| `claude-sonnet-5-5` (padrão) e `claude-sonnet-5` | 2,00 | 10,00 |
| `claude-opus-5-5` | 4,00 | 20,00 |
| `claude-opus-5`, `claude-opus-4-8` | 5,00 | 25,00 |
| `claude-haiku-4-5` | 1,00 | 5,00 |
| `stub` | 0 | 0 |

Com fallback, tokens e custo somam todas as tentativas. Modelo ausente da tabela
fica sem custo calculado. O uso e o custo do mês aparecem em `AiSettingsForm`;
cada chamada, em `AiInteractionList`. Por tenant e mês (somente SELECT):

```sql
SELECT DATE_FORMAT(created_at, '%Y-%m') AS mes,
       COUNT(*) AS chamadas,
       SUM(input_tokens + output_tokens) AS tokens,
       SUM(COALESCE(cost_micro_usd, 0)) / 1000000 AS custo_usd
  FROM ai_interaction
 WHERE tenant_id = :tenant
 GROUP BY mes ORDER BY mes DESC;

SELECT purpose, status, error_code, COUNT(*) AS n
  FROM ai_interaction
 WHERE tenant_id = :tenant AND created_at >= :inicio_do_mes
 GROUP BY purpose, status, error_code;
```

A cota é lida antes da chamada: duas gerações simultâneas podem ultrapassá-la em
uma chamada; o excesso fica visível em `AiSettingsForm`.

## Motivos de falha e o que fazer

O motivo aparece na tela (`AI assistant is unavailable: <motivo>`) e em
`ai_interaction.error_code` (status `failed`).

| Código | Causa | Ação |
|---|---|---|
| `ai_disabled` | Flag `ai_assistant` desligada. | Ligar em `AiSettingsForm`, se desejado. |
| `ai_terms_pending` | Termo `2026-10-v2` não aceito (inclui quem aceitou só a `2026-10-v1`). | Administrador aceita o termo em `AiSettingsForm`. |
| `ai_quota_exceeded` | Tokens do mês >= cota. | Aumentar a cota ou aguardar o mês seguinte. |
| `ai_not_configured` | Sem chave ou configuração `AI_*` inválida. | Conferir env e `ext-curl`; reiniciar o PHP. |
| `ai_auth_failed` | Provedor recusou a chave (401/403). | Conferir/rotacionar a chave na Anthropic e no env. |
| `ai_rate_limited` | Limite de taxa do provedor (429). | Aguardar e tentar de novo; avaliar o limite da conta. |
| `ai_overloaded` | Provedor sobrecarregado (529/5xx de capacidade). | Tentar mais tarde; sem retentativa automática. |
| `ai_timeout` | Estourou `AI_TIMEOUT_SECONDS` ou conexão. | Tentar de novo; conferir saída HTTPS; ajustar o timeout. |
| `ai_refused` | Recusa por classificador (`stop_reason` `refusal`). | Seguir manualmente; o fallback já está ligado por padrão. |
| `ai_truncated` | Saída cortada (`max_tokens`). | Aumentar `AI_MAX_OUTPUT_TOKENS` ou reduzir a entrada. |
| `ai_provider_error` | Resposta inesperada ou texto vazio. | Ver `provider_request_id` em `ai_interaction`; reportar ao provedor se persistir. |
| `ai_empty_input` | Entrada vazia. | Preencher o texto. |
| `ai_input_too_long` | Entrada acima do limite do serviço. | Reduzir o texto. |

## Contexto clínico enviado

O contexto do paciente inclui também prescrições em rascunho (`draft`) e exames
pendentes de revisão (`pending_review`). Ambos seguem marcados no prompt como
provisórios/não validados, e o modelo é instruído a não tratá-los como fato
confirmado. Por isso as três versões de prompt estão em `.v2`; mudar o texto de
um prompt exige nova versão.

## Autosave do atendimento e decisão de IA

O autosave de `EncounterView` é versionado: a aba envia a versão do rascunho
(`draft_version`, SHA-256 só dos campos de rascunho: anamnese, sinais vitais,
mucosas, TPC, exame físico, diagnóstico e plano) e o servidor só grava se a
versão armazenada ainda é a mesma. A comparação e a gravação ocorrem na mesma
transação, com a linha lida por `SELECT ... FOR UPDATE`, então duas gravações
simultâneas não se sobrepõem. Pausar, retomar, finalizar e aceitar o resumo não
alteram `draft_version` nem regravam o rascunho, e por isso nunca viram
conflito. Se outra janela, outro profissional ou a decisão de IA alterou o
rascunho, é **conflito**: nada é gravado, a aba mantém o texto local e mostra o
aviso "A anamnese foi alterada em outra janela. Recarregue a página para
continuar editando." com dois botões:

- **Manter meu texto**: continua com o texto local e passa a usar a versão
  armazenada, de modo que o próximo autosave sobrescreve deliberadamente;
- **Recarregar a página**: descarta o texto local e carrega o armazenado.

Aba aberta antes de um deploy (sem `draft_version` válido) é tratada como
conflito e **não grava**: o profissional recarrega a página. Aplicar uma
sugestão de IA grava só a `anamnesis_text` pela mesma guarda, enviando o
`previous_draft` (o que o tique anterior da aba já enviou) para não confundir um
tique ainda em trânsito com alteração alheia; em conflito a decisão fica
`pending_review` e a tela mostra o aviso. Descartar não grava rascunho. Número
inválido ou fora da faixa da coluna é ignorado (mantém o valor armazenado) e a
tela avisa o campo; os demais campos são gravados.

Os sinais vitais numéricos têm mínimo 0: valor negativo conta como inválido.

Encontro **finalizado** recusa o autosave e o "Aplicar" da IA: sob o mesmo
`SELECT ... FOR UPDATE` o serviço confere o status e devolve o resultado
`closed`, sem gravar nada (o tique imprime `data-status="closed"`). Em corrida
com a finalização, "Aplicar" não é registrado; "Descartar" só registra a
decisão. Aceitar o resumo também lê sob o lock e é recusado em encontro
finalizado. Tick sem resposta válida (rede, erro do servidor, resposta sem a
marca do tique mais recente ou sem retorno em 25 s) mostra **erro visível** na
aba, em vez de "Salvo às ...", e o próximo tique tenta de novo.

## Minimização de PII (LGPD)

`PiiMinimizer` mascara CPF, telefone, e-mail e nomes conhecidos (paciente e
tutor) antes do envio. Uma allowlist clínica (`CLINICAL_TERMS`) só isenta
*partes* de nome que coincidem com termo clínico; o nome completo segue
mascarado.

Telefone: todo número com formato de telefone com prefixo vira `[TELEFONE]`:
`+55`, tronco/operadora (`0`, `(085)`), DDD entre parênteses ou DDD solto/colado.
**Celular de 9 dígitos (9 + 8) é sempre mascarado**, sem exceção. O fixo de 8
dígitos **sem DDD** (sem `+55`, tronco nem parênteses), por exemplo
`Tel: 3456-7890` ou `2000-5000`, **fica intacto**: sem prefixo não se distingue
de valor de exame. Com DDD, o fixo é mascarado, salvo as exceções:

- DDD solto ou colado seguido de unidade de exame (`LAB_UNITS`), como
  `Leucócitos 12 3500-4500 x10³/µL`, que fica intacto;
- DDD solto logo depois do marcador `VR`, `ref` ou `referência`.

Nenhuma exceção vale para número com `+55`, tronco `0` ou DDD entre parênteses.
Faixa **sem unidade** e com DDD solto (`Leucócitos 12 3500-4500`) sai como
`[TELEFONE]` e o modelo não a vê (conservador, por LGPD). Um número logo depois
de `\d,` ou `\d.` é tratado como decimal e fica intacto, salvo quando
`NN,` + 8–9 dígitos colados formam DDD válido com assinante de telefone.

Regra do celular depois de vírgula ou ponto (`\d[.,]` + 9 dígitos): só é decimal
se o número antes do separador tem **1 dígito**, ou 2 dígitos que não sejam DDD
válido, e não vem ele mesmo depois de `\d[.,]`. Intactos: `Hb 1,987654321` e
`Fator 10,987654321`. Mascarados: `Densidade 123,987654321` (3 dígitos) →
`123,[TELEFONE]`, `Tel 123,85987654321` → `123,[TELEFONE]` e
`38,5,987654321` → `38,5,[TELEFONE]`; `Fator 12,987654321` (12 é DDD) →
`[TELEFONE]`. É conservador (LGPD): o custo é mascarar alguns decimais longos.

Risco aceito: fixo de 8 dígitos com DDD **sem parênteses** (e sem `+55`) logo
depois de `VR`/`ref`, por exemplo `VR 85 3456-7890`, **não** é mascarado, porque
é indistinguível de uma faixa de referência como `VR: 12 3512-4587`, que o
usuário decidiu preservar. Com DDD entre parênteses ou `+55` (`VR (85)
3456-7890`) é mascarado.

O prompt leva só o necessário ao caso de uso, e a entrada
não é guardada (só o SHA-256). Em log, `error_log`, exceção, auditoria
(`metadata`) e relatório vão apenas ids, finalidade, modelo, tokens, custo e
códigos: nunca chave, `x-api-key`, prompt, texto livre, sugestão, nome, e-mail,
telefone ou CPF. `suggestion_text` e `final_text` ficam no banco (auditoria
clínica); não há expurgo no MVP.

## Stub e ambientes

`AI_PROVIDER=stub` devolve texto sintético (`[stub] <finalidade> v<versão>: ...`)
sem rede e serve a desenvolvimento e testes; é recusado com
`APP_ENV=production`. `AI_STUB_BEHAVIOR=timeout` simula `ai_timeout`. Testes
automatizados nunca chamam a API real.

## Incidente: desligar a IA

1. Desligar a flag `ai_assistant` do tenant em `AiSettingsForm` (efeito
   imediato; as telas escondem o painel e nada é enviado ao provedor).
2. Para todos os tenants ou chave vazada: remover/rotacionar
   `ANTHROPIC_API_KEY` no ambiente e revogá-la no console da Anthropic; sem
   chave, o resultado é `ai_not_configured`.
3. Consultar `AiInteractionList` e o `audit_log` (ações de geração, falha e
   decisão) para dimensionar o uso; nenhum dado pessoal consta ali além de ids.

## Banco (migration 0014)

Três tabelas novas: `feature_flag`, `ai_tenant_setting` e `ai_interaction`
(9 FKs, 8 CHECKs; o cabeçalho da 0014 diz 8 FKs, mas são 9, sem ALTER em tabela existente; nenhum dado semeado, a IA fica
desligada). `ai_interaction` não tem FK para o sujeito (atendimento ou resultado
de exame): tenant e unidade são regra da aplicação. Aplicação e preparo no
MySQL 5.7: [`shared-hosting-mysql57.md`](./shared-hosting-mysql57.md).

## Copiloto (Fase 8B)

O copiloto é um chat interno que responde perguntas lendo dados do sistema por
ferramentas **somente leitura**, em um laço de tool use síncrono no PHP sobre o
gateway da 8A. Decisão em
[`ADR 0007 (copiloto-ferramentas-mcp)`](../architecture/adr/0007-copiloto-ferramentas-mcp.md).
Não há endpoint MCP externo nem ferramenta de escrita. O painel é
`AiCopilotPanel` (cortina lateral); a 8B.1 entrega o painel clínico e a 8B.2, o
de gestão (veja [Copiloto de gestão (8B.2)](#copiloto-de-gestão-8b2)).

### Casos de uso (clínico)

- Vacinas atrasadas ou próximas do paciente.
- Resumo dos últimos atendimentos e leitura do prontuário de um atendimento.
- Exames pendentes e liberados; prescrições em uso.
- Comparar com outro paciente da mesma unidade.

### Ferramentas e permissão

Cada ferramenta exige a permissão da tela que já mostra o mesmo dado
(`requiredPermission`): quem não abre a tela não lê pelo copiloto. A ferramenta
sem permissão nem é oferecida ao modelo.

| Ferramenta | `requiredPermission` |
|---|---|
| `buscar_paciente` | `GlobalSearchController::onSearch` |
| `obter_paciente` | `PatientForm::onEdit` |
| `obter_historico_atendimentos` | `EncounterView::onReload` |
| `obter_prontuario` | `EncounterView::onReload` |
| `obter_prescricoes` | `PrescriptionForm::__construct` |
| `obter_exames` | `ExamResultForm::__construct` |
| `consultar_vacinacao` | `VaccinationCardView::onReload` |

Além dessas, o painel exige `AiCopilotPanel::onAsk` (perguntar), `onOpen`
(abrir conversa) e `onDelete` (apagar), sempre com unidade ativa.

### Limites

| Limite | Valor |
|---|---|
| Rodadas de ferramentas por pergunta | 4 (`AiCopilotService::MAX_ROUNDS`) |
| Orçamento de tempo por pergunta | 45 s (`AiCopilotService::BUDGET_SECONDS`); estourar vira resposta de "não consegui concluir", não erro |
| Chamadas de ferramenta por usuário | 30 por 10 min (`ToolRateLimiter`, Redis ou `RATE_LIMIT_DRIVER=files`) |
| Linhas por resultado | 50, com `truncated` |
| Tamanho do resultado | 16 KB (`ToolPolicy::MAX_BYTES`) |
| Tempo por ferramenta | 5 s: hint `MAX_EXECUTION_TIME(5000)` nas SELECTs e descarte acima de 5000 ms |

Na hospedagem compartilhada sem Redis, use `RATE_LIMIT_DRIVER=files`; com o
driver `redis` indisponível o painel mostra a IA como indisponível
(`ai_provider_error`). A **cota mensal de tokens é verificada uma vez por
pergunta** (antes do laço); uma pergunta pode fazer até 5 chamadas ao provedor
(4 rodadas e a resposta final) e ultrapassar a cota em uma pergunta.

### Regra de unidade do paciente

Paciente "da unidade" é o que tem atendimento (`encounter`) ou agendamento
(`appointment`) na unidade ativa; a tabela `patient` não tem unidade. As
ferramentas só devolvem registros clínicos de atendimentos dessa unidade. Um id
de outra unidade ou outro tenant responde "não encontrado", sem revelar que
existe. Sem unidade ativa nada é lido.

### Auditoria

Toda chamada, permitida ou negada, grava uma linha em `mcp_tool_call` (ferramenta,
entrada minimizada de até 4096 bytes, decisão, motivo, linhas, `truncated`,
duração, conversa e correlação), sem o conteúdo devolvido. O grupo 1 consulta em
`AiToolCallList` (Configurações > Assistente de IA > Auditoria de ferramentas;
filtros por usuário, período, ferramenta e decisão, no máximo 200 linhas). Cada pergunta
também grava **uma** linha em `ai_interaction` com finalidade `copilot_clinical`
(ou `copilot_management`), `subject_type = ai_conversation` e status novo
`answered` (uso e custo somados das rodadas; `suggestion_text` e `final_text`
nulos, ou `failed` com `error_code`). Cota e custo da 8A valem para o copiloto.

### Histórico e retenção

Só o **autor** vê as próprias conversas, dentro do tenant e da unidade, em
`AiConversationList` ("Minhas conversas"), e pode apagá-las; o gestor vê a
auditoria, nunca o texto. "Minhas conversas" funciona mesmo com a IA desligada
(direito de apagar os próprios dados). A retenção é de **90 dias** sem
atividade: o script apaga as conversas ociosas de todos os tenants, em lotes
(mensagens caem por `ON DELETE CASCADE`); `mcp_tool_call` e `ai_interaction`
ficam. O `DELETE` é feito em lotes (tamanho e número máximo de lotes por
execução configuráveis em `AiConversationRetention::forConnection`, ambos >= 1),
para não segurar travas longas. Rode uma vez por dia, a partir da raiz de `src/`:

```cron
# Retenção de 90 dias das conversas do copiloto (diária, 03:30)
30 3 * * * cd /caminho/da/aplicacao/src && php bin/ai-conversation-retention.php >> /caminho/dos/logs/ai-conversation-retention.log 2>&1
```

A saída é uma linha JSON `{"deleted":n,"batches":b}`; em erro só a classe da
exceção vai ao `error_log` e o código de saída é 1. Há trava contra execução
sobreposta (`flock` em arquivo no diretório temporário): se outra execução
ainda roda, a saída é `{"skipped":"locked"}` com código 0 e nada é apagado;
isso é normal, não é erro, e a próxima execução do cron retoma. Não há texto de pergunta ou
resposta no log.

**Âncora de paciente (`ON DELETE RESTRICT`).** A conversa clínica guarda
`anchor_patient_id` com FK `ai_conversation_anchor_patient_fk` em `patient`,
`ON DELETE RESTRICT`: um paciente com conversa não pode ser excluído
fisicamente até o purge de 90 dias apagar a conversa (ou o autor apagá-la em
"Minhas conversas"). Quem exclui ou anonimiza paciente por script deve apagar
antes as conversas dele; o erro de FK é o sintoma esperado.

### Motivos de recusa de ferramenta

Gravados em `mcp_tool_call.deny_reason`; o modelo recebe só uma mensagem fixa.

| `deny_reason` | Rótulo na tela | Causa |
|---|---|---|
| `not_offered` | Ferramenta não oferecida | Ferramenta fora da família, da flag ou da permissão do usuário. |
| `forbidden` | Ferramenta sem permissão | A permissão mudou entre a oferta e a execução, ou o contexto da chamada não é o do registro. |
| `invalid_input` | Entrada inválida | Entrada fora do schema ou acima de 4096 bytes. |
| `not_found` | Registro não encontrado | Id inexistente, de outra unidade ou outro tenant. |
| `rate_limited` | Limite de chamadas atingido | 30 chamadas em 10 min, ou limiter indisponível (fail-closed). |
| `timeout` | Tempo limite excedido | Resultado acima de 5 s, descartado. |
| `error` | Falha inesperada | Falha inesperada (só a classe é logada). |

A coluna "Motivo" de `AiToolCallList` mostra o rótulo traduzido (pt); o valor
gravado em `deny_reason` continua sendo o código da primeira coluna.

Falha do copiloto na pergunta usa os códigos `ai_*` da tabela de
[Motivos de falha e o que fazer](#motivos-de-falha-e-o-que-fazer) (8A, acima no
documento), não a de `deny_reason`. A flag `ai_assistant` (e
`ai_copilot_management` na gestão) desligada dá `ai_disabled`.

### Incidente

Desligar a flag `ai_assistant` do tenant em `AiSettingsForm` esconde o painel e
nada vai ao provedor; para todos os tenants ou chave vazada, siga
[Incidente: desligar a IA](#incidente-desligar-a-ia). Dimensione o uso em
`AiToolCallList` e `AiInteractionList`.

### Smoke manual com chave real

Só em ambiente de teste, com `ANTHROPIC_API_KEY` real no env e a IA ligada
(termo aceito, flag `ai_assistant`). Nunca cole dado de cliente real nem a chave
em log ou relatório.

1. Abra o copiloto numa tela de paciente da unidade ativa.
2. Pergunte: "Quais vacinas deste paciente estão atrasadas?".
3. Confira: a resposta cita "Consultei: ..." com `consultar_vacinacao`; surge
   uma linha `allowed` em `AiToolCallList`; surge uma linha `answered` em
   `AiInteractionList`; a conversa aparece em "Minhas conversas".
4. Com um usuário sem a permissão da tela de vacinas, repita: a ferramenta não
   é oferecida (`not_offered`) e a resposta não traz o dado.

### Copiloto de gestão (8B.2)

O painel de gestão é o mesmo `AiCopilotPanel`, com a família de ferramentas de
gestão (`ManagementToolset`). Continua somente leitura e sem endpoint MCP externo.

**Ativação.** Flag `ai_copilot_management`, independente de `ai_enabled`. Ligue
no interruptor "copiloto de gestão" de `AiSettingsForm`, gravado no mesmo
`onSave` (`AiSettingsService::setCopilotManagement(bool $enabled): void`, com
RBAC de `AiSettingsForm::onSave` e auditoria
`ai.settings.copilot_management_enabled|_disabled`). Sem linha em
`feature_flag`, fica desligada. Flag desligada ou IA indisponível: o painel
mostra o motivo `ai_disabled`. A flag de gestão **não basta**: o painel exige também
`ai_assistant` ligada (a de gestão só libera a família de ferramentas), o termo
aceito e a cota/configuração em dia. A indisponibilidade segue a ordem
`ai_disabled` (qualquer das flags) → `ai_terms_pending` → `ai_quota_exceeded`
→ `ai_not_configured`; veja a tabela de
[motivos de falha](#motivos-de-falha-e-o-que-fazer).

**Ferramentas.**

| Ferramenta | `requiredPermission` |
|---|---|
| `consultar_agenda` | `AgendaView::onReload` |
| `verificar_horarios` | `AgendaView::onReload` |
| `consultar_estoque` | `ProductList::onReload` |
| `listar_debitos` | `PendingReceivableList::onReload` |
| `indicadores_clinica` | `FinancialOverview::onFilter` |

**Regras.**

- Vencimento de débito = `receivable.created_at` + 7 dias (o schema não tem
  vencimento; mesma regra de `PendingItemQuery::openReceivables`). Débitos por
  unidade via `encounter_account.system_unit_id`. O vencimento é comparado por
  **dia**: um débito criado há 7 dias só fica "vencido" no dia seguinte e,
  no próprio dia do vencimento, aparece como "a vencer" (`>=` hoje), o que
  pode diferir da tela de pendências, que já o lista nesse dia.
- `indicadores_clinica`: `ticket_medio` = receita / atendimentos iniciados no
  período (arredondado ao centavo), `null` sem atendimentos. A receita inclui
  lançamentos de entrada sem atendimento (ex.: venda avulsa), então o ticket
  médio pode superar o valor médio dos atendimentos; leia-o como receita por
  atendimento, não como ticket de atendimento.
- `consultar_agenda`: profissional inativo ainda pode ser consultado (pode ter
  agendamentos antigos); `verificar_horarios` só aceita profissional ativo.
- Estoque: lote vencido sai da validade/lote em `abaixo_minimo` e `termo`
  (comparado com `CURDATE()` do MySQL, o mesmo relógio das gravações);
  `vencendo_30d` usa o relógio da aplicação.
- Estoque: saldo só da unidade ativa; `minimum` nulo quando o mínimo é 0.
- Horários livres: grade 07:00-19:00 em passos de 30 min (a da `AgendaView`),
  calculada por `FreeSlotFinder`; só horários futuros. A ocupação do profissional
  vale no tenant inteiro (inclui outra unidade, sem dados do paciente), como
  `AppointmentService::assertNoConflict`.
- `consultar_agenda` sem data usa hoje; `data` e intervalo juntos, ou intervalo
  acima de 7 dias, é entrada inválida (`invalid_input`). Débito mostra só o
  primeiro nome do tutor.
- Limites, auditoria e motivos de recusa são os do copiloto clínico.

**Menu.** Item "Copiloto de gestão" no submenu de IA (abre o painel de gestão;
não há dashboard clínico). Precisa da permissão de `AiCopilotPanel::onAsk` e do
vínculo de menu aplicado pelo seed 8B.

**Seed.** O bloco 8B de `initial-application-programs.sql` concede
`AiCopilotPanel` e `AiConversationList` só aos grupos que já têm
`EncounterView` (no seed, só o grupo 1, Template - Admin) e `AiToolCallList`
só ao grupo 1; outros grupos precisam de vínculo manual. O bloco no MySQL 5.7
foi conferido só por inspeção (sem imagem 5.7 local).

**Smoke de gestão (chave real).** Só em ambiente de teste, com a IA e a flag
`ai_copilot_management` ligadas, sem dado de cliente real em log ou relatório.

1. Abra "Copiloto de gestão" no menu de IA.
2. Pergunte: "Quais débitos estão vencidos?".
3. Confira: a resposta cita "Consultei: ..." com `listar_debitos`; surge linha
   `allowed` em `AiToolCallList` e linha `answered` em `AiInteractionList`.
4. Com usuário sem a permissão de `PendingReceivableList::onReload`, repita: a
   ferramenta não é oferecida (`not_offered`).
