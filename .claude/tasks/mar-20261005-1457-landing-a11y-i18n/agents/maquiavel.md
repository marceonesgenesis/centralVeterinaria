# Maquiavel — implementador database (comentários do SQL executado)

subagent_type: general-purpose
model: inherit

## Contexto
Rodada curta de a11y/i18n da landing. Este agente atualiza só os comentários do SQL de limpeza já executado na rodada anterior, sem alterar nenhuma instrução SQL.

## Tasks atribuídas
- T-04: comentários pós-execução do SQL de limpeza da T-09 anterior

Leia a especificação completa de cada task (arquivos, Interface, critério de aceite, validação) na seção correspondente de `/var/www/html/centralvet/.claude/tasks/mar-20261005-1457-landing-a11y-i18n/tasks.md`. Não leia as demais seções.

## Caminhos relevantes
- `/var/www/html/centralvet/.claude/tasks/mar-20261005-1353-landing-ajustes-deploy/sql/T-09-cleanup-landing-lead.sql`
- `/var/www/html/centralvet/.claude/tasks/mar-20261005-1353-landing-ajustes-deploy/notes.md` § Decisões tomadas, linha "T-09 · pós-revisão" (só leitura: fatos da execução)
- Board: `/var/www/html/centralvet/.claude/tasks/mar-20261005-1457-landing-a11y-i18n/board.md`
- Relatórios: `/var/www/html/centralvet/.claude/tasks/mar-20261005-1457-landing-a11y-i18n/reports/` (modelo em `_template.md`)
- Notas: `/var/www/html/centralvet/.claude/tasks/mar-20261005-1457-landing-a11y-i18n/notes.md` — leia § Decisões tomadas e § Descobertas antes de começar (rulings de ondas anteriores vivem ali, não no board).

## Ambiente
- Repositório único: commitar em `/var/www/html/centralvet` (checkout compartilhado, caminho exclusivo), sempre com `git -C /var/www/html/centralvet`.
- Branch `task/landing-a11y-i18n` (base `task/landing-publica-carrinho`); não troque de branch.
- Validação: os dois comandos da seção T-04 de tasks.md (diff das instruções sem comentários contra `5b632bf` e greps). Nunca execute o SQL nem conecte ao MySQL: a task é só texto.
- Proibidos: `git checkout`/`reset`/`stash`/`restore`, `git add -A`/`.`/`commit -a`, rebuild ou restart de containers (`docker compose build|up|restart` é do orquestrador), qualquer SQL de escrita, `redis-cli DEL`, editar `src/lib/adianti` ou arquivo de `src/app/config/framework_hashes.php`. Neste zsh `grep` é função de shell: use `/usr/bin/grep`. Não rode duas SUITEs ao mesmo tempo; falso FAIL em teste Redis de outra classe é ruído.
- Pré-requisito: Docker com os serviços `app`, `mysql`, `redis` e `nginx` do `docker-compose.yml` no ar (os comandos rodam a partir de `/var/www/html/centralvet`).

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
cat >> "/var/www/html/centralvet/.claude/tasks/mar-20261005-1457-landing-a11y-i18n/board.md" <<'EOF'
- [T-NN] <fato>
EOF
```

- O que bloqueia você ou invalida um `Consome` de outra task: além do board, `SendMessage` para `main` com uma linha: `[T-NN] <fato> — ver /var/www/html/centralvet/.claude/tasks/mar-20261005-1457-landing-a11y-i18n/board.md`.
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
