# Darwin — Migration 0014 e resumo de exame

subagent_type: general-purpose
model: inherit

## Contexto
Fase 8A — AI Gateway e IA contextual (núcleo): gateway próprio independente do provedor com adaptador Claude (Anthropic Messages API via curl), resumo do paciente, organização da anamnese (texto livre ou ditado pela Web Speech API) e resumo de exame, sempre com revisão humana antes de persistir; IA desligada por padrão por tenant (feature flag + termo + cota mensal de tokens), custo e uso registrados em `ai_interaction`, sob RBAC, tenant e unidade. Prepara o schema da 8A compatível com MySQL 8 e com o preparador 5.7 (contrato de banco de todas as ondas) e, na Onda 4, adapta o `ExamResultForm` legado para o resumo de exame revisado, sem tocar no resultado original.

## Tasks atribuídas
- T-01: Migration 0014 (`feature_flag`, `ai_tenant_setting`, `ai_interaction`), verify e provision
- T-15: Resultado de exame: resumo para revisão, original intacto

Leia a especificação completa de cada task (arquivos, Interface, critério de aceite, validação) na seção correspondente de `/var/www/html/centralvet/.claude/tasks/mar-20261007-1508-fase-8a-ai-gateway/tasks.md`. Não leia as demais seções.

## Caminhos relevantes
- `src/app/database/migrations/20261006_0013_phase7b_documents.sql` e `.verify.sql` (modelo; placeholder de 64 zeros no fim)
- `scripts/prepare-mysql57.py`, `scripts/test-prepare-mysql57.py`, `scripts/test-db/provision.sh` (linha 10 e lista das migrations)
- `src/app/control/clinic/ExamResultForm.php` (`makeExamService` :278, `resolveTenantContext` :318), `src/app/Core/Domain/ExamResult.php`
- `src/app/control/clinic/EncounterView.php` (T-14, mesma onda: só leitura, como modelo do painel de IA)
- Board: `/var/www/html/centralvet/.claude/tasks/mar-20261007-1508-fase-8a-ai-gateway/board.md`
- Relatórios: `/var/www/html/centralvet/.claude/tasks/mar-20261007-1508-fase-8a-ai-gateway/reports/` (modelo em `_template.md`)
- Notas: `/var/www/html/centralvet/.claude/tasks/mar-20261007-1508-fase-8a-ai-gateway/notes.md` — leia § Decisões tomadas e § Descobertas antes de começar (rulings de ondas anteriores vivem ali, não no board).

## Ambiente
- Repositório único `/var/www/html/centralvet` (raiz do projeto, checkout compartilhado): commite com `git -C /var/www/html/centralvet`.
- Branch `feat/fase-8a-ai-gateway` (já em checkout, criada a partir de `origin/main` @ `5e4b238`; não troque de branch).
- LINT = `docker compose run --rm --no-deps -T -v /var/www/html/centralvet/src:/var/www/html/src:ro app php -l <arquivo relativo a src/>` (baseline `/var/www/html/centralvet/.claude/tasks/mar-20261007-1508-fase-8a-ai-gateway/baseline/php-lint.txt`, 0 linhas: todo PHP tocado imprime `No syntax errors detected`).
- SUITE = `docker compose run --rm --no-deps -T -v /var/www/html/centralvet/src:/var/www/html/src:ro app php tests/run.php` (roda no `centralvet_test`; não filtra: use `| /usr/bin/grep -E '<Classe>|Failed:'`; não interromper; com outras SUITEs da onda rodando, FAIL de teste Redis ou deadlock de arquivo alheio é ruído). Classe nova aparece sem rebuild (PSR-4 do host: `CentralVet\` → `src/app/Core/`, `CentralVet\Tests\` → `src/tests/`). Controllers Adianti só carregam em subprocesso com `require "init.php"` (padrão de `src/tests/Integration/BedFormIntegrationTest.php`).
- PYTEST57 = `python3 scripts/test-prepare-mysql57.py` (baseline `/var/www/html/centralvet/.claude/tasks/mar-20261007-1508-fase-8a-ai-gateway/baseline/test-prepare-mysql57.txt`: `Ran 8 tests`, `OK`).
- Use `/usr/bin/grep` (no zsh, `grep` é função e devolve 0).
- Proibidos: git `add -A`/`add .`, `commit -a`, `checkout`, `switch`, `reset`, `stash`, `restore`, `rebase`, `push`, apagar `.git/index.lock` (repita o commit após alguns segundos); docker `build`, `up`, `restart`, `stop`, `down` (rebuild e restart são do orquestrador); qualquer SQL que não seja SELECT e qualquer escrita no Redis fora dos testes (skill `sql-write-approval`); aplicar migration ou DML; `composer` (inclusive `composer require anthropic-ai/sdk`); escrever no `.env`; Playwright (só o validador navega); chamar a API da Anthropic ou qualquer rede externa (testes usam `FakeAiGateway`, transporte injetado ou `StubAiGateway`); editar `src/lib/adianti`, `src/app/config/framework_hashes.php` ou arquivo listado nele (`src/index.php`, `engine.php`, `init.php`, `src/composer.json`, `src/app/lib/include|menu|util|validator`, `src/app/lib/widget/TAccordion.php`, `src/app/templates/adminbs5/*` exceto `cv-components.css` e `js/cv-ai-dictation.js`).
- Segredos e dados pessoais (LGPD): a chave da API só vive em env (`ANTHROPIC_API_KEY`); nos testes use o valor falso `sk-ant-test-f8a`. Nunca grave chave, header `x-api-key`, prompt, texto livre, sugestão, nome de paciente/tutor, e-mail, telefone ou CPF em log, `error_log`, mensagem de exceção, auditoria (`metadata`) ou relatório; registre só ids, finalidade, modelo, tokens, custo e códigos. Registros de teste manuais levam o prefixo `F8A teste` (e-mail `f8a.teste@example.invalid`).
- Referência da API Claude: skill `claude-api` (IDs de modelo, headers, erros); não use memória para isso.
- Pré-requisitos: containers `app`, `mysql`, `redis` e `nginx` no ar (`docker compose ps`), comandos rodados de `/var/www/html/centralvet`; banco `centralvet_test` existente (com a 0014 a partir da Onda 2). Sem hooks de commit conhecidos. Regras de UI em `/var/www/html/centralvet/CLAUDE.md` e `.docs/design-system.md` (rótulos sempre à esquerda; alvos ≥ 44 px com `cv-touch-target`).
- PREP57 = `python3 scripts/prepare-mysql57.py src/app/database/migrations /tmp/claude-1000/f8a-out57` (só gera arquivos fora do repositório).

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
cat >> "/var/www/html/centralvet/.claude/tasks/mar-20261007-1508-fase-8a-ai-gateway/board.md" <<'EOF'
- [T-NN] <fato>
EOF
```

- O que bloqueia você ou invalida um `Consome` de outra task: além do board, `SendMessage` para `main` com uma linha: `[T-NN] <fato> — ver /var/www/html/centralvet/.claude/tasks/mar-20261007-1508-fase-8a-ai-gateway/board.md`.
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
