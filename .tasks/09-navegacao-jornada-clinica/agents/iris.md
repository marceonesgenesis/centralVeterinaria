# Iris — link Tutor → Pacientes

subagent_type: general-purpose
model: herdado

## Contexto
`PatientList` já aceita `?tutor_id=...` desde a Fase 1, mas nenhuma tela de Tutor jamais linkou para ela — hoje o único jeito de ver os pacientes de um tutor é digitar a URL na mão. É a task mais simples desta fase: uma ação de linha a mais, reaproveitando uma tela que já funciona.

## Tasks atribuídas
- T-05: ação de linha "Pacientes" em `TutorList`.

Leia a especificação completa da task (arquivos, Interface, critério de aceite, validação) na seção correspondente de `/var/www/html/centralvet/.tasks/09-navegacao-jornada-clinica/tasks.md`. Não leia as demais seções.

## Caminhos relevantes
- `src/app/control/clinic/ProductList.php` linha ~97 (`$action_batch`, precedente exato de ação de linha navegando com um ID por querystring)
- `src/app/control/clinic/PatientList.php` (confirmar que `?tutor_id=...` já é aceito — não precisa mudar nada nela)

## Restrições
- Não editar `PatientList.php` (já aceita o parâmetro, nenhuma mudança necessária).
- Não editar `TutorForm.php`.
- Não executar comandos destrutivos sem confirmação.
- Não devolver conteúdo integral de arquivos.

## Retorno (até 200 palavras, exatamente neste formato)
Status: concluído | bloqueado | parcial
Arquivos: <caminhos tocados>
Evidência: <comando → trecho de saída que prova o critério de aceite>
Pendências: <o que falta ou "nenhuma">
