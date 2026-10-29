# Fase 8B — Copiloto com ferramentas MCP (design)

- Data: 2026-10-15
- Branch: `feat/fase-8b-copiloto-mcp` (criada a partir de `feat/fase-8a-ai-gateway` @ `2a77bef`, porque depende da 8A ainda não mesclada em `main`)
- Fontes: PRD v1.1 § 10 (`PRD_Central_Vet_Pro_v1_1_MCP.docx`), roadmap (`.docs/plan/centralvet-roadmap.md` L36-37, L100), ADR 0001, 0004 e 0006.

## 1. Objetivo

Dar ao profissional da clínica um **copiloto conversacional dentro do Central Vet** que responde perguntas lendo dados do sistema por **ferramentas somente leitura com políticas**. São dois painéis sobre um núcleo comum:

- **Clínico** (tela do paciente e do atendimento): vacinas atrasadas, resumo dos últimos atendimentos, exames pendentes, prescrições em uso, comparação com outro paciente da unidade.
- **Gestão** (dashboard): agenda do dia, horários livres, estoque baixo, débitos em aberto, indicadores da clínica.

Sucesso significa:

1. As perguntas típicas de cada painel são respondidas com dados reais em até cerca de 45 s.
2. Nenhuma leitura sai do tenant e da unidade nem passa da permissão do usuário.
3. Toda chamada de ferramenta fica auditada.
4. O texto enviado ao provedor passa pelo `PiiMinimizer`.

## 2. Decisões tomadas com o usuário

| Tema | Decisão |
|---|---|
| Consumidor | Copiloto **interno** do app. Não há endpoint MCP externo na 8B; o contrato das ferramentas segue o formato MCP para permitir expor depois. |
| Uso | Os dois painéis, clínico e gestão, sobre um núcleo único. A entrega é em duas partes: 8B.1 (núcleo + clínico) e 8B.2 (gestão). |
| Conversa | Chat com **histórico salvo**. |
| Histórico | Só o **autor** vê as próprias conversas, dentro do tenant e da unidade; pode apagá-las; **90 dias** de retenção. Gestor vê só a auditoria, não o texto. |
| Escopo clínico | **Busca na unidade**: as ferramentas aceitam qualquer paciente da unidade que o RBAC do usuário permite ver. |
| Execução | **Laço de tool use síncrono no PHP** (abordagem A), com no máximo 4 rodadas de ferramentas e orçamento de 45 s por pergunta. |
| Stack | PHP do próprio app. O servidor Node do PRD foi descartado porque a hospedagem compartilhada não tem processo contínuo nem Node. Também não entra SDK novo, porque `composer.json` é protegido por `framework_hashes.php` (ADR 0006). |

Fora da 8B: ferramentas de escrita, confirmação humana e agentes (8C); endpoint MCP externo; streaming de resposta.

## 3. Arquitetura

```
Tela (painel) ──POST──▶ AiCopilotService ──▶ AiGatewayInterface (tools)
                          │   ▲                    │
                          │   └── tool_result ◀────┤ stop_reason=tool_use
                          ▼
                     ToolPolicy ──▶ ToolRegistry ──▶ AiTool::execute
                          │                             │
                          ▼                             ▼
                    mcp_tool_call               Services / Repositories
                                                 (TenantQuery, sem SQL ad hoc)
```

### 3.1 Núcleo de ferramentas (`src/app/Core/Ai/Tools/`)

- **`AiTool`** (interface):
  - `name()`, `description()` e `inputSchema()` (JSON Schema, sem `additionalProperties`).
  - `requiredPermission()`: a ação `Classe::metodo` da tela que já mostra o mesmo dado. Quem não abre a tela não lê pela ferramenta.
  - `family()`: `clinical` ou `management`.
  - `execute(ToolContext, array $input): ToolResult`.
