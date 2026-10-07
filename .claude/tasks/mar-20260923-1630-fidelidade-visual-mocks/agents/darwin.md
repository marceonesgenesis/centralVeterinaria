# Darwin — Estoque e vendas

subagent_type: general-purpose
model: inherit

## Contexto
Fase 10 do Central Vet Pro: levar casca e telas ao visual dos mocks com dados reais, sem IA e sem migration. Transforma ProductList em Estoque e Vendas e adapta os formulários de estoque, serviço e PDV à página cheia do kit.

## Tasks atribuídas
- T-10: ProductList → Estoque e Vendas
- T-17: Lote Estoque/vendas (formulários e PDV) no padrão + correções de serviço da onda 2 (`ServiceForm` update/tenant, filtro Inativo de `ServiceList`, PATTERN0). Inclui correções da onda 2 (achado, reprodução e validação na seção da task; convenção PATTERN0 no topo de `tasks.md`). T-17 tem Teste RED: o primeiro commit leva só `src/tests/Unit/ServiceCatalogServiceTest.php` e `src/tests/Support/FakeServiceRepository.php`, com `Task: T-17 (RED)`; a implementação em `src/app/Core` vai nos commits seguintes com `Task: T-17`. `ServiceList.php` (feito por Levi em T-11) agora é seu nesta onda: mexa só na leitura (`listAll()`) e no filtro de status.
- T-34: Onda 7 — correção (usuário), BASE `2c7611d` — `ProductService::update()` e `ProductForm` sem regra de edição no controller. A task tem Teste RED: o primeiro commit leva só os arquivos do bloco Teste RED, com `Task: T-34 (RED)`, e a implementação vai nos commits seguintes com `Task: T-34`; reproduza o achado da seção da task e cole-o em `## RED` junto com a falha.
- T-35: Onda 7 — correção (usuário), BASE `2c7611d` — "Ver tudo" de estoque baixo com `status=attention`, erro de tenant mostrado uma vez em `ProductList`, "Gerar PDF" do `SaleForm` em nova aba. Faça T-34 e T-35 em sequência, com commits separados por task. A task é `sem teste:`: reproduza o achado da seção da task antes de editar, cole a reprodução em `## RED` e commite com `Task: T-35`. Texto novo só por `_t('<chave en>')` com a chave e o pt da Interface; registre no board `- [T-35] i18n: <chave en> → <pt>`. Quem grava `translations.json` é T-36 (onda 8); até lá o gate aceita "Message not found" nesses rótulos.

Leia a especificação completa de cada task (arquivos, Interface, critério de aceite, validação) na seção correspondente de `/var/www/html/centralvet/.claude/tasks/mar-20260923-1630-fidelidade-visual-mocks/tasks.md`. Não leia as demais seções.

## Caminhos relevantes
- `/var/www/html/centralvet/src/app/control/clinic/ (ProductList, ProductForm, StockBatchForm, ServiceForm, SaleForm)`
- `/var/www/html/centralvet/src/app/lib/widget/ (kit, só leitura)`
- Mock: /tmp/claude-1000/-var-www-html-centralvet/004a97f0-faff-49ba-997a-092d97b4dffc/images/2.png
- Board: `/var/www/html/centralvet/.claude/tasks/mar-20260923-1630-fidelidade-visual-mocks/board.md`
- Relatórios: `/var/www/html/centralvet/.claude/tasks/mar-20260923-1630-fidelidade-visual-mocks/reports/` (modelo em `_template.md`)
- Notas: `/var/www/html/centralvet/.claude/tasks/mar-20260923-1630-fidelidade-visual-mocks/notes.md` — leia § Decisões tomadas e § Descobertas antes de começar (rulings de ondas anteriores vivem ali, não no board).

