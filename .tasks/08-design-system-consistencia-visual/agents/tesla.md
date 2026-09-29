# Tesla — telas de Prontuário, conta do atendimento e pagamento

subagent_type: general-purpose
model: herdado

## Contexto
Aplicar o cabeçalho `.cv-page-header` nas telas de prontuário clínico (a mais heterogênea do Grupo A/C — pode revelar uma tela tão complexa quanto o Grupo B), e depois reestruturar visualmente conta do atendimento e pagamento (Grupo B).

## Tasks atribuídas
- T-03: telas de Prontuário clínico (10 arquivos).
- T-07: reestruturação visual da conta do atendimento.
- T-09: reestruturação visual do pagamento.

Leia a especificação completa de cada task (arquivos, Interface, critério de aceite, validação) na seção correspondente de `/var/www/html/centralvet/.tasks/08-design-system-consistencia-visual/tasks.md`. Não leia as demais seções.

## Caminhos relevantes
- `src/design-system.html` (estrutura exata de `.cv-page-header`/`.cv-page-title` a replicar)
- `src/app/templates/adminbs5/custom.css` (tokens já disponíveis, não redefinir)
- `.tasks/07-fase-5-financeiro-integrado/notes.md` (entradas de T-07/T-10, padrão de `$action` distinto por método usado em `EncounterAccountForm`/`PaymentForm` — não pode ser perdido na reestruturação)

## Restrições
- T-07 e T-09 só podem começar depois que T-01 estiver `[x]` em `tasks.md`.
- Não editar nenhum arquivo de T-02/T-04/T-05/T-06/T-08/T-10 (outros agentes trabalhando em paralelo).
- Em T-03, se `PrescriptionForm.php` (ou qualquer outra das 10) se revelar tão extensa/customizada quanto as telas do Grupo B, aplique só o cabeçalho e registre a constatação em `notes.md` — não force um layout completo de 2 colunas com abas nesta task.
- Em T-07/T-09, os nomes de `$action` passados aos Application services não podem mudar (alimentam a permissão de desconto granular da Fase 5).
- Não alterar nenhum Application service, Repository ou Domain.
- Não executar comandos destrutivos sem confirmação.
- Não devolver conteúdo integral de arquivos.

## Retorno (até 200 palavras, exatamente neste formato)
Status: concluído | bloqueado | parcial
Arquivos: <caminhos tocados>
Evidência: <comando → trecho de saída que prova o critério de aceite>
Pendências: <o que falta ou "nenhuma">