- **`ToolContext`** (imutável, montado só pelo servidor): `tenantId`, `unitId`, `userId`, `correlationId`, `conversationId`, `purpose` e `anchorPatientId` (paciente da tela, quando houver).
- **`ToolResult`**: `data` (array serializável), `rowCount` e `truncated`.
- **`ToolRegistry`**: monta a lista de ferramentas visíveis para a família e o usuário. Uma ferramenta só aparece se:
  - está na allowlist da família;
  - a flag da família está ligada;
  - o RBAC aprova `requiredPermission`.

  A lista vira as definições `tools` enviadas ao modelo.
- **`ToolPolicy`**: portão antes de cada `execute`. Qualquer falha nega a chamada (**fail-closed**) e grava a recusa. As checagens, nesta ordem:
  1. A ferramenta existe e está visível para o usuário. Ferramenta pedida pelo modelo que não foi oferecida é negada.
  2. O `input` valida contra o schema (validador JSON Schema mínimo próprio, sem dependência nova).
  3. `RbacAuthorizationService::decide` aprova a permissão no tenant e na unidade do contexto.
  4. Os ids de entidade recebidos (paciente, tutor, atendimento) pertencem ao tenant e à unidade. Isso é verificado pelo repositório com `TenantQuery`; id de outra unidade é tratado como "não encontrado".
  5. O rate limit por usuário passa (no máximo 30 chamadas de ferramenta por 10 min), usando o backend do `LoginRateLimiter` (Redis ou arquivo).
- **Limites de saída**:
  - no máximo 50 linhas por resultado (com `truncated=true` quando cortado);
  - no máximo 16 KB serializados;
  - tempo limite de 5 s por ferramenta.

  Os textos clínicos do resultado passam pelo `PiiMinimizer` antes de voltar ao modelo.

### 3.2 Ferramentas

As ferramentas reaproveitam services e repositórios existentes; nenhuma escreve. Para cada uma, o plano registra a `requiredPermission` concreta, que é a ação da tela que hoje lista ou mostra o mesmo dado.

**8B.1 — família `clinical`**

| Ferramenta | Entrada | Saída (resumo) |
|---|---|---|
| `buscar_paciente` | `termo` (nome do animal, nome do tutor ou nº do prontuário), `limite` ≤ 20 | id, nome, espécie, raça, idade, tutor (só o primeiro nome) |
| `obter_paciente` | `patient_id` (padrão: âncora) | dados clínicos básicos, alergias, condições crônicas, peso atual |
| `obter_historico_atendimentos` | `patient_id`, `limite` ≤ 10 | data, motivo, diagnóstico, conduta resumida |
| `obter_prontuario` | `encounter_id` | anamnese, exame físico, diagnóstico, plano (minimizados) |
| `obter_prescricoes` | `patient_id`, `situacao` (`ativas` / `todas`) | medicamento, dose, posologia, status (rascunho marcado como provisório) |
| `obter_exames` | `patient_id`, `situacao` (`pendentes` / `liberados` / `todos`) | exame, data, status, resultado resumido (`pending_review` marcado como provisório) |
| `consultar_vacinacao` | `patient_id` | vacina, data aplicada, próxima dose, atrasada (sim/não) |

**8B.2 — família `management`** (flag `ai_copilot_management`)

| Ferramenta | Entrada | Saída (resumo) |
|---|---|---|
| `consultar_agenda` | `data` ou intervalo de no máximo 7 dias, `profissional_id` opcional | horário, paciente, tipo, status |
| `verificar_horarios` | `data`, `duracao_min`, `profissional_id` opcional | lista de horários livres |
| `consultar_estoque` | `filtro` (`abaixo_minimo` / `vencendo_30d` / `termo`) | item, saldo, mínimo, validade |
| `listar_debitos` | `situacao` (`vencidos` / `a_vencer`), `limite` ≤ 50 | tutor (só o primeiro nome), valor, vencimento, dias em atraso |
| `indicadores_clinica` | `periodo` (`mes_atual` / `mes_anterior` / `ultimos_30d`) | atendimentos, ticket médio, receita, novos pacientes, retorno |

Nenhuma ferramenta devolve CPF, telefone, e-mail ou endereço. O tutor aparece só pelo primeiro nome.

