# Naruto — integração, roteamento e permissão de desconto

subagent_type: general-purpose
model: herdado

## Contexto
Conectar as 8 telas novas desta fase ao menu do sistema logado E ao RBAC nativo do Adianti — lição repetida desde a Fase 1. Além disso, esta fase tem um requisito novo: "descontos por permissão" (PRD 8.19) precisa de uma checagem de autorização distinta da autorização padrão de unidade, e cabe a você investigar se o RBAC nativo do Adianti (`SystemUser::getMethods()`) já suporta granularidade por método, ou se a alternativa mais simples é outra.

## Tasks atribuídas
- T-12: registrar as telas novas em menu.xml e RBAC nativo, incluindo permissão de desconto.

Leia a especificação completa da task (arquivos, Interface, critério de aceite, validação) na seção correspondente de `/var/www/html/centralvet/.tasks/07-fase-5-financeiro-integrado/tasks.md`. Não leia as demais seções.

## Caminhos relevantes
- `src/menu.xml` (arquivo a editar)
- `.tasks/06-fase-4-procedimentos-estoque-vendas/notes.md`, seção "Onda 7" (procedimento exato já usado: SQL redigido por um sub-agente, executado depois pelo orquestrador)
- `.tasks/03-fase-1-cadastros-agenda/notes.md`, seção "RBAC — achado crítico e destravamento de acesso" (origem do procedimento; mencione `SystemUser::getPrograms()`/`getMethods()` — investigue o código-fonte real dessas duas, não só a nota, pra saber se `getMethods()` é usado por algo em produção hoje ou é só um método presente na classe sem consumidor real)
- `src/app/Core/Authorization/` (camada CentralVet\Authorization\ — `AdiantiProgramPermissionProvider`, ponto onde a checagem de desconto pode entrar como alternativa se o RBAC nativo não suportar granularidade por método sem trabalho maior)

## Restrições
- Editar `src/menu.xml` livremente; preservar todas as entradas já existentes.
- **Não execute nenhum `INSERT`/`UPDATE`/`DELETE` em `system_program`/`system_group_program` nem em nenhuma outra tabela.** É DML e exige autorização SQL explícita do usuário, que só o orquestrador (Sun Tzu) pode pedir. Sua entrega é: (a) editar `menu.xml`; (b) redigir o SQL exato das 8 telas (INSERTs em `system_program` + `system_group_program`, com ids calculados a partir do `MAX(id)+1` de cada tabela, consultado só por `SELECT` somente leitura); (c) investigar e registrar em `notes.md` a conclusão sobre a permissão de desconto, com o SQL redigido se o mecanismo nativo suportar, ou a alternativa proposta se não suportar; (d) rodar somente consultas `SELECT`/`SHOW`. Devolva o SQL redigido no campo "Pendências" da sua resposta.
- Não executar comandos destrutivos sem confirmação.
- Não devolver conteúdo integral de arquivos.

## Retorno (até 200 palavras, exatamente neste formato)
Status: concluído | bloqueado | parcial
Arquivos: <caminhos tocados>
Evidência: <comando → trecho de saída que prova o critério de aceite>
Pendências: <o que falta ou "nenhuma">
