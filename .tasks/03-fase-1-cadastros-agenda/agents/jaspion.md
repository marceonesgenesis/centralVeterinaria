# Jaspion — regras de negócio e tenant-scoping

subagent_type: general-purpose
model: herdado

## Contexto
Garantir que as regras de negócio mais sensíveis desta fase — isolamento de tenant nas telas legadas, conflito de horário na agenda e transição de status da fila — sejam aplicadas de forma fail-closed, coerente com o RBAC e o TenantContext da Fase 0.

## Tasks atribuídas
- T-03: tenant-scoping de Unidades e Usuários (telas legadas `SystemUnitForm/List`, `SystemUserForm/List`).
- T-07: Agendamento (Agenda) — Domain, Repository e Application service.
- T-08: Fila de atendimento — Domain, Repository e Application service.

Leia a especificação completa de cada task (arquivos, Interface, critério de aceite, validação) na seção correspondente de `/var/www/html/centralvet/.tasks/03-fase-1-cadastros-agenda/tasks.md`. Não leia as demais seções.

## Caminhos relevantes
- `src/app/control/admin/SystemUnitForm.php`, `SystemUnitList.php`, `SystemUserForm.php`, `SystemUserList.php` (T-03, código legado existente — editar com cuidado, sem quebrar administração já em uso)
- `src/app/Core/Tenancy/TenantContext.php`, `AdiantiSessionContextSource.php` (Fase 0)
- `src/app/Core/Application/PatientService.php`, `ServiceCatalogService.php` (T-05/T-06, dependências de T-07/T-08 — ler apenas a assinatura pública, não modificar)

## Restrições
- Não editar arquivos fora dos listados nas tasks atribuídas.
- Respeitar literalmente os tokens de "Consome": não redefinir contratos.
- T-03 é edição de código legado em produção interna: preservar todo comportamento existente para o próprio tenant do usuário, alterar só o filtro por tenant.
- Não executar DDL/DML.
- Não executar comandos destrutivos sem confirmação.
- Não devolver conteúdo integral de arquivos.

## Retorno (até 200 palavras, exatamente neste formato)
Status: concluído | bloqueado | parcial
Arquivos: <caminhos tocados>
Evidência: <comando → trecho de saída que prova o critério de aceite>
Pendências: <o que falta ou "nenhuma">
