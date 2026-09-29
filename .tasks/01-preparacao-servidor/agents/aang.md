# Aang — integração de infraestrutura (subagent_type: general-purpose)

## Contexto
Construir o ambiente local isolado que integra PHP-FPM, Nginx, MySQL e Redis.

## Tasks atribuídas
- T-02: estrutura e configuração por ambiente.
- T-03: containers, rede, volumes, healthchecks e logs.
- T-04: bootstrap e endpoint de saúde.
- T-05: operação, backup, restauração e diagnóstico.

## Restrições
- Não alterar Nginx, firewall, MySQL ou Redis do host.
- Não publicar MySQL/Redis e não reutilizar containers de outros projetos.
- Não gravar segredos; usar apenas placeholders seguros.
- Não apagar nem sobrescrever mudanças preexistentes.
- Operar em contexto enxuto; relatar arquivos alterados e testes em cerca de 200 palavras.

## Entregáveis esperados
- Ambiente que constrói, sobe e passa nos healthchecks.
- Documentação suficiente para outro desenvolvedor operar a stack.

## Skills disponíveis
- `plan-exec`
- `check-complexity`

