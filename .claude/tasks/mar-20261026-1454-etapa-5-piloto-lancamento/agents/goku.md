# Goku — telas de primeiros passos

subagent_type: general-purpose
model: inherit

## Contexto
Etapa 5 — Piloto e lançamento do MVP do Central Vet Pro: onboarding sem intervenção técnica, perfis restritos e jornada homologável em 2 tenants, termos e privacidade com aceite versionado, suporte, cancelamento pelo app, exportação LGPD e oferta alinhada ao recorte homologado por feature flag. Este agente cuida de: telas de primeiros passos.

## Tasks atribuídas
- T-15: Primeiros passos, dados da clínica e orientações do primeiro atendimento

Leia a especificação completa de cada task (arquivos, Interface, critério de aceite, validação) na seção correspondente de `/var/www/html/centralvet/.claude/tasks/mar-20261026-1454-etapa-5-piloto-lancamento/tasks.md`. Não leia as demais seções.

## Caminhos relevantes
- src/app/control/admin/ClinicUserForm.php, ClinicUnitForm.php (padrão de tela)
- src/app/control/clinic/ServiceList.php, ServiceImportForm.php
- .docs/design-system.md
- Board: `/var/www/html/centralvet/.claude/tasks/mar-20261026-1454-etapa-5-piloto-lancamento/board.md`
- Relatórios: `/var/www/html/centralvet/.claude/tasks/mar-20261026-1454-etapa-5-piloto-lancamento/reports/` (modelo em `_template.md`)
- Notas: `/var/www/html/centralvet/.claude/tasks/mar-20261026-1454-etapa-5-piloto-lancamento/notes.md` — leia § Decisões tomadas e § Descobertas antes de começar (rulings de ondas anteriores vivem ali, não no board).

## Ambiente
- Repositório único: commitar em `/var/www/html/centralvet` (raiz do projeto, árvore compartilhada da onda) com `git -C /var/www/html/centralvet`.
- Branch `task/etapa-5-piloto-lancamento` (base `task/etapa-4-operacao`); nunca push nem PR.
- Comandos de validação e baseline: LINT, LINTALL, BASHN, SUITE, MYSQLTEST, SHTEST e CI no cabeçalho de `/var/www/html/centralvet/.claude/tasks/mar-20261026-1454-etapa-5-piloto-lancamento/tasks.md`; baselines `/var/www/html/centralvet/.claude/tasks/mar-20261026-1454-etapa-5-piloto-lancamento/baseline/php-lint-all.txt` e `/var/www/html/centralvet/.claude/tasks/mar-20261026-1454-etapa-5-piloto-lancamento/baseline/bash-n.txt` (0 linhas: qualquer linha é nova). Testes PHP rodam no container `app` (PHP 8.4) com `src/` somente leitura; erro `class not found` = autoload quebrado (PSR-4 `CentralVet\` → `src/app/Core/`).
- Proibido: editar `src/lib/adianti`, `src/engine.php`, `src/index.php`, `src/init.php`, `src/composer.json`, `src/app/config/framework_hashes.php` ou arquivo listado nele (ex.: `app/lib/util/*`, `AdiantiMenuBuilder`); SQL de escrita no banco `centralvet` (dev) sem autorização expressa; escrever no `.env` da raiz; `docker compose down -v` no projeto `centralvet`; credencial real em código, teste, log, relatório ou prompt (prefixos `sk_` + `live_` só por concatenação; e-mails `@example.test`); chamar serviço externo pago ou a rede do Stripe; lint/formatador que reescreve arquivo; `composer update`; `git add -A`, `git stash`, `git checkout`, `git reset`, `git restore`; editar `translations.json`, `menu.xml` ou os seeds fora da sua task (chave de tradução nova vai ao board como `chave nova:`).
- Interface: rótulos alinhados à esquerda (nunca `text-align:right`/`center` em rótulo ou cabeçalho de grid) e ações com `.cv-touch-target` (44 px); textos ao usuário em pt-BR por `_t()`.
- Pré-requisitos: serviços `mysql` e `redis` do projeto `centralvet` no ar (`docker compose ps`), para a integração do SUITE não sair `SKIP`; imagem `centralvet/app:dev` presente.
- Aviso: suítes paralelas de outros agentes podem gerar 1213/1205 intermitentes no `centralvet_test`; rode de novo só a sua classe antes de concluir que falhou. Falha em teste de arquivo que não é seu é ruído da onda.

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
cat >> "/var/www/html/centralvet/.claude/tasks/mar-20261026-1454-etapa-5-piloto-lancamento/board.md" <<'EOF'
- [T-NN] <fato>
EOF
```

- O que bloqueia você ou invalida um `Consome` de outra task: além do board, `SendMessage` para `main` com uma linha: `[T-NN] <fato> — ver /var/www/html/centralvet/.claude/tasks/mar-20261026-1454-etapa-5-piloto-lancamento/board.md`.
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
