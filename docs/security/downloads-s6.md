# S6 — endpoint genérico `download.php`

Data: 2026-10-21 · Etapa 3 (T-05)

## Decisão

`src/download.php` está **desabilitado**. Qualquer requisição recebe
`404` com corpo vazio, antes de `init.php` e sem ler o parâmetro `file`
(nenhum `readfile`, `fopen`, `file_get_contents` nem `realpath`). O evento
fica no log como `download.disabled`, sem o caminho pedido.

Antes, o endpoint servia qualquer arquivo sob `src/files/` (exceto
`files/system/`) a qualquer usuário logado, sem checar o tenant: um
caminho de outra clínica seria entregue se existisse. Qualquer caminho
fora de `files/` (`tmp/`, `app/output/`, nome solto na raiz) já era
recusado.

## Mapa dos usos

Levantamento: `grep -rnI 'download\.php' src --exclude-dir=vendor`
(12 arquivos, todos em `src/lib/adianti`, que não pode ser editado) mais as
chamadas de `TPage::openFile` / `__adianti_download_file` em `src/app`.
Nenhum arquivo de `src/app` referencia `download.php` diretamente.
`adianti.min.js` e `components.min.js` são as versões compactadas de
`adianti.js`, `download.js`, `tfile.js` e `tmultifile.js` e trazem as mesmas
chamadas.

Legenda da coluna "Antes": **recusado** = o endpoint antigo já não entregava
esse caminho (fora de `files/` ou dentro de `files/system/`).

### Framework (código que monta a URL)

| # | Uso | Arquivo:linha | Caminho pedido | Antes | Agora | Alternativa |
|---|---|---|---|---|---|---|
| 1 | Download por JS (`__adianti_download_file`, chamado por `TPage::openFile`) | `lib/adianti/include/util/download.js:19`, `lib/adianti/include/adianti.js:1290` (e `adianti.min.js`) | o que a tela passar; nas telas do app, `tmp/` | recusado | 404 em nova aba | nenhuma no app |
| 2 | Exportação PDF das listas padrão (`onExportPDF`, `<object data=…>`) | `lib/adianti/base/AdiantiStandardListExportTrait.php:87` | `app/output/<uniqid>.pdf` | recusado | janela "Export" com 404 | nenhuma |
| 3 | Exportação CSV/XML das listas padrão (`onExportCSV`/`onExportXML` → `TPage::openFile`) | `lib/adianti/base/AdiantiStandardListExportTrait.php` (`onExportXML`, `onExportCSV`) | `app/output/<uniqid>.csv` / `.xml` | recusado | 404 em nova aba | nenhuma |
| 4 | Link de visualização do `TFile` (modo `file`) | `lib/adianti/widget/form/TFile.php:302` | valor do campo (arquivo existente); no app, `tmp/` | recusado | link leva a 404; o app não usa `setDisplayMode('file')` | — |
| 5 | Pré-visualização/link do upload do `TFile` | `lib/adianti/include/components/tfile/tfile.js:102`, `:111`, `:112`, `:128`, `:143`, `:458` | nome do arquivo enviado, gravado em `tmp/` (`:458` monta `tmp/<nome>`) | recusado | popover, miniatura e link de visualização mostram 404; o upload em si funciona | nenhuma (o arquivo é processado no submit) |
| 6 | Pré-visualização/link do upload do `TMultiFile` | `lib/adianti/include/components/tmultifile/tmultifile.js:109`, `:118`, `:119`, `:135`, `:150` (e `components.min.js`) | nome do arquivo enviado, em `tmp/` | recusado | idem item 5 | anexos já enviados são baixados pelo `SystemDocumentDownloaderService` (`SystemMessageFormView.php:187`) |
| 7 | Imagem da assinatura do `TSignaturePad` | `lib/adianti/widget/form/TSignaturePad.php:231` | `fileName` gravado em `tmp/` | recusado | imagem quebrada; componente não usado no app | — |
| 8 | Imagem do `TImageCropper` | `lib/adianti/widget/form/TImageCropper.php:391` | `fileName` gravado em `tmp/` | recusado | imagem quebrada; componente não usado no app | — |
| 9 | `THyperLink` para arquivo local | `lib/adianti/widget/util/THyperLink.php:38` | `$location` quando é arquivo existente | recusado fora de `files/` | 404; o app não instancia `THyperLink` | — |
| 10 | `TImage` com fonte `download.php` | `lib/adianti/widget/util/TImage.php:118` | o que a fonte trouxer | depende | imagem quebrada; o app não usa essa fonte | — |

