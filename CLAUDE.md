# Central Vet Pro — regras do projeto

## Branches

- Produção: `main`
- Teste: `dev`

## Interface

- Rótulos sempre alinhados à esquerda em todo formulário, grid, cortina lateral e view.
  Não use `text-align:right`/`center` em rótulos nem cabeçalhos de grid. A regra global
  fica em `src/app/templates/adminbs5/custom.css`; detalhes em `.docs/design-system.md`
  § Alinhamento de rótulos.
- Alvos de toque com no mínimo 44 px (`--cv-touch-target` / `.cv-touch-target`).
