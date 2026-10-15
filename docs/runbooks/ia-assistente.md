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
