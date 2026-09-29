# Aang — telas de produto, venda e a conexão final

subagent_type: general-purpose
model: herdado

## Contexto
Construir as telas de Produto/lote e de Venda/PDV, e depois fazer a única edição cirúrgica em `EncounterView.php` desta fase — religar o botão "Procedimento" para a tela real, sem tocar em mais nada do arquivo.

## Tasks atribuídas
- T-07: telas de Produto e entrada de lote.
- T-10: tela de venda/PDV com recibo em PDF.
- T-11: conectar EncounterView à tela de execução de procedimento.

Leia a especificação completa de cada task (arquivos, Interface, critério de aceite, validação) na seção correspondente de `/var/www/html/centralvet/.tasks/06-fase-4-procedimentos-estoque-vendas/tasks.md`. Não leia as demais seções.

## Caminhos relevantes
- `src/app/control/clinic/ServiceForm.php`, `ServiceList.php` (Fase 1 — padrão de tela de catálogo simples a replicar para Produto)
- `src/app/control/clinic/PrescriptionForm.php` (Fase 3 — padrão de geração de PDF via dompdf, a replicar no recibo de venda)
- `src/app/control/clinic/EncounterView.php`, método `onInlineAction()` (branch `$kind === 'procedure'` hoje só grava `audit_log` — é a ÚNICA parte deste arquivo que T-11 pode tocar)
- `.tasks/05-fase-3-prescricao-exames-vacinas/notes.md`, seção da task T-09 (mesmo procedimento de religar um botão inline já foi feito 3 vezes nessa fase — replicar a técnica)

## Restrições
- T-11 só pode começar depois que T-07, T-08, T-09 e T-10 estiverem `[x]` em `tasks.md`.
- Em T-11, editar exclusivamente o branch `procedure` de `onInlineAction()` em `EncounterView.php` — nenhuma outra linha do arquivo muda.
- Não editar `src/menu.xml` (fica para T-12).
- Respeitar literalmente os tokens de "Consome": chamar o Application service correspondente, nunca acessar `Persistence`/`Domain` diretamente do controller.
- Não executar comandos destrutivos sem confirmação.
- Não devolver conteúdo integral de arquivos.

## Retorno (até 200 palavras, exatamente neste formato)
Status: concluído | bloqueado | parcial
Arquivos: <caminhos tocados>
Evidência: <comando → trecho de saída que prova o critério de aceite>
Pendências: <o que falta ou "nenhuma">
