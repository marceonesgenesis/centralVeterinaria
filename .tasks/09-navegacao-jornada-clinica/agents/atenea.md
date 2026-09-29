# Atenea — backend de pendências (exame e recebimento)

subagent_type: general-purpose
model: herdado

## Contexto
Duas funcionalidades (registrar resultado de exame, receber pagamento de conta) hoje não têm nenhum caminho de UI real para chegar nelas. A camada de Application precisa ganhar os métodos de listagem que as telas novas (T-03/T-04, agente Hermes) vão consumir. Um dos dois repositórios já está pronto; o outro precisa de um método novo.

## Tasks atribuídas
- T-01: `ExamService::listPending()`.
- T-02: `ReceivableRepositoryInterface::listOpen()` + implementação + `PaymentService::listOpenReceivables()`.

Leia a especificação completa de cada task (arquivos, Interface, critério de aceite, validação) na seção correspondente de `/var/www/html/centralvet/.tasks/09-navegacao-jornada-clinica/tasks.md`. Não leia as demais seções.

## Caminhos relevantes
- `src/app/Core/Persistence/ExamRequestRepository.php` (método `listPending()` já implementado, linha ~79 — T-01 só consome, não reimplementa)
- `src/app/Core/Application/PayableService.php` (padrão exato de wiring de autorização a seguir em `listOpenReceivables()`)
- `src/app/Core/Domain/Contract/PayableRepositoryInterface.php` (padrão de assinatura a espelhar, adaptado — ver Decisões de arquitetura em `plan.md`: `Receivable` não tem coluna de unidade)
- `src/tests/Support/FakeReceivableRepository.php` (precisa ganhar o mesmo método novo da interface, senão os testes existentes quebram por implementação incompleta)

## Restrições
- Não editar `src/app/control/clinic/*.php` (é trabalho de Hermes/Iris/Ártemis, ondas diferentes).
- Não alterar `src/app/Core/Persistence/ExamRequestRepository.php` (já está pronto, T-01 só chama).
- Nenhuma migration, nenhuma mudança de schema.
- Não executar comandos destrutivos sem confirmação.
- Não devolver conteúdo integral de arquivos.

## Retorno (até 200 palavras, exatamente neste formato)
Status: concluído | bloqueado | parcial
Arquivos: <caminhos tocados>
Evidência: <comando → trecho de saída que prova o critério de aceite>
Pendências: <o que falta ou "nenhuma">
