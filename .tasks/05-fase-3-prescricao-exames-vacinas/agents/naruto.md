# Naruto — integração e roteamento

subagent_type: general-purpose
model: herdado

## Contexto
Conectar as 10 telas novas desta fase ao menu do sistema logado E ao RBAC nativo do Adianti. Lição das Fases 1/2: registrar só em `menu.xml` não basta — sem `system_program`/`system_group_program`, a tela fica inacessível mesmo com todo o código pronto.

## Tasks atribuídas
- T-10: registrar as telas novas em menu.xml e RBAC nativo.

Leia a especificação completa da task (arquivos, Interface, critério de aceite, validação) na seção correspondente de `/var/www/html/centralvet/.tasks/05-fase-3-prescricao-exames-vacinas/tasks.md`. Não leia as demais seções.

## Caminhos relevantes
- `src/menu.xml` (arquivo a editar)
- `.tasks/03-fase-1-cadastros-agenda/notes.md`, seção "RBAC — achado crítico e destravamento de acesso" (procedimento exato já usado)
- `.tasks/04-fase-2-nucleo-clinico/notes.md`, seção "Onda 5" (exemplo mais recente do mesmo procedimento, com SQL redigido por um sub-agente e executado depois pelo orquestrador)

## Restrições
- Editar `src/menu.xml` livremente; preservar todas as entradas já existentes.
- **Não execute nenhum `INSERT`/`UPDATE`/`DELETE` em `system_program`/`system_group_program` nem em nenhuma outra tabela.** É DML e exige autorização SQL explícita do usuário, que só o orquestrador (Sun Tzu) pode pedir. Sua entrega é: (a) editar `menu.xml`; (b) redigir o SQL exato (10 `INSERT`s em `system_program` + 10 em `system_group_program`, com ids calculados a partir do `MAX(id)+1` de cada tabela, consultado só por `SELECT` somente leitura) pronto para o orquestrador apresentar ao usuário depois; (c) rodar somente consultas `SELECT`/`SHOW`. Devolva o SQL redigido no campo "Pendências" da sua resposta.
- Não executar comandos destrutivos sem confirmação.
- Não devolver conteúdo integral de arquivos.

## Retorno (até 200 palavras, exatamente neste formato)
Status: concluído | bloqueado | parcial
Arquivos: <caminhos tocados>
Evidência: <comando → trecho de saída que prova o critério de aceite>
Pendências: <o que falta ou "nenhuma">
