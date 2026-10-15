# ADR 0006: AI Gateway próprio com adaptador Claude

- Status: aceito
- Data: 2026-10-07

## Contexto

A Fase 8A traz IA contextual (resumo do paciente, organização da anamnese e
resumo de exame) com revisão humana antes de persistir. A hospedagem é
compartilhada (PHP, MySQL 5.7, sem worker contínuo) e `src/composer.json` está
entre os arquivos protegidos por `framework_hashes.php`. O provedor pode mudar,
a chave não pode vazar e dados clínicos são pessoais (LGPD).

## Decisão

- Gateway próprio (`AiGatewayInterface`) independente do provedor, com adaptador
  `AnthropicMessagesGateway` que chama `POST /v1/messages` por curl, sem
  streaming. `StubAiGateway` (fora de produção) e `UnconfiguredAiGateway`
  completam as implementações.
- Chamada síncrona: uma por requisição, timeout total de 30 s e de conexão de
  5 s, sem retentativa; falha vira aviso traduzido (13 motivos `ai_*`).
- Modelo padrão `claude-sonnet-5-5` com effort `low`, ambos configuráveis por
  env (`AI_MODEL`, `AI_EFFORT`); o modelo não recebe `thinking`, `temperature`,
  `top_p` nem `top_k`. Fallback server-side (`AI_FALLBACKS=default`) ligado.
- IA desligada por padrão por tenant: feature flag `ai_assistant`, termo
  `2026-10-v1` e cota mensal de tokens; uso e custo (micro-USD) em
  `ai_interaction`, da entrada só o SHA-256.
- Revisão humana obrigatória: a sugestão só persiste após aceite ou edição do
  profissional; autorização pelas permissões das telas hospedeiras, com tenant
  e unidade.
- PII minimizada antes do envio; a chave só em env e mascarada em log e
  auditoria.
- Ditado pela Web Speech API do navegador: o áudio vai ao serviço de voz do
  fabricante do navegador, declarado ao usuário (aviso e termo).

## Alternativas

- **SDK oficial `anthropic-ai/sdk`:** recomendado por padrão, mas exige editar
  `src/composer.json` (protegido) e só um endpoint não streaming é usado; o
  projeto já tem o padrão curl (`S3Client`). Trocar pelo SDK mexe só no
  adaptador.
- **Fila/worker:** a revisão ocorre na mesma interação e o worker da hospedagem
  roda por cron `--once`; descartada, a reavaliar se o uso crescer.
- **Prompt caching:** não usado; o prompt de sistema fica abaixo do mínimo
  cacheável (as colunas de cache existem para o custo ficar certo).

## Consequências

- Trocar de provedor ou de SDK afeta só um adaptador.
- Requer `ext-curl` e HTTPS de saída para `api.anthropic.com:443` na hospedagem.
- PHP-FPM pode ficar ocupado até 30 s por chamada lenta; a cota pode ser
  ultrapassada em uma chamada por concorrência.
- Sugestões descartadas ficam em `ai_interaction` (auditoria); expurgo fora do
  escopo.
- O áudio do ditado sai do controle do Central Vet; o aviso é obrigatório.
- Operação em [`ia-assistente.md`](../../runbooks/ia-assistente.md).
