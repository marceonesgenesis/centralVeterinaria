# Aang — telas de caixa, contas a pagar e a conexão final

subagent_type: general-purpose
model: herdado

## Contexto
Construir as telas de sessão de caixa e de contas a pagar/lançamentos financeiros, e depois fazer a única conexão desta fase entre `EncounterView.php` e a conta do atendimento — sem tocar em mais nada do arquivo.

## Tasks atribuídas
- T-08: telas de sessão de caixa.
- T-09: telas de conta a pagar e lançamento financeiro.
- T-11: conectar EncounterView à conta do atendimento.

Leia a especificação completa de cada task (arquivos, Interface, critério de aceite, validação) na seção correspondente de `/var/www/html/centralvet/.tasks/07-fase-5-financeiro-integrado/tasks.md`. Não leia as demais seções.

## Caminhos relevantes
- `src/app/control/clinic/ServiceForm.php`, `ServiceList.php` (Fase 1 — padrão de tela de catálogo/listagem simples a replicar para conta a pagar/lançamento)
- `src/app/control/clinic/EncounterView.php`, método `inlineActionsPanel()`/array `$kinds` (Fases 3/4 — mecanismo real já usado 4 vezes para conectar uma ação da tela de atendimento a uma tela nova; T-11 é a quinta vez, mesma técnica). Se não houver uma entrada livre equivalente a reaproveitar, explore o arquivo pra achar o padrão real de adicionar um novo ponto de acesso e documente a decisão em `notes.md` — não invente um mecanismo novo sem antes conferir se o array já suporta uma chave adicional.
- `.tasks/06-fase-4-procedimentos-estoque-vendas/notes.md`, entrada de T-11 (relato exato da última vez que esse tipo de edição foi feita, inclusive o diff de uma linha só)

## Restrições
- T-11 só pode começar depois que T-07 (Tesla) estiver `[x]` em `tasks.md`.
- Em T-11, editar exclusivamente o ponto de acesso novo — nenhuma outra linha de `EncounterView.php` muda.
- Não editar `src/menu.xml` (fica para T-12).
- Respeitar literalmente os tokens de "Consome": chamar o Application service correspondente, nunca acessar `Persistence`/`Domain` diretamente do controller.
- Não executar comandos destrutivos sem confirmação.
- Não devolver conteúdo integral de arquivos.

## Retorno (até 200 palavras, exatamente neste formato)
Status: concluído | bloqueado | parcial
Arquivos: <caminhos tocados>
Evidência: <comando → trecho de saída que prova o critério de aceite>
Pendências: <o que falta ou "nenhuma">
