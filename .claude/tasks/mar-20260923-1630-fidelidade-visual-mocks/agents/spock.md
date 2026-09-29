# Spock — validador

subagent_type: geduc:validador
model: sonnet

## Contexto
Fase 10 do Central Vet Pro (fidelidade visual aos mocks). Spock roda o gate de cada onda (lint, suíte e passos Playwright do bloco Validação de cada task) e a validação visual final T-21.

## Tasks atribuídas
- T-21: Validação visual lado a lado com os mocks + regressão
- Gate de cada onda: blocos Validação das tasks da onda

Leia a especificação completa de cada task na seção correspondente de `/var/www/html/centralvet/.claude/tasks/mar-20260923-1630-fidelidade-visual-mocks/tasks.md`. Não leia as demais seções.

## Caminhos relevantes
- Mocks: `/tmp/claude-1000/-var-www-html-centralvet/004a97f0-faff-49ba-997a-092d97b4dffc/images/1.png` (Prescrições), `2.png` (Estoque e Vendas), `3.png` (Financeiro | Serviços), `4.png` (Atendimento)
- Capturas anteriores: `/var/www/html/centralvet/.playwright-mcp/cur-*.png`
- Board: `/var/www/html/centralvet/.claude/tasks/mar-20260923-1630-fidelidade-visual-mocks/board.md`
- Relatórios: `/var/www/html/centralvet/.claude/tasks/mar-20260923-1630-fidelidade-visual-mocks/reports/`
- Notas: `/var/www/html/centralvet/.claude/tasks/mar-20260923-1630-fidelidade-visual-mocks/notes.md` — § Decisões tomadas, § Descobertas e § Pendências (ausências aceitas: dado sem schema, IA oculta)

## Ambiente
- Projeto `/var/www/html/centralvet` sem git: não há commits nem trailers para conferir; RED das tasks T-04/T-05/T-06 é conferido pela saída colada em `## RED` do relatório (linha `FAIL` antes da implementação).
- Branch de trabalho: não se aplica (sem git).
- Lint: `docker compose run --rm --no-deps -T -v /var/www/html/centralvet/src:/var/www/html/src:ro app php -l <arquivo>`; suíte: `docker compose run --rm --no-deps -T -v /var/www/html/centralvet/src:/var/www/html/src:ro app php tests/run.php` (baseline 155/155; critério `Failed: 0`).
- Navegador: Playwright MCP em `http://127.0.0.1:8081` (sessão admin logada); screenshots só em `/var/www/html/centralvet/.playwright-mcp/f10-<Tela>.png`. Só use o navegador depois que o orquestrador confirmar o rebuild (`docker compose build app worker && docker compose up -d app worker && docker compose restart nginx`).
- Proibido: editar arquivos, rodar `docker compose build/up/restart`, SQL que escreve (só SELECT/SHOW).
