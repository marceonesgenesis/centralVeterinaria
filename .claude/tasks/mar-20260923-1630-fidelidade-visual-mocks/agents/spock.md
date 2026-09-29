# Spock — validador

subagent_type: geduc:validador
model: sonnet

## Contexto
Fase 10 do Central Vet Pro (fidelidade visual aos mocks). Spock roda o gate de cada onda (lint, suíte, conferência de commits/RED, passos Playwright do bloco Validação de cada task e a varredura Playwright das telas tocadas na onda) e a validação final T-21, cuja varredura cobre todas as telas do menu.

## Tasks atribuídas
- T-21: Validação visual lado a lado com os mocks + regressão + varredura Playwright de todas as telas do menu
- Gate de cada onda: blocos Validação das tasks da onda + varredura Playwright da onda (`plan.md § Critérios gerais de aceite`, item "Varredura Playwright no gate")

Leia a especificação completa de cada task na seção correspondente de `/var/www/html/centralvet/.claude/tasks/mar-20260923-1630-fidelidade-visual-mocks/tasks.md`. Não leia as demais seções.

## Caminhos relevantes
- Mocks: `/tmp/claude-1000/-var-www-html-centralvet/004a97f0-faff-49ba-997a-092d97b4dffc/images/1.png` (Prescrições), `2.png` (Estoque e Vendas), `3.png` (Financeiro | Serviços), `4.png` (Atendimento)
- Capturas anteriores: `/var/www/html/centralvet/.playwright-mcp/cur-*.png`
- Menu: `/var/www/html/centralvet/src/menu.xml` (lista de telas da varredura de T-21)
- Board: `/var/www/html/centralvet/.claude/tasks/mar-20260923-1630-fidelidade-visual-mocks/board.md`
- Relatórios: `/var/www/html/centralvet/.claude/tasks/mar-20260923-1630-fidelidade-visual-mocks/reports/`
- Notas: `/var/www/html/centralvet/.claude/tasks/mar-20260923-1630-fidelidade-visual-mocks/notes.md` — § Decisões tomadas, § Descobertas e § Pendências (ausências aceitas: dado sem schema, IA oculta)

## Ambiente
- Repositório único `/var/www/html/centralvet` (git), branch `feat/fidelidade-visual-mocks` (base `main` @ `9efef4e`). Você não commita. Confira por `git -C /var/www/html/centralvet log --format='%h %s%n%b' <BASE da onda>..HEAD`: todo commit da onda tem trailer `Task: T-NN` de uma task da onda; commit sem trailer reprova a task cujos arquivos ele toca. Commits `chore(tasks)` que só tocam `.claude/tasks/` (do fechador) são isentos do trailer.
- BASE da onda 3: `f48ebe0` (ondas 1 e 2 fechadas). No gate da onda 3, rode também os passos de correção da onda 2 dos blocos Validação de T-17 (editar sem duplicar, id de outro tenant negado, filtro Inativo), T-18 (filtro de receitas `entry_type=income`) e T-19 (unidades por tenant, troca recusada, CSS do seletor), e PATTERN0 (console sem `pattern`) nos formulários de T-15..T-18.
- RED (T-04, T-05, T-06, T-17): o commit `Task: T-NN (RED)` existe, toca só os arquivos do bloco Teste RED da task (teste e fixtures) e é anterior a todo commit `Task: T-NN` que toca a implementação (`git -C /var/www/html/centralvet log --reverse --format='%h %b' 9efef4e..HEAD -- <arquivos da task>`); RED ausente ou depois da implementação reprova sem correção. Tasks `sem teste:` não têm commit RED.
- Escopo: `git -C /var/www/html/centralvet show --stat <hash>` de cada commit `Task: T-NN` só lista caminhos de "Arquivos prováveis" da T-NN (ou desvio registrado no relatório).
- Lint: `docker compose run --rm --no-deps -T -v /var/www/html/centralvet/src:/var/www/html/src:ro app php -l <arquivo>`; suíte: `docker compose run --rm --no-deps -T -v /var/www/html/centralvet/src:/var/www/html/src:ro app php tests/run.php` (baseline 155/155; critério `Failed: 0`).
- Navegador: Playwright MCP em `http://127.0.0.1:8081` (sessão admin logada); screenshots só em `/var/www/html/centralvet/.playwright-mcp/f10-<Tela>.png` (pasta no `.gitignore`). Só use o navegador depois que o orquestrador confirmar o rebuild (`docker compose build app worker && docker compose up -d app worker && docker compose restart nginx`).
- Proibido: editar arquivos, commitar, `git checkout/switch/reset/stash/restore`, rodar `docker compose build/up/restart`, SQL que escreve (só SELECT/SHOW).

## Varredura Playwright (todo gate)
Além dos passos de Validação das tasks, percorra com o Playwright MCP cada tela da lista da onda (`plan.md § Critérios gerais de aceite`; em T-21, todas as telas de `menu.xml`, inclusive as administrativas do Adianti em Configurações):
1. `browser_navigate` até a tela pelo menu (ou pela URL do contexto, para telas contextuais) e `browser_snapshot`.
2. Exercite os fluxos que a tela oferece: abrir, listar (paginação se houver), filtrar/buscar, abrir um registro, salvar e voltar. Salvar só em telas clínicas, criando ou editando registros de teste com nome prefixado `F10 varredura`; nas telas administrativas do Adianti (usuários, grupos, programas, unidades, preferências, logs) só abrir, listar, filtrar e voltar. Nunca excluir, estornar, fechar caixa ou finalizar atendimento que não seja registro criado pela própria varredura.
3. Depois de cada tela: `browser_console_messages` (mensagens de nível `error`) e `browser_network_requests` (status ≥ 400 ou falha de rede, exceto `favicon`); erro PHP, "Fatal error", "Warning:", "Exception" ou diálogo de erro do Adianti na tela também contam.
4. Devolva a tabela `Tela | Fluxos exercitados | Console (errors) | Rede (≥400) | Erro na tela | Veredito | Task dona` e, por bug, passos de reprodução, mensagem exata e screenshot `f10-bug-<Tela>-<n>.png`.

Atribuição: bug numa tela ou arquivo de task da onda → reprova a task dona (fix loop); bug sem task dona na onda → "sem dona" (o orquestrador abre task de correção). Em T-21, bugs pré-existentes (tela fora do Mapa de arquivos) são mapeados como "pré-existente" e entram na onda de correção aberta pelo orquestrador; bug em arquivo de `src/lib/adianti` ou em `framework_hashes.php` é mapeado e marcado "framework — não editável".
