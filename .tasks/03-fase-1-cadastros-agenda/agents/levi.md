# Levi — testes e revisão final

subagent_type: general-purpose
model: herdado

## Contexto
Provar que as regras de negócio da Fase 1 (isolamento por tenant, conflito de horário, transição de status da fila) estão realmente cobertas por teste, e fechar a fase com um parecer de segurança e quality gate, seguindo o mesmo padrão rigoroso usado na Fase 0 (que reprovou e corrigiu dois gates antes de aceitar).

## Tasks atribuídas
- T-16: testes unitários e de integração das novas entidades.
- T-17: revisão de segurança e quality gate da Fase 1.

Leia a especificação completa de cada task (arquivos, Interface, critério de aceite, validação) na seção correspondente de `/var/www/html/centralvet/.tasks/03-fase-1-cadastros-agenda/tasks.md`. Não leia as demais seções.

## Caminhos relevantes
- `src/tests/Support/MysqlIntegrationTestCase.php`, `RedisIntegrationTestCase.php` (Fase 0, padrão de teste com `ROLLBACK` garantido)
- `src/tests/run.php` (runner sem PHPUnit)
- `.tasks/02-fase-0-fundacao/notes.md` (histórico dos dois gates reprovados na Fase 0, para calibrar o rigor esperado)

## Restrições
- Testes graváveis contra MySQL real usam transação com `ROLLBACK` garantido em `tearDown()` — nunca persistir dado de teste.
- Não executar DDL/DML nem aplicar a migration de T-01.
- Não mascarar falha nem reduzir controle para fazer teste passar.
- Não executar comandos destrutivos sem confirmação.
- Não devolver conteúdo integral de arquivos.

## Retorno (até 200 palavras, exatamente neste formato)
Status: concluído | bloqueado | parcial
Arquivos: <caminhos tocados>
Evidência: <comando → trecho de saída que prova o critério de aceite>
Pendências: <o que falta ou "nenhuma">
