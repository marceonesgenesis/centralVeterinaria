# Design system mínimo — Central Vet Pro

O tema estende o AdminBS5 em `src/app/templates/adminbs5/custom.css`, sem alterar
o CSS do framework. Classes próprias usam o prefixo `cv-`; tokens usam `--cv-`.

## Tokens

- Cores são semânticas: `primary`, `surface`, `text`, `border`, `focus`, `info`,
  `success`, `warning` e `danger`. Existem valores equivalentes para dark mode.
- Espaçamento segue escala de 4, 8, 12, 16, 24 e 32 px.
- Raios: `sm`, `md`, `lg`; sombras: `sm`, `md`.
- Alvo de toque: 44 px (`--cv-touch-target`).
- Breakpoints: mobile até 36 rem, tablet até 64 rem e desktop acima de 64 rem.

## Componentes base

- `.cv-page`, `.cv-page-header`: estrutura e cabeçalho responsivos.
- `.cv-section`: agrupamento visual neutro.
- `.cv-state`: mensagem com ícone, título e descrição.
- Modificadores: `--loading`, `--empty`, `--error`, `--success`, `--denied` e `--info`.
- `.cv-skeleton`: indicação de carregamento, desativada com movimento reduzido.
- `.cv-table-scroll`: região de tabela navegável e rolável em telas estreitas.

## Alinhamento de rótulos

Rótulos ficam sempre alinhados à esquerda, em qualquer formulário, grid, cortina
lateral ou view: `.control-label`, `.col-form-label`, `label` e cabeçalhos de
`TDataGrid`. A regra global fica em `custom.css` e sobrepõe o alinhamento à direita
do `BootstrapFormBuilder` e o alinhamento por coluna do cabeçalho do grid. Em telas
novas, não alinhe rótulos à direita nem ao centro (nada de `style="text-align:right"`
em rótulo, nem `'center'`/`'right'` pensando no cabeçalho do `TDataGridColumn`).
Valores numéricos dentro das células podem continuar alinhados à direita.

Use `role="status"` para atualizações informativas e `role="alert"` apenas para
erros que exigem atenção imediata. Mantenha texto e ícone; cor não pode ser o
único indicador. Todos os controles precisam de nome acessível e foco visível.

A demonstração estática está em `/design-system.html`. Ela não depende de sessão,
não executa operações e não representa funcionalidades da Fase 1.
