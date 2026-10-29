# Aang — Conclusão integrada e ficha da cirurgia

subagent_type: general-purpose
model: inherit

## Contexto
Fase 6B — Cirurgia (MVP operacional): salas, agendamento a partir do atendimento com equipe, consentimento, checklist em 3 momentos, eventos, materiais e conclusão integrada à conta do atendimento (Fase 5), ao estoque (Fase 4), à internação (6A) e à agenda. Integrar cirurgia, conta do atendimento, estoque e agenda na conclusão, e expor a ficha com todas as ações de status. Existe porque a conclusão cruza quatro módulos e precisa ser tudo-ou-nada.

## Tasks atribuídas
- T-11: Conclusão integrada e retorno — SurgeryCompletionService + addSourcedItem com tipos de cirurgia
- T-14: Ficha da cirurgia (SurgeryView): status, cancelamento, conclusão, retorno, internar

Leia a especificação completa de cada task (arquivos, Interface, critério de aceite, validação) na seção correspondente de `/var/www/html/centralvet/.claude/tasks/mar-20261005-2234-fase-6b-cirurgia/tasks.md`. Não leia as demais seções.

## Caminhos relevantes
- `src/app/Core/Application/EncounterAccountService.php`, `StockService.php`, `AppointmentService.php`, `HospitalizationDischargeService.php` (modelo)
- `src/app/control/clinic/HospitalizationView.php` (modelo de T-14: `onAskDischarge`/`postedSummary`), `EncounterView.php::onScheduleFollowUp` (linhas 1767-1830)
- Board: `/var/www/html/centralvet/.claude/tasks/mar-20261005-2234-fase-6b-cirurgia/board.md`
- Relatórios: `/var/www/html/centralvet/.claude/tasks/mar-20261005-2234-fase-6b-cirurgia/reports/` (modelo em `_template.md`)
- Notas: `/var/www/html/centralvet/.claude/tasks/mar-20261005-2234-fase-6b-cirurgia/notes.md` — leia § Decisões tomadas e § Descobertas antes de começar (rulings de ondas anteriores vivem ali, não no board).

## Ambiente
- Repositório único `/var/www/html/centralvet` (raiz do projeto, checkout compartilhado): commite com `git -C /var/www/html/centralvet`.
- Branch `feat/fase-6b-cirurgia` (criada pelo orquestrador a partir de `feat/fase-6a-internacao`; não troque de branch).
- LINT = `docker compose run --rm --no-deps -T -v /var/www/html/centralvet/src:/var/www/html/src:ro app php -l <arquivo relativo a src/>` (baseline `/var/www/html/centralvet/.claude/tasks/mar-20261005-2234-fase-6b-cirurgia/baseline/php-lint.txt`, 0 linhas: todo PHP tocado imprime `No syntax errors detected`).
- SUITE = `docker compose run --rm --no-deps -T -v /var/www/html/centralvet/src:/var/www/html/src:ro app php tests/run.php` (roda no `centralvet_test`; não filtra: use `| /usr/bin/grep -E '<Classe>|Failed:'`; não interromper; com outras SUITEs de agentes da onda rodando, FAIL de teste Redis ou deadlock de arquivo alheio é ruído). Classe nova aparece sem rebuild (PSR-4 do host). Controllers Adianti só carregam em subprocesso com `require "init.php"` (padrão de `src/tests/Integration/BedFormIntegrationTest.php`).
- Use `/usr/bin/grep` (no zsh, `grep` é função e devolve 0).
- Proibidos: git `add -A`/`add .`, `commit -a`, `checkout`, `switch`, `reset`, `stash`, `restore`, `rebase`, `push`, apagar `.git/index.lock` (repita o commit após alguns segundos); docker `build`, `up`, `restart`, `stop`, `down` (rebuild e restart são do orquestrador); qualquer SQL que não seja SELECT e qualquer escrita no Redis (skill `sql-write-approval`); aplicar migration ou DML; `composer`; Playwright (só o validador navega); editar `src/lib/adianti`, `src/app/config/framework_hashes.php` ou arquivo listado nele (`src/index.php`, `engine.php`, `init.php`, `composer.json`, `src/app/lib/include|menu|util|validator`, `src/app/lib/widget/TAccordion.php`, `src/app/templates/adminbs5/*` exceto `cv-components.css`).
- Pré-requisitos: containers `app`, `mysql`, `redis`, `nginx` no ar (`docker compose ps`), comandos rodados de `/var/www/html/centralvet`; banco `centralvet_test` existente (com a 0011 a partir da Onda 2). Sem hooks de commit conhecidos e sem `CLAUDE.md` no projeto: as regras estão aqui e em `plan.md § Premissas`. Registros de teste manuais levam o prefixo `F6B teste`.
- T-11: em `EncounterAccountService.php` só a lista de tipos de `addSourcedItem`; construtor e demais métodos intactos.
- Telas: textos em `_t('<en>')`; anote cada chave nova no board como `- [T-xx] i18n: <en> → <pt>` (T-19 é o único escritor de `translations.json`, `UserMessage.php` e `UserMessageTest.php`). `CvPage::header`, nunca `TXMLBreadCrumb`. Erro na tela só via `CvFormat::userError($e)` (o `ControllerRawExceptionMessageTest` reprova `getMessage()`); capture `AuthorizationDenied` e `MissingTenantContext`; ações `private const ACTION_X = 'Classe::método'`; texto clínico só por POST; botões com `cv-touch-target`. Não edite `menu.xml`, `CvNav.php` nem `EncounterView.php` (T-18) nem `cv-components.css` (T-16). O PHP do container em execução é o da imagem: a mudança só aparece em `http://127.0.0.1:8081` depois do rebuild do orquestrador.

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
cat >> "/var/www/html/centralvet/.claude/tasks/mar-20261005-2234-fase-6b-cirurgia/board.md" <<'EOF'
- [T-NN] <fato>
EOF
```

- O que bloqueia você ou invalida um `Consome` de outra task: além do board, `SendMessage` para `main` com uma linha: `[T-NN] <fato> — ver /var/www/html/centralvet/.claude/tasks/mar-20261005-2234-fase-6b-cirurgia/board.md`.
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
