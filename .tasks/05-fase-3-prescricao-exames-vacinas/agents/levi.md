# Levi — testes e revisão final

subagent_type: general-purpose
model: herdado

## Contexto
Provar que as regras de negócio da Fase 3 (autorização por unidade, ciclo de vida do exame, baixa de estoque e próxima dose da vacina) estão cobertas por teste, e fechar a fase com um parecer de segurança e quality gate, no mesmo padrão rigoroso das fases anteriores.

## Tasks atribuídas
- T-11: testes unitários de Prescrição, Exame e Vacinação.
- T-12: revisão de segurança e quality gate da Fase 3.

Leia a especificação completa de cada task (arquivos, Interface, critério de aceite, validação) na seção correspondente de `/var/www/html/centralvet/.tasks/05-fase-3-prescricao-exames-vacinas/tasks.md`. Não leia as demais seções.

## Caminhos relevantes
- `src/tests/Support/FakeAuthorizationPolicy.php` (Fase 1 — dublê já existente, reaproveitar)
- `src/tests/Unit/AppointmentServiceTest.php`, `QueueEntryServiceTest.php`, `EncounterServiceTest.php` (Fase 1/2 — padrão de teste com `AuthorizationPolicyInterface` a replicar)
- `src/tests/run.php` (runner sem PHPUnit)
- `.tasks/04-fase-2-nucleo-clinico/notes.md` (histórico dos gates anteriores, para calibrar o rigor esperado)

## Restrições
- Não executar DDL/DML nem aplicar migration.
- Não mascarar falha nem reduzir controle para fazer teste passar.
- Não executar comandos destrutivos sem confirmação.
- Não devolver conteúdo integral de arquivos.

## Retorno (até 200 palavras, exatamente neste formato)
Status: concluído | bloqueado | parcial
Arquivos: <caminhos tocados>
Evidência: <comando → trecho de saída que prova o critério de aceite>
Pendências: <o que falta ou "nenhuma">
