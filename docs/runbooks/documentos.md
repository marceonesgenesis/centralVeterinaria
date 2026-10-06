# Documentos (Fase 7B)

Os documentos são PDFs gerados de forma assíncrona pelo worker, guardados em
storage privado com versionamento por fonte, listados e baixados só pela tela
com RBAC, tenant e unidade. O aviso `document_ready` ao tutor é opcional e só
sai com opt-in. Os PDFs síncronos já existentes (`PrescriptionForm::onGeneratePdf`,
recibo de venda e relatório de produtos) não mudam.

> Nenhum passo com efeito real (DDL/DML) roda sem autorização SQL específica
> do usuário (`sql-write-approval`). Ver [`migrations.md`](./migrations.md).
> Este runbook não contém credencial.

## Tipos e fontes

| Tipo (`kind`) | Fonte (`source_type`) | Observação |
|---|---|---|
| `vaccination_card` | `patient` | Carteira de vacinação; exige ao menos uma vacinação. |
| `prescription` | `prescription` | Receita arquivada, em paralelo ao PDF síncrono (ação "Arquivar PDF"). |
| `medical_certificate` | `patient` | Atestado: texto livre ou template do tenant; o texto fica congelado em `generated_document.body_text`. |
| `surgery_consent` | `surgery` | Termo de consentimento cirúrgico; usa o texto já registrado na cirurgia (6B), também congelado no pedido. |

Fora do MVP: orçamento, recibo de recebimento, laudo gerado e relatório de
alta.

## Fluxo assíncrono

1. `DocumentRequestForm` valida o pedido e grava `generated_document` com
   status `queued`; o controller publica o job `document.generate` (fila
   `default`) só depois do commit. O payload leva ids, nunca texto.
2. O worker faz o claim condicional (`claimed_at`), monta o conteúdo, gera o
   PDF (Dompdf), grava o objeto no storage e, numa transação, registra
   `stored_object`, marca `ready` (UPDATE condicional) e cria o aviso, se
   houver. Se a transação falha, o objeto gravado é apagado.
3. Estados: `queued → ready` ou `queued → failed`. São 3 tentativas
   (`MAX_ATTEMPTS = 3`); falha de fonte inexistente (`source_not_found`) vai
   direto para `failed`, as demais são retentadas e ficam `failed` na última.
   `last_error_code` guarda só um código.
4. Varredor (`DocumentSweeper`): republica o job de documentos `queued` há mais
   de 10 minutos (publicação perdida depois do commit). Roda no tick do worker
   contínuo e pelo comando `bin/document-sweep.php`.
5. Um documento `failed` aparece na Central de Pendências como
   `document_failed` (deep-link para `DocumentList`).

## Versionamento

Cada pedido é uma linha nova com `version = MAX + 1` por fonte
(`generated_document_version_uq` em `tenant_id, kind, source_type, source_id,
version`; colisão 1062 é retentada). Documentos antigos permanecem na lista.
O "Tentar de novo" de um `failed` reaproveita a mesma linha (mesma versão) e
republica o job.

## Storage

| Variável | Efeito |
|---|---|
| `DOCUMENT_STORAGE_DRIVER` | `local` (padrão) ou `s3`. |
| `DOCUMENT_STORAGE_LOCAL_ROOT` | Raiz do driver `local`; padrão `/var/www/html/var/documents`. Precisa ficar fora do webroot (`src/`): o driver recusa root dentro dele. |
| `DOCUMENT_SYSTEM_USER_ID` | Usuário (id positivo) registrado nas ações do worker; padrão `1`. |
| `DOCUMENT_SWEEP_INTERVAL_SECONDS` | Intervalo do tick do varredor no worker contínuo; padrão `600`; `0` desliga o tick. Não afeta o comando nem o `--once`. |

- **Docker**: o container `app` é `read_only`; a pasta de documentos é o
  volume nomeado `app_documents` montado em `/var/www/html/var/documents`
  (dono `www-data`, criada no `Dockerfile`). O volume é criado no
  `docker compose up -d` depois do rebuild de `app` e `worker`; sem rebuild o
  worker não enxerga as classes novas nem a pasta. Não apague arquivos do
  volume: `generated_document` e `stored_object` apontam para eles.
- **`s3`**: usa as variáveis `S3_*` já existentes (bucket `S3_BUCKET`). O
  bucket precisa existir antes: no ambiente local suba o MinIO com
  `docker compose --profile minio up -d` e crie o bucket. `STORAGE_DRIVER`
  dos anexos existentes não muda.
