# S6 — endpoint genérico `download.php`

Data: 2026-10-21 · Etapa 3 (T-05)

## Decisão

`src/download.php` está **desabilitado**. Qualquer requisição recebe
`404` com corpo vazio, antes de `init.php` e sem ler o parâmetro `file`
(nenhum `readfile`, `fopen`, `file_get_contents` nem `realpath`). O evento
fica no log como `download.disabled`, sem o caminho pedido.

Antes, o endpoint servia qualquer arquivo sob `src/files/` (exceto
`files/system/`) a qualquer usuário logado, sem checar o tenant: um
caminho de outra clínica seria entregue se existisse.

## Mapa dos usos

Framework (`src/lib/adianti`, não editável — passam a receber 404):

| Uso | Onde | Efeito |
|---|---|---|
| Link do arquivo enviado | `lib/adianti/widget/form/TFile.php:302` | link `download.php?file=<valor>` ao lado do campo |
| Hiperlink para arquivo | `lib/adianti/widget/util/THyperLink.php:38` | `href` `download.php?file=<local>` |
| Imagem via download | `lib/adianti/widget/util/TImage.php:118` | `src` começando por `download.php` |
| Download por JavaScript | `lib/adianti/include/util/download.js:19` | `__adianti_download_file` abre `download.php?file=…&basename=…` |

Telas que chamam `TPage::openFile` (usa `__adianti_download_file`):

| Tela | Uso |
|---|---|
| `app/control/admin/SystemSQLPanel.php:242` | exportação do resultado da consulta |
| `app/control/admin/SystemTableList.php:148`, `:212` | exportação de tabela |
| `app/control/admin/SystemDatabaseExplorer.php:276`, `:371` | dump `.zip` do banco |

São telas da administração da plataforma; os arquivos que elas geram ficam
em `tmp/`, que o endpoint já recusava (fora de `files/`). A exportação
dessas telas já não funcionava pelo `download.php` e continua indisponível.

## Constatações

- `src/files/` só contém `system/` (`documents/`, `messages/`), que já
  era bloqueado pelo endpoint. Não há arquivo de usuário servido por ele.
- Os documentos clínicos (receitas, atestados, termos, PDFs) usam
  mecanismo próprio, com checagem de tenant, e não dependem de
  `download.php`.

## Consequência

Um uso futuro que precise de download deve ter endpoint próprio, com
checagem de sessão, tenant e permissão, em vez de reabrir o endpoint
genérico.
