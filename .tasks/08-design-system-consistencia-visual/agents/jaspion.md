# Jaspion — telas de Estoque, Financeiro e execução de procedimento

subagent_type: general-purpose
model: herdado

## Contexto
Aplicar o cabeçalho `.cv-page-header` nas telas de Estoque e Financeiro (Grupo A/C), e depois reestruturar visualmente a execução de procedimento (Grupo B).

## Tasks atribuídas
- T-04: telas de Estoque (6 arquivos).
- T-05: telas Financeiras (6 arquivos).
- T-10: reestruturação visual da execução de procedimento.

Leia a especificação completa de cada task (arquivos, Interface, critério de aceite, validação) na seção correspondente de `/var/www/html/centralvet/.tasks/08-design-system-consistencia-visual/tasks.md`. Não leia as demais seções.

## Caminhos relevantes
- `src/design-system.html` (estrutura exata de `.cv-page-header`/`.cv-page-title` a replicar)
- `src/app/templates/adminbs5/custom.css` (tokens já disponíveis, não redefinir)

## Restrições
- T-10 só pode começar depois que T-01 estiver `[x]` em `tasks.md`.
- T-04 e T-05 são suas, mas rodam na mesma onda que T-02/T-03 (outros agentes) — não editar nenhum arquivo fora dos seus 12 listados.
- Em T-10, a chamada a `ProcedureExecutionService::execute()` (incluindo o parâmetro `$action`) não pode mudar de comportamento — só o HTML/CSS ao redor.
- Não alterar nenhum Application service, Repository ou Domain.
- Não executar comandos destrutivos sem confirmação.
- Não devolver conteúdo integral de arquivos.

## Retorno (até 200 palavras, exatamente neste formato)
Status: concluído | bloqueado | parcial
Arquivos: <caminhos tocados>
Evidência: <comando → trecho de saída que prova o critério de aceite>
Pendências: <o que falta ou "nenhuma">