- **Hospedagem compartilhada**: pasta acima do `public_html` com permissão
  0750 (ver [`shared-hosting-mysql57.md`](./shared-hosting-mysql57.md)).

## Download e RBAC

O PDF só sai pelo controller, em
`engine.php?class=DocumentList&method=onDownload&id=<id>&static=1` (nova aba),
com RBAC, tenant e unidade ativa. Não há URL pré-assinada nem link público.
Documento inexistente, de outra unidade, `queued`, sem sessão ou sem permissão
devolve o mesmo 404 ("Document not found"), sem oráculo sobre a existência. O
nome do arquivo é `<kind>-<id>-v<versão>.pdf`, sem dado pessoal. Download e
retry são auditados com `entityId` = id e metadata
`{document_id, kind, version}`.

## Aviso `document_ready`

Só com "Avisar o tutor quando estiver pronto" marcado no pedido (`notify_tutor`)
e opt-in explícito do tutor no canal (base `consent`, regra de
[`comunicacao.md`](./comunicacao.md)). O texto vem do template ou do padrão da
7A, sem link e sem anexo: o tutor não tem acesso ao download. Dedupe por
`document_ready:document:<id>:<canal>`; `source_type` e `source_id` da
mensagem ficam NULL (os CHECKs da 7A não foram ampliados).

## Banco (migration 0013)

`20261006_0013_phase7b_documents.sql` (com `.verify.sql`) cria 2 tabelas:
`document_template` e `generated_document`; 9 CHECKs, 3 UNIQUEs
(`document_template_tenant_name_uq`, `generated_document_version_uq`,
`generated_document_stored_object_uq`) e 10 chaves estrangeiras, mais índices
nas colunas de FK. Nenhum dado é semeado e nenhuma tabela existente é
alterada. Rollback: restaurar o backup anterior ou migration reversa numerada
([`migration-rollback.md`](./migration-rollback.md)); não apague as tabelas à
mão depois de gerar documentos. Hospedagem MySQL 5.7: ver
[`shared-hosting-mysql57.md`](./shared-hosting-mysql57.md).

## Telas e permissões

4 programas: `DocumentList`, `DocumentRequestForm`, `DocumentTemplateList` e
`DocumentTemplateForm`, concedidos aos grupos 1 (`Template - Admin`), 2
(`Template - Users`), 4 (`Clínico – Internação`) e 5 (`Clínico – Cirurgia`),
16 concessões; o grupo 3 (`Application - Programs`) não recebe. A DML é
`T-04-programs.sql` da task, aplicada só com autorização SQL. Menu
"Documentos" (lista e templates); atalhos no paciente, na carteira de
vacinação, na cirurgia e na receita.

## Operação

```bash
# Varredor uma vez (JSON com tenants, republished, errors; sai 1 se errors > 0)
docker compose exec -T worker php bin/document-sweep.php
# Drenar a fila uma vez (inclui document.generate)
docker compose exec -T worker php bin/worker.php --once --max-seconds=50 --max-jobs=200
```

- **Reprocessar um `failed`**: em `DocumentList`, "Tentar de novo" na linha
  (confirmação, `failed → queued`, nova publicação). Não edite o banco.
- **Onde ficam os arquivos**: no driver `local`, em
  `DOCUMENT_STORAGE_LOCAL_ROOT` (volume `app_documents`); no `s3`, no bucket.
  O vínculo é `generated_document.stored_object_id` e `storage_key`.
- **Logs**: `document.job.<ready|skipped|failed|invalid>` e
  `document.sweep.completed/tenant_failed/failed`, só com ids, `kind`,
  `version` e códigos.

## LGPD

Nome de paciente ou tutor, e-mail, telefone, texto de atestado ou termo e
bytes do PDF nunca vão para log, `error_log`, URL, payload de fila, nome de
arquivo, mensagem de exceção ou relatório. `generated_document.body_text` e o
PDF são dados pessoais do histórico da clínica.

## Limites do MVP

- Sem retenção nem expurgo definidos para PDFs e `body_text`: defina a
  política antes de produção.
- Templates só para `medical_certificate`, com placeholders fechados.
- Sem assinatura digital, sem envio do PDF ao tutor e sem URL pública.
- Os anexos existentes continuam no `STORAGE_DRIVER`; migrá-los para a fábrica
  de documentos é pendência da hospedagem sem S3.
- Sem tela para apagar documento ou versão.
