# Arquimedes — Testes de caracterização da 7B

subagent_type: general-purpose
model: inherit

## Contexto
Rodada 4 de dívida técnica do Central Vet Pro: anexos (foto, atendimento, resultado de exame) passam a gravar por uma `StorageFactory` com driver configurável (local fora do webroot ou S3), lendo os objetos antigos pelo `storage_provider`, para rodar na hospedagem compartilhada sem S3; mais as pendências restantes da Fase 7B e a busca global com 44 px. Existe para cobrir com teste dois comportamentos da 7B que ficaram sem prova: dedupe do aviso via `insertIfNew` e o link voltar da tela de template.

## Tasks atribuídas
- T-06: Testes de caracterização: dedupe do `document_ready` e "voltar" da `DocumentTemplateForm`

Leia a especificação completa de cada task (arquivos, Interface, critério de aceite, validação) na seção correspondente de `/var/www/html/centralvet/.claude/tasks/mar-20261007-1405-rodada-4-divida-tecnica/tasks.md`. Não leia as demais seções.

## Caminhos relevantes
- `src/app/Core/Application/DocumentReadyNotifier.php:20-130`
- `src/tests/Support/FakeOutboundMessageRepository.php`
- `src/tests/Unit/DocumentGenerationServiceTest.php` (testes 277-360 de consentimento)
- `src/tests/Integration/DocumentTemplateScreensIntegrationTest.php`
- `src/app/control/clinic/DocumentTemplateForm.php:76` (só leitura)
- Board: `/var/www/html/centralvet/.claude/tasks/mar-20261007-1405-rodada-4-divida-tecnica/board.md`
- Relatórios: `/var/www/html/centralvet/.claude/tasks/mar-20261007-1405-rodada-4-divida-tecnica/reports/` (modelo em `_template.md`)
- Notas: `/var/www/html/centralvet/.claude/tasks/mar-20261007-1405-rodada-4-divida-tecnica/notes.md` — leia § Decisões tomadas e § Descobertas antes de começar (rulings de ondas anteriores vivem ali, não no board).

## Ambiente
- Repositório único `/var/www/html/centralvet` (raiz do projeto, checkout compartilhado): commite com `git -C /var/www/html/centralvet`.
- Branch `feat/rodada-4-divida-tecnica` (já criada pelo orquestrador a partir de `origin/main` @ `0d5ac47`; não troque de branch).
- LINT = `docker compose run --rm --no-deps -T -v /var/www/html/centralvet/src:/var/www/html/src:ro app php -l <arquivo relativo a src/>` — baseline `/var/www/html/centralvet/.claude/tasks/mar-20261007-1405-rodada-4-divida-tecnica/baseline/php-lint.txt` (0 linhas): todo PHP tocado imprime `No syntax errors detected`.
- SUITE = `docker compose run --rm --no-deps -T -v /var/www/html/centralvet/src:/var/www/html/src:ro app php tests/run.php | /usr/bin/grep -E '<Classe>|Failed:'` (não filtra: use o grep; integração roda no `centralvet_test`; com outras SUITEs da onda rodando, FAIL de teste Redis ou deadlock de arquivo alheio é ruído). Classe nova aparece sem rebuild (PSR-4 do host: `CentralVet\` → `src/app/Core/`, `CentralVet\Tests\` → `src/tests/`). Controllers Adianti só carregam em subprocesso com `require "init.php"` (padrão de `src/tests/Integration/DocumentTemplateScreensIntegrationTest.php::runInAdianti`).
- Use `/usr/bin/grep` (no zsh, `grep` é função).
- Proibidos: git `add -A`/`add .`, `commit -a`, `checkout`, `switch`, `reset`, `stash`, `restore`, `rebase`, `push`, apagar `.git/index.lock` (repita o commit após alguns segundos); docker `build`, `up`, `restart`, `stop`, `down` (são do orquestrador); qualquer SQL que não seja SELECT (skill `sql-write-approval`); migration; `composer`; escrever no `.env`; Playwright (só o validador navega); apagar arquivos do volume `app_documents`; editar `src/lib/adianti`, `src/app/config/framework_hashes.php` ou arquivo listado nele (`src/app/templates/adminbs5/*` exceto `cv-components.css`, entre outros).
- Testes que usam `putenv` restauram o valor original no `finally`. Mensagens de exceção em inglês; mensagem nova vai ao board como `- [T-NN] i18n-domínio: <mensagem>`. Services não abrem transação; o controller abre `TTransaction::open('permission')`. Regras de UI em `/var/www/html/centralvet/CLAUDE.md` e `.docs/design-system.md` (rótulos à esquerda; alvos ≥ 44 px).
- Pré-requisitos: containers `app` e `mysql` no ar (`docker compose ps`), comandos rodados de `/var/www/html/centralvet`; banco `centralvet_test` existente. Sem hooks de commit conhecidos.

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
cat >> "/var/www/html/centralvet/.claude/tasks/mar-20261007-1405-rodada-4-divida-tecnica/board.md" <<'EOF'
- [T-NN] <fato>
EOF
```

- O que bloqueia você ou invalida um `Consome` de outra task: além do board, `SendMessage` para `main` com uma linha: `[T-NN] <fato> — ver /var/www/html/centralvet/.claude/tasks/mar-20261007-1405-rodada-4-divida-tecnica/board.md`.
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