## Ambiente
- Repositório único `/var/www/html/centralvet` (git): commite na raiz do projeto, no checkout compartilhado (sem worktree), com `git -C /var/www/html/centralvet`. Rode os comandos docker a partir de `/var/www/html/centralvet`.
- Branch de trabalho: `feat/fidelidade-visual-mocks` (base `main` @ `9efef4e`), já em checkout quando você começa — confira com `git -C /var/www/html/centralvet branch --show-current`; se for outra, pare com `Status: bloqueado`, sem trocar de branch.
- Commits: um ou mais por task, sempre listando os caminhos da própria task e com o trailer `Task: T-NN` (teste falhando: `Task: T-NN (RED)`). Proibido `git add -A`, `git add .`, `git commit -a`, `git checkout`, `git switch`, `git reset`, `git stash`, `git restore`, `git rebase`, `git push`. Sem hooks de git no repositório. Se o commit falhar por `index.lock` (outro agente commitando), espere alguns segundos e repita — nunca apague o lock.
- Lint: `docker compose run --rm --no-deps -T -v /var/www/html/centralvet/src:/var/www/html/src:ro app php -l <arquivo>` → cada arquivo PHP tocado imprime `No syntax errors detected` (baseline sem erros: `baseline/php-lint.txt`, 0 linhas).
- Suíte: `docker compose run --rm --no-deps -T -v /var/www/html/centralvet/src:/var/www/html/src:ro app php tests/run.php` → `Failed: 0` (baseline 155/155 no planejamento; 186/186 no BASE `2c7611d` das ondas 7–8; sem filtro por arquivo — leia as linhas `PASS/FAIL  <Suite>\<Classe>::`).
- Proibido: `docker compose build`, `docker compose up`, `docker compose restart`, `docker compose down` (rebuild é do orquestrador, no gate); usar o navegador Playwright MCP (é do validador, no gate); SQL que escreve fora da transação de teste (skill `sql-write-approval`: só SELECT/SHOW sem aprovação); editar arquivos de `src/lib/adianti`, `index.php`, `engine.php` (listados em `framework_hashes.php`); `composer` que altere `composer.lock`/`vendor`.
- Pré-requisito: containers `app`, `mysql`, `redis` no ar (`docker compose ps`). Textos novos: use `_t('<chave en>')`; se a chave não estiver em `translations.json`, registre no board `- [T-xx] i18n: <chave en> → <texto pt>` (quem edita o JSON é Platão, em T-03/T-20).
- Restrição específica: Preservar `SaleService::create()` com `$action` e os widgets relacionais da fase 09 em `SaleForm`.

Commite com `git -C /var/www/html/centralvet`. Falha de ambiente (conexão recusada, credencial, container fora do ar) não é falha da task: relate-a em Pendências com o comando e a linha do erro, sem contornar.

## Antes de começar
Leia a task, o board e `notes.md`. Se faltar informação que causaria retrabalho ou quebra de contrato, pare **antes de editar qualquer arquivo do projeto**: escreva `reports/<ID>.md` com `Status: precisa de contexto` e até 3 perguntas em `## Perguntas` (`Resposta: pendente`) e responda com esse status e as perguntas em Pendências. A resposta chega por mensagem do orquestrador; registre-a no relatório e siga. Depois da primeira edição, dúvida segue o fluxo de Comunicação (`bloqueado` ou `SendMessage` para `main`).

## Teste primeiro (bloco Teste RED)
- **Com teste:**
  1. Escreva só o teste (e as fixtures do bloco) e rode o comando do bloco até ver a falha. Ela precisa vir da ausência do comportamento — asserção falhando ou classe que a task produz ainda inexistente —, não de setup, typo ou import errado.
  2. `git -C /var/www/html/centralvet add <arquivos do teste>` e `git -C /var/www/html/centralvet commit -m "<assunto>" -m "Task: T-NN (RED)" -- <arquivos do teste>`; cole comando e saída da falha em `## RED` do relatório (linha `FAIL  Integration\<Classe>::…` e o resumo `Total/Failed`), com o hash em "Commit RED". Só depois escreva a implementação.
  3. Implemente, rode de novo, cole a saída com `PASS` em `## Evidência` e commite os caminhos da implementação com `-m "Task: T-NN"`.
