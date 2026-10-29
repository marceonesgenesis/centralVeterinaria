# Saitama — landing

subagent_type: general-purpose
model: inherit

## Contexto
Etapa 3 — Contratação: compra pela landing com Stripe, assinatura mensal com webhook autenticado, provisionamento e direitos por plano, mais as pendências S5, S6, caixa e Etapa 2. Leva a compra para a landing pública, com acessibilidade e textos honestos.

## Tasks atribuídas
- T-20: Landing: compra

Leia a especificação completa de cada task (arquivos, Interface, critério de aceite, validação) na seção correspondente de `/var/www/html/centralvet/.claude/tasks/mar-20261021-1554-etapa-3-contratacao/tasks.md`. Não leia as demais seções.

## Caminhos relevantes
- `src/app/view/landing/landing.html`
- `src/landing/landing.js`
- `src/landing/translations.js`
- `src/app/Core/Landing/LandingPage.php`
- `.docs/design-system.md`
- Board: `/var/www/html/centralvet/.claude/tasks/mar-20261021-1554-etapa-3-contratacao/board.md`
- Relatórios: `/var/www/html/centralvet/.claude/tasks/mar-20261021-1554-etapa-3-contratacao/reports/` (modelo em `_template.md`)
- Notas: `/var/www/html/centralvet/.claude/tasks/mar-20261021-1554-etapa-3-contratacao/notes.md` — leia § Decisões tomadas e § Descobertas antes de começar (rulings de ondas anteriores vivem ali, não no board).

## Ambiente
- Repositório único: commitar em `/var/www/html/centralvet` (raiz do projeto, árvore compartilhada da onda) com `git -C /var/www/html/centralvet`.
- Branch `task/etapa-3-contratacao` (base `task/etapa-2-dinheiro-estoque`); nunca push nem PR.
- Comandos de validação e baseline: LINT, SUITE e MYSQLTEST no cabeçalho de `tasks.md`; baseline `/var/www/html/centralvet/.claude/tasks/mar-20261021-1554-etapa-3-contratacao/baseline/php-lint.txt` (0 linhas). Testes rodam no container `app` (PHP 8.4) com `src/` somente leitura; erro `class not found` = autoload quebrado.
- Proibido: editar `src/lib/adianti`, `src/engine.php`, `src/index.php`, `src/init.php`, `src/composer.json`, `src/app/config/framework_hashes.php` ou qualquer arquivo listado nele (única exceção, autorizada pelo usuário: T-04 edita `src/composer.json`, `src/composer.lock` e a linha `composer.json` de `framework_hashes.php`); `composer update` sem pacote nomeado; SQL de escrita contra o banco `centralvet` (dev) sem autorização expressa; escrever no `.env`; colocar chave real (`sk_live_`, `sk_test_`, `whsec_`) em código, teste, log, relatório ou prompt; `git add -A`, `git stash`, `git checkout`, `git reset`, `git restore`.
- Regras do projeto: rótulos e cabeçalhos de grid à esquerda; alvos de toque ≥ 44 px (`--cv-touch-target`); mensagens ao usuário só por `_t()` com as chaves de T-01 (`^1..^4`); toda mutação nova passa pela `MutationRequestPolicy` (exceção: `billing-webhook.php`, autenticado por `Stripe-Signature`).
- Pré-requisito do `node --check`: `/usr/bin/node` no host.

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
cat >> "/var/www/html/centralvet/.claude/tasks/mar-20261021-1554-etapa-3-contratacao/board.md" <<'EOF'
- [T-NN] <fato>
EOF
```

- O que bloqueia você ou invalida um `Consome` de outra task: além do board, `SendMessage` para `main` com uma linha: `[T-NN] <fato> — ver /var/www/html/centralvet/.claude/tasks/mar-20261021-1554-etapa-3-contratacao/board.md`.
- Mensagem recebida do orquestrador durante a execução tem prioridade sobre o plano.

## Restrições
- Não editar arquivos fora dos listados nas tasks atribuídas, exceto `reports/T-NN.md` e o append em `board.md`.
- Commitar listando caminhos, com o trailer da task: `git add <caminhos>` seguido de `git commit -m "<assunto>" -m "Task: T-NN" -- <caminhos>` (só o `--` falha em arquivo novo sem add). Nunca `git add -A`, `git add .` nem `git commit -a`: a árvore é compartilhada com outros agentes da onda.
- Nunca `git checkout`, `git reset`, `git stash` nem `git restore` na árvore compartilhada — destroem ou escondem o trabalho alheio.
- Nunca `git push` nem PR: os commits da task são internos à branch de trabalho, e publicar é decisão do desenvolvedor.
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
