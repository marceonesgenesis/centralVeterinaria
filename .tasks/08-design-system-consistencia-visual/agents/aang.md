# Aang — telas de Recepção e PDV

subagent_type: general-purpose
model: herdado

## Contexto
Aplicar o cabeçalho `.cv-page-header` nas telas de Recepção (Grupo A/C, mais numeroso), e depois reestruturar visualmente o PDV (Grupo B).

## Tasks atribuídas
- T-02: telas de Recepção (10 arquivos).
- T-08: reestruturação visual do PDV.

Leia a especificação completa de cada task (arquivos, Interface, critério de aceite, validação) na seção correspondente de `/var/www/html/centralvet/.tasks/08-design-system-consistencia-visual/tasks.md`. Não leia as demais seções.

## Caminhos relevantes
- `src/design-system.html` (estrutura exata de `.cv-page-header`/`.cv-page-title` a replicar)
- `src/app/templates/adminbs5/custom.css` (tokens já disponíveis, não redefinir)

## Restrições
- T-08 só pode começar depois que T-01 estiver `[x]` em `tasks.md`.
- Não editar nenhum arquivo de T-03/T-04/T-05/T-06/T-07/T-09/T-10 (outros agentes trabalhando em paralelo).
- Em T-08, a chamada a `SaleService::create()` e a geração de PDF do recibo não podem mudar de comportamento — só o HTML/CSS ao redor.
- Não alterar nenhum Application service, Repository ou Domain.
- Não executar comandos destrutivos sem confirmação.
- Não devolver conteúdo integral de arquivos.

## Retorno (até 200 palavras, exatamente neste formato)
Status: concluído | bloqueado | parcial
Arquivos: <caminhos tocados>
Evidência: <comando → trecho de saída que prova o critério de aceite>
Pendências: <o que falta ou "nenhuma">