### 3.3 Laço de conversa (`AiCopilotService`)

1. A tela envia `conversation_id` (ou nenhum, para conversa nova), `family`, `anchor_patient_id` (só no clínico) e `pergunta`.
2. O serviço checa a disponibilidade da 8A, na ordem flag `ai_assistant` → termo → cota → configuração. No painel de gestão, também a flag `ai_copilot_management`.
3. Carrega a conversa, verificando que pertence ao autor, ao tenant e à unidade. Pega as últimas 20 mensagens e acrescenta a pergunta, minimizada.
4. Chama o gateway com `system` (prompt versionado da família, no `AiPromptCatalog`), mensagens e `tools`.
5. Enquanto `stop_reason = tool_use`, por no máximo **4 rodadas** e dentro do **orçamento de 45 s**:
   - executa cada `tool_use` pela `ToolPolicy`;
   - devolve o resultado como `tool_result`, ou um erro curto como `is_error`.
6. Ao atingir o limite de rodadas ou de tempo, pede ao modelo uma resposta final **sem** ferramentas. Se o tempo já acabou, responde "não consegui concluir a consulta, tente uma pergunta mais específica".
7. Grava a pergunta e a resposta em `ai_conversation_message` e o uso em `ai_interaction`, com propósito `copilot_clinical` ou `copilot_management`, para a cota e o custo da 8A continuarem valendo para o copiloto.
8. A resposta mostra, abaixo do texto, as ferramentas consultadas, por exemplo "Consultei: vacinação, histórico (3)".

O `correlation_id` é um por pergunta e liga `ai_interaction`, as linhas de `mcp_tool_call` e o log.

### 3.4 Gateway

- `AiCompletionRequest` ganha `tools` (lista de definições) e mensagens com blocos `tool_use` / `tool_result`.
- `AiCompletionResult` passa a expor `stopReason` e `toolUses` (id, nome, input).
- `AnthropicMessagesGateway` envia `tools` e lê `stop_reason: tool_use`. Nada muda para quem não envia `tools`, como o assistente da 8A.
- `StubAiGateway` ganha roteiro programável (sequência de `tool_use` e depois texto) para os testes.
- O fallback de modelo e a precificação por modelo da 8A continuam valendo em cada rodada.

### 3.5 Persistência

Migration nova `0015`. A execução depende de aprovação de SQL do usuário, e o script precisa ser compatível com MySQL 5.7.

- **`ai_conversation`**: `id`, `tenant_id`, `system_unit_id`, `user_id`, `family`, `anchor_patient_id` (nulo), `title` (primeiros 80 caracteres da primeira pergunta, minimizados), `created_at`, `updated_at`. Índice em (`tenant_id`, `user_id`, `updated_at`).
- **`ai_conversation_message`**: `id`, `conversation_id` (FK com `ON DELETE CASCADE`), `role` (`user` / `assistant`), `content` (texto já minimizado), `tool_summary` (JSON curto com nomes e contagens), `created_at`.
- **`mcp_tool_call`**: `id`, `tenant_id`, `system_unit_id`, `user_id`, `conversation_id`, `correlation_id`, `tool_name`, `input_minimized` (JSON passado pelo `PiiMinimizer`/`AuditRedactor`), `decision` (`allowed` / `denied`), `deny_reason`, `row_count`, `truncated`, `duration_ms`, `created_at`. Índices (`tenant_id`, `created_at`), (`user_id`, `created_at`), (`tool_name`, `created_at`) e (`correlation_id`).
- **Retenção**: um comando do worker de cron apaga `ai_conversation` (e as mensagens, em cascata) com `updated_at` acima de 90 dias, em lotes. `mcp_tool_call` e `ai_interaction` **não** são apagadas, porque são auditoria.

O `content` grava o texto já minimizado, então o histórico não guarda telefone, CPF nem e-mail. O texto clínico fica, por ser o próprio conteúdo da conversa do autor.

### 3.6 Telas

