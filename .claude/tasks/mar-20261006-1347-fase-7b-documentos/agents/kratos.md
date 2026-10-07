# Kratos — Tela de pedido de documento

subagent_type: general-purpose
model: inherit

## Contexto
Fase 7B — Documentos (PRD §8.21): PDF assíncrono pelo worker (fila `document.generate`) para carteira de vacinação, receita, atestado e termo de consentimento cirúrgico, storage privado (driver local fora do webroot ou S3/MinIO) com versionamento, lista/download com RBAC e isolamento por tenant e unidade, e aviso `document_ready` pela comunicação da 7A só com opt-in. Permitir pedir os 4 tipos de documento a partir das telas clínicas, com texto de atestado editável e opção de aviso — existe porque é a porta de entrada do fluxo.

## Tasks atribuídas
- T-15: Tela de pedido de documento

Leia a especificação completa de cada task (arquivos, Interface, critério de aceite, validação) na seção correspondente de `/var/www/html/centralvet/.claude/tasks/mar-20261006-1347-fase-7b-documentos/tasks.md`. Não leia as demais seções.

## Caminhos relevantes
- `src/app/control/clinic/{CommunicationComposeForm,CommunicationMessageView}.php` (modelos: `CvCombo`, catches, `const TOUCH`)
- `src/app/lib/widget/{CvPage,CvCombo}.php`, `CvFormat::e`/`CvFormat::userError`
- `src/tests/Integration/BedFormIntegrationTest.php` (subprocesso)
- Board: `/var/www/html/centralvet/.claude/tasks/mar-20261006-1347-fase-7b-documentos/board.md`
- Relatórios: `/var/www/html/centralvet/.claude/tasks/mar-20261006-1347-fase-7b-documentos/reports/` (modelo em `_template.md`)
- Notas: `/var/www/html/centralvet/.claude/tasks/mar-20261006-1347-fase-7b-documentos/notes.md` — leia § Decisões tomadas e § Descobertas antes de começar (rulings de ondas anteriores vivem ali, não no board).

## Ambiente
- Repositório único `/var/www/html/centralvet` (raiz do projeto, checkout compartilhado): commite com `git -C /var/www/html/centralvet`.
- Branch `feat/fase-7b-documentos` (criada pelo orquestrador a partir de `feat/fase-7a-comunicacao` @ `83029c3`; não troque de branch).
- LINT = `docker compose run --rm --no-deps -T -v /var/www/html/centralvet/src:/var/www/html/src:ro app php -l <arquivo relativo a src/>` (baseline `/var/www/html/centralvet/.claude/tasks/mar-20261006-1347-fase-7b-documentos/baseline/php-lint.txt`, 0 linhas: todo PHP tocado imprime `No syntax errors detected`).
- SUITE = `docker compose run --rm --no-deps -T -v /var/www/html/centralvet/src:/var/www/html/src:ro app php tests/run.php` (roda no `centralvet_test`; não filtra: use `| /usr/bin/grep -E '<Classe>|Failed:'`; não interromper; com outras SUITEs da onda rodando, FAIL de teste Redis ou deadlock de arquivo alheio é ruído). Classe nova aparece sem rebuild (PSR-4 do host: `CentralVet\` → `src/app/Core/`, `CentralVet\Tests\` → `src/tests/`). Controllers Adianti só carregam em subprocesso com `require "init.php"` (padrão de `src/tests/Integration/BedFormIntegrationTest.php`).
- Use `/usr/bin/grep` (no zsh, `grep` é função e devolve 0).
- Proibidos: git `add -A`/`add .`, `commit -a`, `checkout`, `switch`, `reset`, `stash`, `restore`, `rebase`, `push`, apagar `.git/index.lock` (repita o commit após alguns segundos); docker `build`, `up`, `restart`, `stop`, `down` (rebuild e restart são do orquestrador); qualquer SQL que não seja SELECT e qualquer escrita no Redis fora dos testes (skill `sql-write-approval`); aplicar migration ou DML; `composer`; escrever no `.env`; Playwright (só o validador navega); apagar arquivos do volume `app_documents`; editar `src/lib/adianti`, `src/app/config/framework_hashes.php` ou arquivo listado nele (`src/index.php`, `engine.php`, `init.php`, `composer.json`, `src/app/lib/include|menu|util|validator`, `src/app/lib/widget/TAccordion.php`, `src/app/templates/adminbs5/*` exceto `cv-components.css`).
- Dados pessoais (LGPD): nome de paciente/tutor, e-mail, telefone, texto de atestado/termo e bytes de PDF nunca em log, `error_log`, URL, payload de fila, nome de arquivo, mensagem de exceção ou relatório; registre só ids, `kind`, `version` e códigos. Registros de teste manuais levam o prefixo `F7B teste` (e-mail `f7b.teste@example.invalid`).
- Pré-requisitos: containers `app`, `mysql`, `redis`, `nginx` e `worker` no ar (`docker compose ps`), comandos rodados de `/var/www/html/centralvet`; banco `centralvet_test` existente (com a 0013 a partir da Onda 2). Sem hooks de commit conhecidos. Regras de UI em `/var/www/html/centralvet/CLAUDE.md` e `.docs/design-system.md` (rótulos sempre à esquerda; alvos ≥ 44 px com `cv-touch-target`).
- Mensagens de exceção em inglês; cada mensagem nova vai para o board como `- [T-xx] i18n-domínio: <mensagem>` e cada `_t()` novo como `- [T-xx] i18n: <en> → <pt>` (T-19 traduz). Services não abrem transação; o controller abre `TTransaction::open('permission')`.

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
cat >> "/var/www/html/centralvet/.claude/tasks/mar-20261006-1347-fase-7b-documentos/board.md" <<'EOF'
- [T-NN] <fato>
EOF
```

- O que bloqueia você ou invalida um `Consome` de outra task: além do board, `SendMessage` para `main` com uma linha: `[T-NN] <fato> — ver /var/www/html/centralvet/.claude/tasks/mar-20261006-1347-fase-7b-documentos/board.md`.
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
