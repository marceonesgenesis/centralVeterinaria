# ADR 0007: Copiloto com ferramentas MCP somente leitura

- Status: aceito
- Data: 2026-10-15

## Contexto

A Fase 8B dá ao profissional um chat interno que responde perguntas lendo
dados do sistema (vacinas, atendimentos, exames, prescrições; na 8B.2 agenda,
estoque e financeiro). A hospedagem é compartilhada (PHP, MySQL 5.7, sem
processo contínuo nem Node) e `src/composer.json` é protegido por
`framework_hashes.php` (ADR 0006). Os dados são clínicos e pessoais (LGPD), e
o acesso deve respeitar tenant, unidade e a permissão do usuário.

## Decisão

- Laço de tool use síncrono no PHP (`AiCopilotService`) sobre o gateway da 8A:
  no máximo 4 rodadas de ferramentas e 45 s por pergunta; a chamada final usa
  as mesmas ferramentas com `tool_choice none`.
- Ferramentas somente leitura no formato MCP (nome, descrição, JSON Schema),
  internas ao app; não há endpoint MCP externo nem escrita (8C).
- `ToolPolicy` fail-closed: toda exceção vira recusa auditada. Cada ferramenta
  exige a permissão da tela que mostra o mesmo dado (`requiredPermission`),
  tenant e unidade vêm só do servidor, 30 chamadas por 10 min por usuário,
  50 linhas, 16 KB e 5 s por resultado, com `PiiMinimizer` no que vai ao modelo.
- Auditoria em `mcp_tool_call` (sem o conteúdo devolvido) e uma linha de
  `ai_interaction` por pergunta, com status `answered`; a 0015 amplia os 6
  CHECKs dela.
- Histórico em `ai_conversation` e `ai_conversation_message`, visível só ao
  autor, com retenção de 90 dias por cron diário (`bin/ai-conversation-retention.php`).
- Paciente "da unidade" = com atendimento ou agendamento na unidade ativa; id
  de fora responde "não encontrado".

## Alternativas

- **Servidor MCP em Node (PRD):** descartado; a hospedagem não tem Node nem
  processo contínuo.
- **SDK oficial da Anthropic ou biblioteca MCP:** exigiria editar
  `src/composer.json` (protegido); o gateway curl já existe.
- **Endpoint MCP externo:** fora da 8B; o formato das ferramentas permite
  expô-lo depois.
- **Guardar texto da resposta em `ai_interaction`:** descartado; o texto fica
  só na conversa, sob a retenção de 90 dias.

## Consequências

- PHP-FPM pode ficar ocupado até 45 s por pergunta; a cota é checada uma vez
  por pergunta e pode ser ultrapassada (até 5 chamadas ao provedor).
- O limite de 5 s por ferramenta não é preemptivo: usa o hint
  `MAX_EXECUTION_TIME` nas SELECTs e descarta o resultado tardio.
- Sem Redis, o limiter usa `RATE_LIMIT_DRIVER=files`.
- A 0015 aplica DROP/ADD de CHECK em `ai_interaction`; exige janela curta e
  backup (ver `shared-hosting-mysql57.md`).
- Paciente com conversa ancorada não pode ser apagado fisicamente (FK
  `ON DELETE RESTRICT`).
