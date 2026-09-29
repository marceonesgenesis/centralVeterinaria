# Tesla — telas de exame e vacina

subagent_type: general-purpose
model: herdado

## Contexto
Construir as telas de Exame (catálogo/solicitação/resultado) e Vacina (catálogo/protocolo/aplicação/carteira) — os dois conjuntos de tela mais numerosos desta fase.

## Tasks atribuídas
- T-07: Telas de Exame.
- T-08: Telas de Vacina.

Leia a especificação completa de cada task (arquivos, Interface, critério de aceite, validação) na seção correspondente de `/var/www/html/centralvet/.tasks/05-fase-3-prescricao-exames-vacinas/tasks.md`. Não leia as demais seções.

## Caminhos relevantes
- `src/app/control/clinic/ServiceForm.php`, `ServiceList.php` (Fase 1 — padrão de tela de catálogo simples a replicar)
- `src/app/Core/Application/EncounterDocumentService.php` (Fase 2 — padrão de anexo via `StorageInterface`, a reaproveitar no resultado de exame)
- `src/app/control/clinic/PatientForm.php`, `PatientList.php` (Fase 1 — padrão de listagem escopada por um id relacionado, útil para `VaccinationCardView`)

## Restrições
- Não editar `src/menu.xml` (fica para T-10) nem `EncounterView.php` (fica para T-09).
- Respeitar literalmente os tokens de "Consome": chamar o Application service correspondente, nunca acessar `Persistence`/`Domain` diretamente do controller.
- Não executar comandos destrutivos sem confirmação.
- Não devolver conteúdo integral de arquivos.

## Retorno (até 200 palavras, exatamente neste formato)
Status: concluído | bloqueado | parcial
Arquivos: <caminhos tocados>
Evidência: <comando → trecho de saída que prova o critério de aceite>
Pendências: <o que falta ou "nenhuma">
