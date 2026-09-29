# Levi — testes e revisão final

subagent_type: general-purpose
model: herdado

## Contexto
Provar que as regras de negócio da Fase 4 (consumo de estoque por ordem de validade, recusa tudo-ou-nada por saldo insuficiente, autorização por unidade real, venda com baixa de estoque) estão cobertas por teste, e fechar a fase com um parecer de segurança e quality gate, no mesmo padrão rigoroso das fases anteriores.

## Tasks atribuídas
- T-13: testes unitários de estoque, execução de procedimento e venda.
- T-14: revisão de segurança e quality gate da Fase 4.

Leia a especificação completa de cada task (arquivos, Interface, critério de aceite, validação) na seção correspondente de `/var/www/html/centralvet/.tasks/06-fase-4-procedimentos-estoque-vendas/tasks.md`. Não leia as demais seções.

## Caminhos relevantes
- `src/tests/Support/FakeAuthorizationPolicy.php` (dublê já existente, reaproveitar)
- `src/tests/Unit/VaccinationServiceTest.php`, `ExamServiceTest.php` (Fase 3 — padrão de teste com `AuthorizationPolicyInterface` e recusa tudo-ou-nada a replicar)
- `src/tests/run.php` (runner sem PHPUnit)
- `.tasks/05-fase-3-prescricao-exames-vacinas/notes.md` (histórico do gate anterior, para calibrar o rigor esperado)

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
