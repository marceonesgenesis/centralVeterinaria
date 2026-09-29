# Ártemis — widgets de busca relacional

subagent_type: general-purpose
model: herdado

## Contexto
3 telas standalone (não alcançadas por nenhum fluxo contextual) hoje pedem que o usuário digite um ID numérico cru de tutor/paciente/produto/procedimento/profissional, ou ficam em branco sem nenhum seletor. `TDBUniqueSearch`/`TDBCombo` (framework Adianti) resolvem isso com busca por nome — já existem, só nunca foram usados em nenhuma tela customizada deste projeto (o único precedente é uma tela nativa do admin, `SystemUserForm.php`).

## Tasks atribuídas
- T-08: `AppointmentForm` — 3 campos.
- T-09: `SaleForm` — 4 campos.
- T-10: `VaccinationCardView` — seletor de paciente quando ausente.

Leia a especificação completa de cada task (arquivos, Interface, critério de aceite, validação) na seção correspondente de `/var/www/html/centralvet/.tasks/09-navegacao-jornada-clinica/tasks.md`. Não leia as demais seções.

## Caminhos relevantes
- `src/lib/adianti/widget/wrapper/TDBUniqueSearch.php` (assinatura do construtor: `__construct($name, $database, $model, $key, $value, $orderColumn, ?TCriteria $criteria)`)
- `src/app/control/admin/SystemUserForm.php` linha ~42-44 (único precedente de uso real no projeto, incluindo `TDBCombo` e `TDBUniqueSearch` lado a lado)
- `src/app/control/clinic/ProcedureInputForm.php` (`loadProductOptions()`, padrão já usado no projeto para popular um `TCombo` a partir de um catálogo pequeno — referência para o `service_id` de T-08)
- Cada arquivo-alvo já tem `resolveTenantContext()` como método próprio (duplicado em cada classe, mesmo padrão do resto do projeto) — reaproveitar, não recriar.
- Modelos: `src/app/model/clinic/{Tutor,Patient,Product}.php` (todos com atributo `tenant_id` confirmado — usar em `TCriteria`); `SystemUser` é nativo do Adianti, sem `tenant_id` (ver Decisões de arquitetura em `plan.md` para o tratamento de `professional_system_user_id`).

## Restrições
- Em T-09 (`SaleForm`), a lógica de negócio de fechamento de venda (`SaleService::create()`/cálculo de totais) não pode mudar — só os widgets de entrada dos 4 campos.
- Em T-10 (`VaccinationCardView`), o caminho contextual já existente (`patient_id` vindo por querystring de `VaccinationForm`) não pode mudar de comportamento — o seletor só aparece quando `patient_id` está ausente.
- Todo `TDBUniqueSearch` que busca em `Tutor`/`Patient`/`Product` precisa do `TCriteria` de `tenant_id` — nunca deixar sem filtro (vazamento entre tenants).
- Não executar comandos destrutivos sem confirmação.
- Não devolver conteúdo integral de arquivos.

## Retorno (até 200 palavras, exatamente neste formato)
Status: concluído | bloqueado | parcial
Arquivos: <caminhos tocados>
Evidência: <comando → trecho de saída que prova o critério de aceite>
Pendências: <o que falta ou "nenhuma">
