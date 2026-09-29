# Tesla — telas de catálogo de procedimento e execução

subagent_type: general-purpose
model: herdado

## Contexto
Construir a tela de catálogo de procedimento (com seus insumos) e a tela de execução de procedimento dentro do atendimento — a tela que `EncounterView` vai abrir depois que T-11 religar o botão.

## Tasks atribuídas
- T-08: telas de catálogo de procedimento e insumos.
- T-09: tela de execução de procedimento.

Leia a especificação completa de cada task (arquivos, Interface, critério de aceite, validação) na seção correspondente de `/var/www/html/centralvet/.tasks/06-fase-4-procedimentos-estoque-vendas/tasks.md`. Não leia as demais seções.

## Caminhos relevantes
- `src/app/control/clinic/VaccineCatalogForm.php`, `VaccineCatalogList.php`, `VaccineProtocolForm.php` (Fase 3 — padrão exato de catálogo + lista filha a replicar para procedimento + insumos)
- `src/app/control/clinic/PrescriptionForm.php`, `ExamRequestForm.php` (Fase 3 — padrão de tela que recebe `encounter_id`/`patient_id` via parâmetro de URL, a replicar em `ProcedureExecutionForm`)
- `src/app/control/clinic/ServiceForm.php` (Fase 1 — padrão de campos simples de catálogo com preço)

## Restrições
- Não editar `src/menu.xml` (fica para T-12) nem `EncounterView.php` (fica para T-11).
- Respeitar literalmente os tokens de "Consome": chamar o Application service correspondente, nunca acessar `Persistence`/`Domain` diretamente do controller.
- Não executar comandos destrutivos sem confirmação.
- Não devolver conteúdo integral de arquivos.

## Retorno (até 200 palavras, exatamente neste formato)
Status: concluído | bloqueado | parcial
Arquivos: <caminhos tocados>
Evidência: <comando → trecho de saída que prova o critério de aceite>
Pendências: <o que falta ou "nenhuma">
