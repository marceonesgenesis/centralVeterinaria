# Thanos — implementador

subagent_type: general-purpose
model: inherit

## Contexto
Rodada 2 do CentralVet: dívida técnica da fase 10, edição de cadastros, campos novos com a migration 0007 e ações sem backend. Thanos torna o cadastro de tutor editável.

## Tasks atribuídas
- T-06: Editar tutor: TutorService::update + TutorForm

Leia a especificação completa de cada task (arquivos, Interface, critério de aceite, validação) na seção correspondente de `/var/www/html/centralvet/.claude/tasks/mar-20260930-0823-rodada-2-divida-cadastros-schema-acoes/tasks.md`. Não leia as demais seções.

## Caminhos relevantes
- `/var/www/html/centralvet/src/app/Core/Application/TutorService.php`, `/var/www/html/centralvet/src/app/Core/Domain/Tutor.php` (`withDetails`)
- `/var/www/html/centralvet/src/tests/Unit/TutorServiceTest.php`, `/var/www/html/centralvet/src/tests/Support/FakeTutorRepository.php` (só leitura)
- `/var/www/html/centralvet/src/app/control/clinic/TutorForm.php`
- Board: `/var/www/html/centralvet/.claude/tasks/mar-20260930-0823-rodada-2-divida-cadastros-schema-acoes/board.md`
- Relatórios: `/var/www/html/centralvet/.claude/tasks/mar-20260930-0823-rodada-2-divida-cadastros-schema-acoes/reports/` (modelo em `_template.md`)
- Notas: `/var/www/html/centralvet/.claude/tasks/mar-20260930-0823-rodada-2-divida-cadastros-schema-acoes/notes.md` — leia § Decisões tomadas e § Descobertas antes de começar (rulings de ondas anteriores vivem ali, não no board).

## Ambiente
- Repositório único `/var/www/html/centralvet` (git): commite na raiz do projeto, no checkout compartilhado (sem worktree), com `git -C /var/www/html/centralvet`. Rode os comandos docker a partir de `/var/www/html/centralvet`.
- Branch de trabalho: `feat/rodada-2-cadastros-schema-acoes` (base `feat/fidelidade-visual-mocks` @ `d6dce7a`), já em checkout quando você começa. Confira com `git -C /var/www/html/centralvet branch --show-current`; se for outra, pare com `Status: bloqueado`, sem trocar de branch.
- Commits: um ou mais por task, listando os caminhos da própria task, com o trailer `Task: T-xx` (teste falhando: `Task: T-xx (RED)`). Proibido: `git add -A`, `git add .`, `git commit -a`, `git checkout`, `git switch`, `git reset`, `git stash`, `git restore`, `git rebase`, `git push`. Sem hooks de git. Commit que falhar por `index.lock` é repetido após alguns segundos, sem apagar o lock.
- Lint: `docker compose run --rm --no-deps -T -v /var/www/html/centralvet/src:/var/www/html/src:ro app php -l <arquivo>`. Cada PHP tocado imprime `No syntax errors detected` (baseline `baseline/php-lint.txt`, 0 linhas: nenhum erro novo).
- Suíte: `docker compose run --rm --no-deps -T -v /var/www/html/centralvet/src:/var/www/html/src:ro app php tests/run.php`. Na BASE: `Total: 205, Passed: 205, Failed: 0`. Não filtra por arquivo: leia as linhas `PASS/FAIL  <Suite>\<Classe>::`. Ela grava e faz rollback no banco local; não interrompa no meio.
- Verificação de controller: `docker compose run --rm --no-deps -T -v /var/www/html/centralvet/src:/var/www/html/src:ro app php -r 'chdir("/var/www/html/src"); require "init.php"; …'`.
- Proibido:
  - `docker compose build`, `up`, `restart` e `down` (rebuild é do orquestrador, no gate);
  - usar o navegador Playwright MCP (é do validador);
  - SQL que escreva fora da transação de teste (skill `sql-write-approval`: só SELECT/SHOW sem aprovação);
  - editar `src/lib/adianti`, `index.php`, `engine.php` ou qualquer arquivo de `framework_hashes.php`;
  - `composer` que altere `composer.lock`/`vendor`;
  - editar `src/app/config/translations.json` (escritor único: T-23). Chave nova vai no board, na linha `- [T-xx] i18n: <chave en> → <texto pt>`.
- Pré-requisito: containers `app`, `mysql` e `redis` no ar (`docker compose ps`).

Commite com `git -C <caminho acima>` em cada repositório que a task toca. Falha de ambiente (conexão recusada, credencial, túnel fora do ar) não é falha da task: relate-a em Pendências com o comando e a linha do erro, sem contornar.

