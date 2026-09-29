# Hermes — telas de pendências (exame e recebimento)

subagent_type: general-purpose
model: herdado

## Contexto
Duas funcionalidades já existem no backend (registrar resultado de exame, receber pagamento) mas não têm nenhuma tela de listagem que aponte para elas — hoje só são alcançáveis com uma URL manual e o ID certo, que ninguém tem como adivinhar. Esta onda cria as 2 telas de listagem, no mesmo padrão já usado em `PayableList` para o caso simétrico ("contas a pagar" → aqui "resultados pendentes" e "contas a receber").

## Tasks atribuídas
- T-03: tela `PendingExamResultList`.
- T-04: tela `PendingReceivableList`.

Leia a especificação completa de cada task (arquivos, Interface, critério de aceite, validação) na seção correspondente de `/var/www/html/centralvet/.tasks/09-navegacao-jornada-clinica/tasks.md`. Não leia as demais seções.

## Caminhos relevantes
- `src/app/control/clinic/PayableList.php` (espelho estrutural exato: `onReload()` sobrescrito, `TDataGridAction` de linha, sem `TXMLBreadCrumb` até o registro em menu.xml, `resolveTenantContext()` duplicado no fim da classe)
- `src/app/control/clinic/ProductList.php` linha ~97 (`$action_batch`, precedente exato de `TDataGridAction(['OutraTela','onEdit'], ['id_do_pai' => '{id}', ...])`)
- `src/app/Core/Application/ExamService.php` (método `listPending()`, produzido por T-01 — Atenea) e `src/app/Core/Application/PaymentService.php` (método `listOpenReceivables()`, produzido por T-02 — Atenea)
- `src/app/control/clinic/ExamResultForm.php` e `src/app/control/clinic/PaymentForm.php` (telas de destino das ações de linha — confirmar o nome exato do parâmetro de querystring que cada uma espera: `exam_request_id` e `receivable_id`, respectivamente)

## Restrições
- Esta onda só abre depois que T-01 e T-02 (Atenea) estiverem `[x]` em `tasks.md`.
- Não editar `ExamService.php`, `PaymentService.php` nem qualquer arquivo de Persistence/Domain — são consumidos, não modificados.
- Não registrar as telas em `menu.xml` nem em `system_program` (é T-06/T-07, onda seguinte, agente Jano).
- Não executar comandos destrutivos sem confirmação.
- Não devolver conteúdo integral de arquivos.

## Retorno (até 200 palavras, exatamente neste formato)
Status: concluído | bloqueado | parcial
Arquivos: <caminhos tocados>
Evidência: <comando → trecho de saída que prova o critério de aceite>
Pendências: <o que falta ou "nenhuma">
