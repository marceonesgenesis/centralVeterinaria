# Jaspion — regras de negócio (exame e vacina)

subagent_type: general-purpose
model: herdado

## Contexto
Implementar os dois domínios com mais regra de negócio própria desta fase: o ciclo de vida de um exame (solicitação → resultado) e a aplicação de vacina (com baixa de estoque e cálculo de próxima dose).

## Tasks atribuídas
- T-04: Exame — Domain, Repository e Application service.
- T-05: Vacina — Domain, Repository e Application service.

Leia a especificação completa de cada task (arquivos, Interface, critério de aceite, validação) na seção correspondente de `/var/www/html/centralvet/.tasks/05-fase-3-prescricao-exames-vacinas/tasks.md`. Não leia as demais seções.

## Caminhos relevantes
- `src/app/Core/Application/QueueEntryService.php` (Fase 1 — padrão de transição de status controlada)
- `src/app/Core/Application/AppointmentService.php`, `EncounterService.php` (Fase 1/2 — padrão de `AuthorizationPolicyInterface` com unidade real do agregado)
- `src/app/Core/Persistence/AbstractTenantRepository.php`, `TenantQuery.php` (Fase 0)
- `src/app/Core/Domain/Contract/ExamCatalogRepositoryInterface.php`, `ExamRequestRepositoryInterface.php`, `ExamResultRepositoryInterface.php`, `VaccineCatalogRepositoryInterface.php`, `VaccineProtocolRepositoryInterface.php`, `VaccinationRepositoryInterface.php` (T-02, contratos que você vai implementar)

## Restrições
- Não editar arquivos fora dos listados nas tasks atribuídas.
- Respeitar literalmente os tokens de "Consome": não redefinir contratos.
- Baixa de estoque de vacina é só um contador simples (`vaccine_catalog_item.stock_quantity` -1 por aplicação) — não construa um módulo de estoque completo.
- Não executar DDL/DML nem aplicar migration.
- Não executar comandos destrutivos sem confirmação.
- Não devolver conteúdo integral de arquivos.

## Retorno (até 200 palavras, exatamente neste formato)
Status: concluído | bloqueado | parcial
Arquivos: <caminhos tocados>
Evidência: <comando → trecho de saída que prova o critério de aceite>
Pendências: <o que falta ou "nenhuma">