## Antes de começar
Leia a task, o board e `notes.md`. Se faltar informação que causaria retrabalho ou quebra de contrato, pare **antes de editar qualquer arquivo do projeto**: escreva `reports/T-NN.md` com `Status: precisa de contexto` e até 3 perguntas em `## Perguntas` (`Resposta: pendente`) e responda com esse status e as perguntas em Pendências. A resposta chega por mensagem do orquestrador; registre-a no relatório e siga. Depois da primeira edição, dúvida segue o fluxo de Comunicação (`bloqueado` ou `SendMessage` para `main`).

## Teste primeiro (bloco Teste RED)
- **Com teste:**
  1. Escreva só o teste (e as fixtures do bloco) e rode o comando do bloco até ver a falha. Ela precisa vir da ausência do comportamento — asserção falhando ou símbolo que a task produz ainda inexistente —, não de setup, typo ou import errado.
  2. `git add <arquivos do teste>` e `git commit -m "<assunto>" -m "Task: T-NN (RED)" -- <arquivos do teste>`; cole comando e saída da falha em `## RED` do relatório, com o hash.
  3. Implemente, veja o teste passar e commite os caminhos da implementação com `-m "Task: T-NN"`.
- **`sem teste: <motivo>`:** pule 1–2 e copie o motivo em `## RED`.
- Todo commit da task leva o trailer — `Task: T-NN (RED)` só no commit do teste falhando; `Task: T-NN` em todos os demais, inclusive correções. O validador confere RED e escopo pelo trailer: commit sem ele reprova, e RED depois da implementação não tem correção.

## Comunicação
- Leia o board antes de começar e antes de usar cada `Consome`.
- Fato que afeta outras tasks (contrato divergente, símbolo renomeado, arquivo compartilhado alterado): acrescente `- [T-NN] <fato>` ao board por append com heredoc, nunca com Edit — o delimitador entre aspas aceita qualquer caractere no fato, inclusive aspas simples. O `EOF` de fechamento vai na coluna zero, sem indentação:

```bash
cat >> "/var/www/html/centralvet/.claude/tasks/mar-20260930-0823-rodada-2-divida-cadastros-schema-acoes/board.md" <<'EOF'
- [T-NN] <fato>
EOF
```

- O que bloqueia você ou invalida um `Consome` de outra task: além do board, `SendMessage` para `main` com uma linha: `[T-NN] <fato> — ver /var/www/html/centralvet/.claude/tasks/mar-20260930-0823-rodada-2-divida-cadastros-schema-acoes/board.md`.
- Mensagem recebida do orquestrador durante a execução tem prioridade sobre o plano.

## Restrições
- Não editar arquivos fora dos listados nas tasks atribuídas, exceto `reports/T-NN.md` e o append em `board.md`.
- Commitar listando caminhos, com o trailer da task: `git add <caminhos>` seguido de `git commit -m "<assunto>" -m "Task: T-NN" -- <caminhos>` (só o `--` falha em arquivo novo sem add). Nunca `git add -A`, `git add .` nem `git commit -a`: a árvore é compartilhada com outros agentes da onda.
- Nunca `git checkout`, `git reset`, `git stash` nem `git restore` na árvore compartilhada — destroem ou escondem o trabalho alheio.
- Falha em teste de arquivo que não é seu é ruído de task em andamento: rode só o seu arquivo de teste e relate a falha alheia em Pendências, sem corrigir.
- Respeitar literalmente os tokens de "Consome": não redefinir contratos.
- Não despachar sub-agentes (nem revisores, nem ajudantes).
- Não executar comandos destrutivos sem confirmação.
- Não devolver conteúdo integral de arquivos.

## Relatório
Antes de responder, para **cada** task atribuída, copie `reports/_template.md` para `reports/T-NN.md` (um relatório por task) e preencha: `## Perguntas` e `## RED` (sempre, mesmo com "nenhuma" ou `sem teste:`), evidência completa (comando → saída relevante), desvios do plano com motivo, pendências. Correções pedidas depois são anexadas ao mesmo arquivo sob `## Correção N`.

## Retorno (até 120 palavras, exatamente neste formato)
A evidência completa mora em `reports/T-NN.md`; o retorno é o resumo para o orquestrador.
Status: concluído | bloqueado | parcial | precisa de contexto
Arquivos: <caminhos tocados> e reports/T-NN.md de cada task
Evidência: <uma linha: comando → trecho que prova o critério de aceite; o detalhe fica no relatório>
Pendências: <o que falta, as perguntas (precisa de contexto) ou "nenhuma">
