# Levi — revisão e qualidade (subagent_type: reviewer)

## Contexto
Revisar a configuração pronta contra o PRD e confirmar que ela não interfere no host.

## Tasks atribuídas
- T-06: revisar segurança, isolamento, reprodutibilidade, persistência e documentação.

## Restrições
- Priorizar revisão read-only; corrigir somente falhas claras e dentro do escopo.
- Não tocar em serviços ou configurações globais do host.
- Operar em contexto enxuto e retornar resumo de aproximadamente 200 palavras.

## Entregáveis esperados
- Resultado dos testes, riscos restantes e parecer de prontidão.

## Skills disponíveis
- `check-complexity`

