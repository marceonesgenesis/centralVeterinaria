# Gandalf — Documentação dos runbooks

subagent_type: geduc:documentador
model: sonnet

## Contexto
Atualiza os runbooks do copiloto e da hospedagem 5.7 com as regras que a revisão final da 8B pediu para documentar e com o comportamento novo das Ondas 1–2.

## Tasks atribuídas
- T-24: runbooks: 0015 no 5.7, códigos ai_*, flag, débitos, ticket médio, retenção, seed, âncora, motivos

Leia a especificação completa de cada task (arquivos, Interface, critério de aceite, validação) na seção correspondente de `/var/www/html/centralvet/.claude/tasks/mar-20261019-1612-fase-8b-pendencias/tasks.md`. Não leia as demais seções.

## Caminhos relevantes
- docs/runbooks/ia-assistente.md
- docs/runbooks/shared-hosting-mysql57.md (seção 0011 :186-190 como modelo)
- Histórico: /var/www/html/centralvet/.claude/tasks/mar-20261015-2050-fase-8b-copiloto-mcp/reviews/T-01.md, T-12.md, T-17.md, T-18.md, T-21.md, T-24.md
- Board: `/var/www/html/centralvet/.claude/tasks/mar-20261019-1612-fase-8b-pendencias/board.md`
- Relatórios: `/var/www/html/centralvet/.claude/tasks/mar-20261019-1612-fase-8b-pendencias/reports/` (modelo em `_template.md`)
- Notas: `/var/www/html/centralvet/.claude/tasks/mar-20261019-1612-fase-8b-pendencias/notes.md` — leia § Decisões tomadas e § Descobertas antes de começar (rulings de ondas anteriores vivem ali, não no board).

## Ambiente
- Repositório único `/var/www/html/centralvet` (raiz do projeto, checkout compartilhado com os outros agentes da onda): commite com `git -C /var/www/html/centralvet`.
- Branch `task/fase-8b-pendencias` (já em checkout; não troque de branch).
- Validação: os `grep` do bloco Validação da T-24 em `/var/www/html/centralvet/.claude/tasks/mar-20261019-1612-fase-8b-pendencias/tasks.md`, rodados de `/var/www/html/centralvet` com `/usr/bin/grep` (no zsh, `grep` é função). Fontes para conferir o texto (só leitura): `src/app/Core/Application/AiCopilotService.php` (motivos de indisponibilidade), `src/app/Core/Ai/Tools/Management/IndicadoresClinicaTool.php` (fórmula do ticket médio), `src/app/Core/Persistence/CopilotFinanceQuery.php:42` (vencimento), `src/bin/ai-conversation-retention.php` (trava, depois da T-15), `src/app/control/clinic/AiToolCallList.php` (rótulos, depois da T-21), `scripts/prepare-mysql57.py:96-152` (verify adaptado e `manifest.json`), `src/app/database/seeds/initial-application-programs.sql:1034-1089`.
- Proibidos: git `add -A`/`add .`, `commit -a`, `checkout`, `switch`, `reset`, `stash`, `restore`, `rebase`, `push`; qualquer SQL (skill `sql-write-approval`); editar arquivo fora de `docs/runbooks/ia-assistente.md` e `docs/runbooks/shared-hosting-mysql57.md`.
- Texto em pt-BR; nunca citar chave, nome de paciente/tutor ou dado clínico real. Pré-requisitos: nenhum.

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
cat >> "/var/www/html/centralvet/.claude/tasks/mar-20261019-1612-fase-8b-pendencias/board.md" <<'EOF'
- [T-NN] <fato>
EOF
```

- O que bloqueia você ou invalida um `Consome` de outra task: além do board, `SendMessage` para `main` com uma linha: `[T-NN] <fato> — ver /var/www/html/centralvet/.claude/tasks/mar-20261019-1612-fase-8b-pendencias/board.md`.
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