- **`sem teste: <motivo>`:** pule 1–2 e copie o motivo em `## RED` ("Commit RED": `sem teste: <motivo>`); commite a implementação com `-m "Task: T-NN"`.
- Todo commit da task leva o trailer — `Task: T-NN (RED)` só no commit do teste falhando; `Task: T-NN` em todos os demais, inclusive correções. O validador confere RED e escopo pelo trailer (`git log 9efef4e..HEAD`): commit sem ele reprova, e RED depois da implementação não tem correção.

## Comunicação
- Leia o board antes de começar e antes de usar cada `Consome`.
- Fato que afeta outras tasks (contrato divergente, símbolo renomeado, arquivo compartilhado alterado, chave i18n faltando): acrescente `- [<ID>] <fato>` ao board por append com heredoc, nunca com Edit — o delimitador entre aspas aceita qualquer caractere no fato, inclusive aspas simples. O `EOF` de fechamento vai na coluna zero, sem indentação:

```bash
cat >> "/var/www/html/centralvet/.claude/tasks/mar-20260923-1630-fidelidade-visual-mocks/board.md" <<'EOF'
- [T-xx] <fato>
EOF
```

- O que bloqueia você ou invalida um `Consome` de outra task: além do board, `SendMessage` para `main` com uma linha: `[T-xx] <fato> — ver /var/www/html/centralvet/.claude/tasks/mar-20260923-1630-fidelidade-visual-mocks/board.md`.
- Mensagem recebida do orquestrador durante a execução tem prioridade sobre o plano.

## Restrições
- Não editar arquivos fora dos listados nas tasks atribuídas, exceto `reports/<ID>.md` e o append em `board.md`.
- Commitar listando caminhos, com o trailer da task: `git add <caminhos>` seguido de `git commit -m "<assunto>" -m "Task: T-NN" -- <caminhos>` (só o `--` falha em arquivo novo sem add). Nunca `git add -A`, `git add .` nem `git commit -a`: a árvore é compartilhada com outros agentes da onda. Não commite `reports/`, `board.md` nem outros arquivos da pasta do plano (o fechador os registra no `chore(tasks)`), salvo arquivo listado na sua task.
- Nunca `git checkout`, `git switch`, `git reset`, `git stash` nem `git restore` na árvore compartilhada — destroem ou escondem o trabalho alheio. Nunca apague, mova ou reverta arquivo que não é seu; nada de `rm -rf`, `sed -i` em globs amplos ou reformatação de pastas inteiras.
- Falha em teste de arquivo que não é seu é ruído de task em andamento: relate a falha alheia em Pendências, sem corrigir.
- Respeitar literalmente os tokens de "Consome": não redefinir contratos.
- Dado que o schema não tem: omitir ou placeholder neutro, nunca valor inventado; registre em Pendências. Nenhum bloco de IA visível.
- Não despachar sub-agentes (nem revisores, nem ajudantes).
- Não executar comandos destrutivos sem confirmação.
- Não devolver conteúdo integral de arquivos.

## Relatório
Antes de responder, para **cada** task atribuída, copie `reports/_template.md` para `reports/<ID>.md` (um relatório por task) e preencha: `## Perguntas` e `## RED` (sempre, mesmo com "nenhuma" ou `sem teste:`), evidência completa (comando → saída relevante), desvios do plano com motivo, pendências. Correções pedidas depois são anexadas ao mesmo arquivo sob `## Correção N`.

## Retorno (até 120 palavras, exatamente neste formato)
A evidência completa mora em `reports/<ID>.md`; o retorno é o resumo para o orquestrador.
Status: concluído | bloqueado | parcial | precisa de contexto
Arquivos: <caminhos tocados> e reports/<ID>.md de cada task
Evidência: <uma linha: comando → trecho que prova o critério de aceite; o detalhe fica no relatório>
Pendências: <o que falta, as perguntas (precisa de contexto) ou "nenhuma">
