# Tesla — conta do atendimento e pagamento

subagent_type: general-purpose
model: herdado

## Contexto
Construir as duas telas mais complexas desta fase: a conta do atendimento (onde os itens se acumulam, o desconto é aplicado e a conta fecha) e a tela de registrar pagamento contra o recebível gerado por ela — a jornada completa "atendimento fecha financeiramente" do PRD.

## Tasks atribuídas
- T-07: tela de conta do atendimento.
- T-10: tela de registrar pagamento.

Leia a especificação completa de cada task (arquivos, Interface, critério de aceite, validação) na seção correspondente de `/var/www/html/centralvet/.tasks/07-fase-5-financeiro-integrado/tasks.md`. Não leia as demais seções.

## Caminhos relevantes
- `src/app/control/clinic/ProcedureExecutionForm.php`, `PrescriptionForm.php` (Fase 3/4 — padrão de tela que recebe `encounter_id`/`patient_id` via parâmetro de URL, a replicar em `EncounterAccountForm`)
- `src/app/control/clinic/SaleForm.php` (Fase 4 — padrão de carrinho com `TDataGrid` de itens acumulados antes de uma ação final, útil tanto pra listar itens da conta quanto pra estrutura geral de `PaymentForm`)
- `src/app/control/clinic/VaccineCatalogForm.php`/`VaccineProtocolForm.php` (Fase 3 — padrão de tela pai + ação filha, se for útil pra estruturar o botão de desconto separado do resto do formulário)

## Restrições
- T-10 só pode começar depois que T-06 (Athena), T-07 (você mesmo) e T-08 (Aang) estiverem `[x]` em `tasks.md` — precisa de `PaymentService` pronto e de saber como uma `CashSession` aberta é encontrada.
- Não editar `src/menu.xml` (fica para T-12) nem `EncounterView.php` (fica para T-11).
- Respeitar literalmente os tokens de "Consome": chamar o Application service correspondente, nunca acessar `Persistence`/`Domain` diretamente do controller.
- Não executar comandos destrutivos sem confirmação.
- Não devolver conteúdo integral de arquivos.

## Retorno (até 200 palavras, exatamente neste formato)
Status: concluído | bloqueado | parcial
Arquivos: <caminhos tocados>
Evidência: <comando → trecho de saída que prova o critério de aceite>
Pendências: <o que falta ou "nenhuma">
