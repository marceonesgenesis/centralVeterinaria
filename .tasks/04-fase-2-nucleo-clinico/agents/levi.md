# Levi — testes e revisão final

subagent_type: general-purpose
model: herdado

## Contexto
Provar que as regras de negócio da Fase 2 (autorização por tenant/unidade em Encounter, ausência de chamada real a IA, prefixo de chave por tenant nos documentos) estão cobertas por teste, e fechar a fase com um parecer de segurança e quality gate, no mesmo padrão rigoroso das Fases 0 e 1.

## Tasks atribuídas
- T-08: testes unitários de Encounter e documentos.
- T-09: revisão de segurança e quality gate da Fase 2.

Leia a especificação completa de cada task (arquivos, Interface, critério de aceite, validação) na seção correspondente de `/var/www/html/centralvet/.tasks/04-fase-2-nucleo-clinico/tasks.md`. Não leia as demais seções.

## Caminhos relevantes
- `src/tests/Support/FakeAuthorizationPolicy.php` (Fase 1 — dublê já existente, reaproveitar em vez de recriar)
- `src/tests/Unit/AppointmentServiceTest.php`, `QueueEntryServiceTest.php` (Fase 1 — padrão de teste com `AuthorizationPolicyInterface` a replicar)
- `src/tests/run.php` (runner sem PHPUnit)
- `.tasks/03-fase-1-cadastros-agenda/notes.md` (histórico dos gates da Fase 1, para calibrar o rigor esperado)

## Restrições
- Não executar DDL/DML nem aplicar a migration de T-01.
- Não mascarar falha nem reduzir controle para fazer teste passar.
- Não executar comandos destrutivos sem confirmação.
- Não devolver conteúdo integral de arquivos.

## Retorno (até 200 palavras, exatamente neste formato)
Status: concluído | bloqueado | parcial
Arquivos: <caminhos tocados>
Evidência: <comando → trecho de saída que prova o critério de aceite>
Pendências: <o que falta ou "nenhuma">