- **Painel clínico**: um botão "Copiloto" abre uma cortina lateral na tela do paciente e na do atendimento, com o paciente da tela como âncora. Mostra a conversa atual, o campo de pergunta, o histórico do autor para o paciente e o botão "Apagar conversa".
- **Painel de gestão**: a mesma cortina no dashboard, sem âncora.
- **Menu IA**: a lista "Minhas conversas" (autor, família, data, título), com abrir e apagar.
- **Configurações de IA**: um interruptor para a família de gestão. O gestor vê a auditoria de ferramentas (`mcp_tool_call`), com filtros por usuário, ferramenta, decisão e período, sem o texto das conversas.
- **Layout e textos**: rótulos à esquerda, alvos de toque de 44 px (`--cv-touch-target`), textos pt/en no `translations.json` e estado "pensando…" com live region acessível.

## 4. Erros e casos-limite

| Situação | Comportamento |
|---|---|
| IA indisponível (flag, termo, cota, configuração) | O painel mostra o motivo, como na 8A. Nenhuma chamada sai. |
| Ferramenta negada (RBAC, schema, id de outra unidade, rate limit) | `tool_result` com `is_error` e motivo genérico ("sem permissão" / "não encontrado" / "limite atingido"), mais uma linha `denied` em `mcp_tool_call`. O modelo continua e explica ao usuário. |
| Exceção dentro da ferramenta | Erro genérico ao modelo, exceção registrada no log com `correlation_id` e uma linha `denied` com `deny_reason=error`. Detalhe interno nunca vai ao modelo. |
| Limite de rodadas ou de tempo | Resposta final sem ferramentas, ou a mensagem de tempo esgotado. A pergunta e a resposta são gravadas. |
| Erro do provedor | A mesma mensagem e o mesmo registro da 8A. A pergunta é gravada e a resposta fica marcada como falha, para o usuário poder reenviar. |
| Conversa de outro usuário, tenant ou unidade | Tratada como "não encontrada". |
| Paciente âncora de outra unidade | O painel não abre. |
| Modelo pede ferramenta não oferecida | A chamada é negada (`denied`, `not_offered`). |

## 5. Testes

- **Unitários**:
  - `ToolPolicy`: cada motivo de recusa, falha fechada e ordem das checagens.
  - Validador de schema.
  - `ToolRegistry`: visibilidade por flag e por RBAC.
  - Cada ferramenta com repositório fake: filtro por tenant e unidade, limites, truncamento e campos proibidos ausentes (CPF, telefone, e-mail, endereço).
  - `AiCopilotService` com o stub roteirizado: 0, 1 e 4 rodadas, limite de rodadas, orçamento de tempo, ferramenta negada no meio do laço, erro do provedor e gravação de histórico e de `ai_interaction`.
  - `AnthropicMessagesGateway`: serialização de `tools` / `tool_use` / `tool_result` e leitura de `stop_reason`.
- **Integração** (banco de teste):
  - Ferramentas reais contra os repositórios, incluindo paciente de outra unidade devolvido como "não encontrado".
  - `mcp_tool_call` gravado com input minimizado.
  - Retenção de 90 dias que apaga as conversas e mantém a auditoria.
  - Telas: painel abre, envia, lista e apaga; textos em pt; rótulos à esquerda.
- **Smoke manual**: com `ANTHROPIC_API_KEY` real, uma pergunta de cada painel (roteiro no runbook).

## 6. Entrega

- **8B.1** — Núcleo (`AiTool`, `ToolContext`, `ToolRegistry`, `ToolPolicy`, validador), gateway com tools, `AiCopilotService`, migration `0015`, as 7 ferramentas clínicas, painel clínico, "Minhas conversas", auditoria para o gestor, retenção, runbook `ia-assistente.md` e ADR 0007.
- **8B.2** — As 5 ferramentas de gestão, a flag `ai_copilot_management`, o painel de gestão e o interruptor nas configurações.

A 8B.2 depende só do núcleo da 8B.1. Cada parte tem critério de aceite próprio no plano.
