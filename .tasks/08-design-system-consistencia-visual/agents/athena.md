# Athena — fundação visual e EncounterView

subagent_type: general-purpose
model: herdado

## Contexto
Estabelecer a fundação visual (cor de sidebar, marca, tipografia) que toda outra task desta fase depende, e depois fazer a reestruturação mais arriscada: o `EncounterView`, tela que concentra lógica de negócio de 3 fases diferentes.

## Tasks atribuídas
- T-01: fundação — cor de sidebar, logo e tipografia em custom.css/layout.html.
- T-06: reestruturação visual do EncounterView.

Leia a especificação completa de cada task (arquivos, Interface, critério de aceite, validação) na seção correspondente de `/var/www/html/centralvet/.tasks/08-design-system-consistencia-visual/tasks.md`. Não leia as demais seções.

## Caminhos relevantes
- `src/design-system.html` (referência viva dos componentes `.cv-*` já definidos)
- `src/app/templates/adminbs5/custom.css` (tokens `--cv-*` já existentes, ler antes de adicionar qualquer regra nova)
- `.tasks/06-fase-4-procedimentos-estoque-vendas/notes.md` e `.tasks/07-fase-5-financeiro-integrado/notes.md`, entradas de T-11 (mecanismo real usado pra religar botões do EncounterView nas Fases 4/5 — a T-06 desta fase NÃO pode quebrar esses pontos)
- Credencial de teste: usuário `admin`, senha em `.env` (`CENTRALVET_ADMIN_PASSWORD`), app em `http://127.0.0.1:8081`

## Restrições
- T-06 só pode começar depois que T-01 estiver `[x]` em `tasks.md`.
- Em T-06, os 5 pontos de navegação (`__adianti_goto_page` para PrescriptionForm/ExamRequestForm/ProcedureExecutionForm/VaccinationForm/EncounterAccountForm) e o mecanismo de autosave/ditado por voz não podem mudar de comportamento — só o HTML/CSS ao redor.
- Não alterar nenhum Application service, Repository ou Domain.
- Não executar comandos destrutivos sem confirmação.
- Não devolver conteúdo integral de arquivos.

## Retorno (até 200 palavras, exatamente neste formato)
Status: concluído | bloqueado | parcial
Arquivos: <caminhos tocados>
Evidência: <comando → trecho de saída que prova o critério de aceite>
Pendências: <o que falta ou "nenhuma">
