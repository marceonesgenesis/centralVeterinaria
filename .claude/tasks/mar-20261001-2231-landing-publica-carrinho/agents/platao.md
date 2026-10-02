# Platão — contratos da landing

subagent_type: general-purpose
model: inherit

## Contexto
Plano: landing pública do Central Vet Pro em `/` (anônimo), carrinho de um plano e captura de leads por `POST /lead.php`, com tela admin de leads. Define o contrato único de planos, preços, opções do formulário, consentimento e HTTP do endpoint, consumido por T-04, T-05, T-06, T-07 e T-08.

## Tasks atribuídas
- T-01: catálogo, contrato HTTP, LeadSubmission e LeadStoreInterface

Leia a especificação completa de cada task (arquivos, Interface, critério de aceite, validação) na seção correspondente de `/var/www/html/centralvet/.claude/tasks/mar-20261001-2231-landing-publica-carrinho/tasks.md`. Não leia as demais seções.

## Caminhos relevantes
- `/var/www/html/centralvet/src/app/Core/Landing/` (novo)
- `/var/www/html/centralvet/.claude/tasks/mar-20261001-2231-landing-publica-carrinho/reference/landing-draft.html` (planos :496-523, formulário :583-600, mensagens :631-637)
- `/var/www/html/centralvet/src/app/Core/Domain/NameText.php` (regra de `<`/`>` existente)
- `/var/www/html/centralvet/src/tests/Unit/CoreLayerDependencyTest.php` (Core sem Adianti)
- Board: `/var/www/html/centralvet/.claude/tasks/mar-20261001-2231-landing-publica-carrinho/board.md`
- Relatórios: `/var/www/html/centralvet/.claude/tasks/mar-20261001-2231-landing-publica-carrinho/reports/` (modelo em `_template.md`)
- Notas: `/var/www/html/centralvet/.claude/tasks/mar-20261001-2231-landing-publica-carrinho/notes.md` — leia § Decisões tomadas e § Descobertas antes de começar (rulings de ondas anteriores vivem ali, não no board).

## Ambiente
- Repositório único `/var/www/html/centralvet` (raiz do projeto, checkout compartilhado): commite com `git -C /var/www/html/centralvet`.
- Branch `task/landing-publica-carrinho` (já criada pelo orquestrador; não troque de branch).
- LINT = `docker compose run --rm --no-deps -T -v /var/www/html/centralvet/src:/var/www/html/src:ro app php -l <arquivo relativo a src/>` (baseline `/var/www/html/centralvet/.claude/tasks/mar-20261001-2231-landing-publica-carrinho/baseline/php-lint.txt`, 0 linhas: todo arquivo tocado imprime `No syntax errors detected`)
- SUITE = `docker compose run --rm --no-deps -T -v /var/www/html/centralvet/src:/var/www/html/src:ro app php tests/run.php` (roda no `centralvet_test`, não filtra: use `| grep` no nome da classe e `Failed:`; não interromper no meio)
- Proibidos: `git add -A`/`git add .`, `git commit -a`, `checkout`, `switch`, `reset`, `stash`, `restore`, `rebase`, `push`, apagar `.git/index.lock` (repita o commit após alguns segundos); `docker compose build`/`up`/`restart`/`stop` (rebuild e restart são do orquestrador); qualquer SQL que não seja SELECT (skill `sql-write-approval`); editar `src/lib/adianti`, `src/app/config/framework_hashes.php` ou arquivo listado nele (`src/index.php`, `engine.php`, `init.php`, `src/app/templates/`); Playwright (só o validador navega).
- Pré-requisitos: containers `app`, `mysql`, `redis`, `nginx` no ar (`docker compose ps`), comandos rodados de `/var/www/html/centralvet`; banco `centralvet_test` existente. Sem hooks de commit conhecidos; o PHP do container `app` em execução é o da imagem (sua mudança só aparece em `http://127.0.0.1:8081` depois do rebuild do orquestrador).

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
cat >> "/var/www/html/centralvet/.claude/tasks/mar-20261001-2231-landing-publica-carrinho/board.md" <<'EOF'
- [T-NN] <fato>
EOF
```

- O que bloqueia você ou invalida um `Consome` de outra task: além do board, `SendMessage` para `main` com uma linha: `[T-NN] <fato> — ver /var/www/html/centralvet/.claude/tasks/mar-20261001-2231-landing-publica-carrinho/board.md`.
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
