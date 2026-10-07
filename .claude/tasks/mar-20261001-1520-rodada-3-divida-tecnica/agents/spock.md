# Spock — validador

subagent_type: geduc:validador
model: sonnet

## Contexto
Rodada 3 de dívida técnica da CentralVet. Spock roda o gate de cada onda (1–4) e a validação final T-18: lint, suíte, conferência de commits e RED, os passos de Validação de cada task e a varredura Playwright das telas tocadas.

## Tasks atribuídas
- T-18: validação final — suíte, varredura Playwright, Review Focus, dados preservados
- Gate de cada onda (1–4; na onda 2, só depois do provisionamento do `centralvet_test` registrado em `notes.md § Bloqueios`), com a lista de telas de `plan.md § Critérios gerais de aceite`

Leia a especificação completa de cada task na seção correspondente de `/var/www/html/centralvet/.claude/tasks/mar-20261001-1520-rodada-3-divida-tecnica/tasks.md`. Não leia as demais seções.

## Caminhos relevantes
- Plano: `/var/www/html/centralvet/.claude/tasks/mar-20261001-1520-rodada-3-divida-tecnica/plan.md` (§ Critérios gerais de aceite, § Review Focus)
- Board: `/var/www/html/centralvet/.claude/tasks/mar-20261001-1520-rodada-3-divida-tecnica/board.md`
- Relatórios: `/var/www/html/centralvet/.claude/tasks/mar-20261001-1520-rodada-3-divida-tecnica/reports/` (modelo em `_template.md`)
- Notas: `/var/www/html/centralvet/.claude/tasks/mar-20261001-1520-rodada-3-divida-tecnica/notes.md` — leia § Decisões tomadas, § Bloqueios (provisionamento do `centralvet_test`) e § Descobertas antes de começar.

## Ambiente
- Repositório único `/var/www/html/centralvet`, branch `feat/rodada-3-divida-tecnica` (base `feat/rodada-2-cadastros-schema-acoes`). Você não commita.
- Commits da onda: `git -C /var/www/html/centralvet log --format='%h %s%n%b' <BASE da onda>..HEAD`. Todo commit tem trailer `Task: T-xx` de uma task da onda; `chore(tasks)` restritos a `.claude/tasks/` são isentos. `git show --stat <hash>` só lista caminhos de "Arquivos prováveis" da task.
- RED (T-01, T-02, T-03, T-04, T-05, T-07, T-08, T-14, T-19, T-20): o commit `Task: T-xx (RED)` toca só os arquivos do bloco Teste RED e é anterior a todo commit `Task: T-xx` da implementação. As demais tasks são `sem teste`; em T-09 e T-17 confira em `## RED` do relatório a mutação feita em worktree isolada e `git -C /var/www/html/centralvet worktree list` sem worktree restante.
- Lint: `docker compose run --rm --no-deps -T -v /var/www/html/centralvet/src:/var/www/html/src:ro app php -l <arquivo>` (baseline `baseline/php-lint.txt`, 0 linhas). Suíte: `docker compose run --rm --no-deps -T -v /var/www/html/centralvet/src:/var/www/html/src:ro app php tests/run.php`, sozinha (sem outra SUITE em paralelo); critério `Failed: 0`.
- i18n (onda 3 em diante): o comando de contagem do critério de T-16.
- Banco: só SELECT/SHOW (skill `sql-write-approval`). Registre `SELECT COUNT(*)` de `tutor`, `patient`, `service`, `product`, `stored_object`, `financial_entry` em `centralvet` na BASE e no fim de cada gate. Do gate da onda 2 em diante (depois de T-19), a SUITE roda no `centralvet_test`: confira `MAX(id)` de `tutor` e `patient` em `centralvet` antes e depois da SUITE (iguais).
- Navegador: Playwright MCP **sempre** em `http://127.0.0.1:8081`, nunca `localhost:8081`, com a sessão admin logada pelo orquestrador, e só depois que ele confirmar o rebuild. Screenshots só em `/var/www/html/centralvet/.playwright-mcp/r3-<Tela>.png`. Use `browser_handle_dialog` para provar 0 dialogs nos payloads com `<img src=x onerror=alert(1)> R3`.
- Payloads e registros de teste: crie pela UI só registros `R3 varredura` ou os nomes fixados nas tasks (`R3 tutor contexto`, produto `<img src=x onerror=alert(1)> R3`, página de wiki `<img src=x onerror=alert(1)> R3 wiki` para T-21, documentos `r3-falso.pdf`/`r3-real.pdf` no Drive para T-20), registrando os ids; eles ficam no banco.
- T-20 (onda 1): o POST forçado ao `CvUploaderService` (`engine.php?class=CvUploaderService&method=show`, multipart `fileName=r3.xhtml`, sem `extensions`) vai pelo `browser_evaluate` com a sessão logada; confira a resposta JSON e `docker compose exec -T app ls /var/www/html/src/tmp | wc -l` antes e depois (só leitura). O tutor 10626 e os pacientes 9179/9180 (R2) são de leitura.
- Proibido: editar arquivos do projeto, commitar, `git checkout/switch/reset/stash/restore`, `docker compose build/up/restart` e SQL que escreva. Nunca excluir, estornar, fechar caixa ou finalizar atendimento que não seja da varredura.

Falha de ambiente (conexão recusada, sessão do navegador caída, container fora do ar) não é falha da task: relate-a com o comando e a linha do erro, sem contornar.

## Varredura Playwright (todo gate)
1. `browser_navigate` até cada tela da lista da onda e `browser_snapshot`.
2. Exercite abrir, listar, abrir registro, provocar o erro da task e voltar.
3. Depois de cada tela: `browser_console_messages` (nível `error`), `browser_network_requests` (≥ 400, exceto `favicon`) e dialogs. "Fatal error", "Warning:", "Exception", "Message not found" (da onda 3 em diante) ou diálogo de erro do Adianti também contam.
4. Tabela `Tela | Fluxos | Console (errors) | Rede (≥400) | Erro na tela | Veredito | Task dona`. Bug em tela de task da onda reprova a task dona; bug sem dona na onda vira "sem dona".

## Relatório
Grave `reviews/T-xx.md § Gate` por task. Em T-18, grave `reports/T-18.md` a partir de `reports/_template.md`.

## Retorno (até 120 palavras)
Status: aprovado | reprovado | parcial
Evidência: <comando → trecho>
Pendências: <o que falhou ou "nenhuma">
