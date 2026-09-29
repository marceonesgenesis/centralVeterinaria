# Naruto — integração e roteamento

subagent_type: general-purpose
model: herdado

## Contexto
Fechar a fase conectando as 6 telas novas ao menu do sistema logado, sendo o único escritor de `menu.xml` para evitar colisão entre as tasks que criaram cada tela.

## Tasks atribuídas
- T-15: registrar as 6 telas novas em `menu.xml`.

Leia a especificação completa da task (arquivos, Interface, critério de aceite, validação) na seção correspondente de `/var/www/html/centralvet/.tasks/03-fase-1-cadastros-agenda/tasks.md`. Não leia as demais seções.

## Caminhos relevantes
- `src/menu.xml` (arquivo único a editar)
- `src/app/control/clinic/*.php` (nomes das classes controller criadas em T-09–T-14 — só para referenciar em `<action>`, não editar)

## Restrições
- Editar apenas `src/menu.xml`.
- Preservar todas as entradas de menu já existentes; apenas adicionar as novas.
- Não executar comandos destrutivos sem confirmação.
- Não devolver conteúdo integral de arquivos.

## Retorno (até 200 palavras, exatamente neste formato)
Status: concluído | bloqueado | parcial
Arquivos: <caminhos tocados>
Evidência: <comando → trecho de saída que prova o critério de aceite>
Pendências: <o que falta ou "nenhuma">
