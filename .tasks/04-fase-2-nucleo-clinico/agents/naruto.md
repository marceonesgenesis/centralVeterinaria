# Naruto — integração e roteamento

subagent_type: general-purpose
model: herdado

## Contexto
Conectar `EncounterView` ao menu do sistema logado E ao RBAC nativo do Adianti. A Fase 1 descobriu que registrar só em `menu.xml` não basta — sem `system_program`/`system_group_program`, a tela fica inacessível mesmo com todo o código pronto. Esta task só é considerada concluída com um teste de acesso real, não apenas com a existência das linhas no banco.

## Tasks atribuídas
- T-07: registrar EncounterView em menu.xml e RBAC nativo.

Leia a especificação completa da task (arquivos, Interface, critério de aceite, validação) na seção correspondente de `/var/www/html/centralvet/.tasks/04-fase-2-nucleo-clinico/tasks.md`. Não leia as demais seções.

## Caminhos relevantes
- `src/menu.xml` (arquivo a editar)
- `.tasks/03-fase-1-cadastros-agenda/notes.md`, seção "RBAC — achado crítico e destravamento de acesso" (procedimento exato já usado: `system_program`/`system_group_program`, grupo "Template - Admin", ids manuais porque as tabelas não usam AUTO_INCREMENT)

## Restrições
- Editar `src/menu.xml` livremente; preservar todas as entradas já existentes.
- **Não execute nenhum `INSERT`/`UPDATE`/`DELETE` em `system_program`/`system_group_program` nem em nenhuma outra tabela.** É DML e exige autorização SQL explícita do usuário, que só o orquestrador (Sun Tzu) pode pedir em uma troca direta com ele — isso está fora do seu escopo como sub-agente. Sua entrega é: (a) editar `menu.xml`; (b) redigir o SQL exato (`INSERT` completo, com os ids calculados a partir do `MAX(id)+1` de cada tabela, consultado só por `SELECT` somente leitura) pronto para o orquestrador apresentar ao usuário depois; (c) rodar somente consultas `SELECT`/`SHOW` para levantar esse SQL. Devolva o SQL redigido no campo "Pendências" da sua resposta.
- Não executar comandos destrutivos sem confirmação.
- Não devolver conteúdo integral de arquivos.

## Retorno (até 200 palavras, exatamente neste formato)
Status: concluído | bloqueado | parcial
Arquivos: <caminhos tocados>
Evidência: <comando → trecho de saída que prova o critério de aceite>
Pendências: <o que falta ou "nenhuma">
