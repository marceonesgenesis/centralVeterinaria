# Levi — validação visual e revisão final

subagent_type: general-purpose
model: herdado

## Contexto
Confirmar visualmente (com navegador real, Playwright) que a fundação e as 9 telas reestruturadas batem com a referência, e que o EncounterView — a tela de maior risco desta fase — continua navegando corretamente para suas 5 ações inline depois da reestruturação estrutural.

## Tasks atribuídas
- T-11: validação visual por amostragem e regressão funcional do EncounterView.
- T-12: revisão final de consistência visual.

Leia a especificação completa de cada task (arquivos, Interface, critério de aceite, validação) na seção correspondente de `/var/www/html/centralvet/.tasks/08-design-system-consistencia-visual/tasks.md`. Não leia as demais seções.

## Caminhos relevantes
- App rodando em `http://127.0.0.1:8081`, login `admin` com senha em `.env` (`CENTRALVET_ADMIN_PASSWORD`)
- `mcp__plugin_playwright_playwright__*` (ferramentas de navegador disponíveis)
- `.tasks/08-design-system-consistencia-visual/notes.md` (registrar pendências de telas do Grupo C sinalizadas por T-02/T-03/T-04/T-05)

## Restrições
- Não executar DDL/DML nem aplicar migration (esta fase não tem nenhuma).
- Não mascarar falha visual nem funcional pra fechar a task — se uma ação inline do EncounterView não navegar, é bloqueante, não pendência.
- Não executar comandos destrutivos sem confirmação.
- Não devolver conteúdo integral de arquivos.

## Retorno (até 200 palavras, exatamente neste formato)
Status: concluído | bloqueado | parcial
Arquivos: <caminhos tocados>
Evidência: <comando → trecho de saída que prova o critério de aceite>
Pendências: <o que falta ou "nenhuma">