### Telas do app que acionam esses usos

| Tela | Arquivo:linha | Uso (#) | Caminho | Antes | Agora |
|---|---|---|---|---|---|
| Painel SQL — CSV | `app/control/admin/SystemSQLPanel.php:242` | 1 | `tmp/<aleatório>-<nome>` | recusado | 404 |
| Tabelas — CSV | `app/control/admin/SystemTableList.php:148`, `:212` | 1 | `tmp/<aleatório>-<nome>` | recusado | 404 |
| Explorador do banco — dump `.zip` | `app/control/admin/SystemDatabaseExplorer.php:276`, `:371` | 1 | `tmp/` | recusado | 404 |
| Explorador do banco — upload | `app/control/admin/SystemDatabaseExplorer.php:389` | 5 | `tmp/` | recusado | pré-visualização 404 |
| Usuários — Save as CSV/PDF/XML | `app/control/admin/SystemUserList.php:272-274` | 2, 3 | `app/output/` | recusado | 404 |
| Papéis — Save as CSV/PDF/XML | `app/control/admin/SystemRoleList.php:131-133` | 2, 3 | `app/output/` | recusado | 404 |
| Programas — Save as CSV/PDF/XML | `app/control/admin/SystemProgramList.php:163-165` | 2, 3 | `app/output/` | recusado | 404 |
| Unidades — Save as CSV/PDF/XML | `app/control/admin/SystemUnitList.php:142-144` | 2, 3 | `app/output/` | recusado | 404 |
| Grupos — Save as CSV/PDF/XML | `app/control/admin/SystemGroupList.php:141-143` | 2, 3 | `app/output/` | recusado | 404 |
| Agendamentos — Save as CSV/PDF/XML | `app/control/communication/schedule/SystemScheduleList.php:192-194` | 2, 3 | `app/output/` | recusado | 404 |
| Mensagem — anexos | `app/control/communication/messages/SystemMessageForm.php:45` | 6 | `tmp/` | recusado | pré-visualização 404 |
| Suporte — anexos | `app/control/admin/SystemSupportForm.php:45` | 6 | `tmp/` | recusado | pré-visualização 404 |
| Drive — upload de documento | `app/control/communication/documents/SystemDriveDocumentUploadForm.php:76` | 5 | `tmp/` | recusado | pré-visualização 404 |
| Uploads clínicos (`PatientForm:154`, `ExamResultForm:108`, `EncounterView:1522`, `ServiceImportForm:29`, `SystemProfileForm:41`) | — | 5 | `tmp/` | recusado | pré-visualização 404 |

## Constatações

- Nenhum uso perdeu função com a desativação: todos pediam `tmp/`,
  `app/output/` ou `files/system/`, que o endpoint antigo já recusava.
- `src/files/` só contém `system/` (`documents/`, `messages/`), que já
  era bloqueado pelo endpoint. Não há arquivo de usuário servido por ele.
- Os documentos clínicos (receitas, atestados, termos, PDFs) e os
  documentos/anexos do Drive e das mensagens usam mecanismo próprio
  (`SystemDocumentDownloaderService`), com checagem de acesso, e não
  dependem de `download.php`.

## Riscos

- **Botões visíveis que levam a 404.** As exportações "Save as CSV/PDF/XML"
  das 6 listas administrativas, o CSV do Painel SQL e das Tabelas, o dump do
  Explorador do banco e a pré-visualização de upload do `TFile`/`TMultiFile`
  continuam na interface e devolvem 404. Já não funcionavam antes (caminho
  recusado), então não é regressão, mas agora a quebra é definitiva. Tratar
  numa task futura: esconder as ações ou dar a elas um endpoint próprio, com
  checagem de sessão, tenant e permissão, sem alterar `src/lib/adianti`.
- **Documentação desatualizada.** `README.md:169-170` ("downloads
  autenticados passam somente por `download.php`") e
  `docs/security/csrf-inventario.md:172` descrevem o endpoint antigo;
  ficam para atualização fora desta task.

## Consequência

Um uso futuro que precise de download deve ter endpoint próprio, com
checagem de sessão, tenant e permissão, em vez de reabrir o endpoint
genérico.
