# Jaspion — caixa, contas a pagar e lançamentos

subagent_type: general-purpose
model: herdado

## Contexto
Construir dois núcleos independentes entre si, mas ambos consumidos depois pelo serviço de pagamento (T-06, outro agente): a sessão de caixa por unidade, e as contas a pagar com seus lançamentos financeiros automáticos.

## Tasks atribuídas
- T-04: sessão de caixa — Domain, Repository e Application service.
- T-05: conta a pagar e lançamento financeiro — Domain, Repository e Application service.

Leia a especificação completa de cada task (arquivos, Interface, critério de aceite, validação) na seção correspondente de `/var/www/html/centralvet/.tasks/07-fase-5-financeiro-integrado/tasks.md`. Não leia as demais seções.

## Caminhos relevantes
- `src/app/Core/Application/StockService.php` (Fase 4, já corrigido na revisão final — padrão de operação simples que ainda assim precisa autorizar antes de gravar; `CashSessionService::open()`/`PayableService::create()` seguem a mesma disciplina, não pulem a autorização por parecer uma escrita "simples")
- `src/app/Core/Application/VaccineProtocolService.php` (Fase 3 — padrão de service filho sem update, útil para `PayableService`)
- `src/app/Core/Domain/Exception/` (pasta de exceções de domínio existentes, para seguir o mesmo padrão ao criar `CashSessionAlreadyOpenException`)

## Restrições
- Não editar arquivos fora dos listados nas tasks atribuídas.
- Não toque em nenhum arquivo de T-03/T-06 (outro agente trabalhando em paralelo nesta mesma onda, ou em onda posterior).
- `PayableService::pay()` deve chamar `FinancialEntryService::record()` internamente — ambos são desta mesma task (T-05), leia a assinatura real de `FinancialEntryService::record()` que você mesmo criar antes de chamá-la de `PayableService`.
- Respeitar literalmente os tokens de "Consome": não redefinir contratos.
- Não executar comandos destrutivos sem confirmação.
- Não devolver conteúdo integral de arquivos.

## Retorno (até 200 palavras, exatamente neste formato)
Status: concluído | bloqueado | parcial
Arquivos: <caminhos tocados>
Evidência: <comando → trecho de saída que prova o critério de aceite>
Pendências: <o que falta ou "nenhuma">
