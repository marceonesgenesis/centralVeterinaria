# ADR 0003: Migrations MySQL versionadas e verificáveis

- Status: aceito
- Data: 2026-09-20

## Contexto

O baseline Adianti foi instalado antes de existir um histórico próprio de
migrations. DDL MySQL faz commits implícitos e a base contém dados iniciais.

## Decisão

Migrations Central Vet são SQL numerado em `app/database/migrations`, registram
nome e SHA-256 em `schema_migrations` e incluem prechecks/validação. A migration
de fundação cria um tenant inicial, vincula os dados legados, torna
`system_unit.tenant_id` obrigatório somente após o backfill e cria auditoria de
negócio separada dos logs técnicos do template.

## Consequências

Cada aplicação requer backup e autorização SQL específica. Falha intermediária
deve interromper o processo para inspeção; rollback destrutivo nunca é
automático. O hash registrado deve ser substituído pelo hash do artefato
aprovado pelo executor antes da execução.
