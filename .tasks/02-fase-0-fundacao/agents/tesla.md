# Tesla — serviços de plataforma (subagent_type: general-purpose)

## Contexto
Implementar Redis, storage e observabilidade como adapters substituíveis.

## Tasks atribuídas
- T-07: cache, locks e fila com retry/dead-letter.
- T-08: storage S3-compatible e MinIO local opcional.
- T-10: logs estruturados, métricas, tracing/error tracking configuráveis.

## Restrições
- Prefixar chaves e objetos por ambiente e tenant.
- Nenhum provedor externo ou segredo real.
- Preservar funcionamento local sem dependência SaaS.
- Operar em contexto enxuto; retorno em aproximadamente 200 palavras.

## Entregáveis esperados
- Adapters testáveis, worker real e telemetria sem exposição de dados.

## Skills disponíveis
- `plan-exec`

