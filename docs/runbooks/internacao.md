# Internação (Fase 6A)

A internação cobre leitos, admissão, prescrição interna, administração,
evolução e parâmetros, flowboard do turno, transferência e alta integrada à
conta do atendimento (Fase 5) e ao estoque (Fase 4). O MVP é operacional: a
admissão parte sempre de um atendimento (`hospitalization.encounter_id NOT
NULL`), porque a conta é por atendimento.

> Nenhum passo com efeito real (DDL/DML) roda sem autorização SQL específica
> do usuário (`sql-write-approval`). Ver [`migrations.md`](./migrations.md).

## Fluxos

1. **Leito**: `BedList` e `BedForm` cadastram, ativam e desativam leitos por
   unidade (código único por unidade, diária em `daily_rate_cents`).
2. **Admissão**: no atendimento, o plano clínico `hospitalization` abre
   `HospitalizationAdmissionForm`. A ocupação do leito é um UPDATE condicional
   (`status = 'available'`); se outro tablet ocupou antes, a admissão inteira
   é desfeita (um único `TTransaction`). A diária do leito é copiada para a
   internação.
3. **Prescrição**: `HospitalizationOrderForm` (medicação, alimentação ou
   procedimento) gera a agenda inteira na criação.
4. **Administração**: `HospitalizationAdministrationForm` registra Feito ou
   Não feito (motivo obrigatório). Toque duplo concorrente não duplica:
   o UPDATE só vale sobre `pending`.
5. **Evolução e parâmetros**: `HospitalizationEventForm` (`type=evolution` ou
   `vitals`) grava eventos append-only com autoria e horário.
6. **Flowboard**: `HospitalizationBoard` mostra a janela de 2 h do turno, com
   atrasadas e próximas administrações, por unidade.
7. **Transferência**: `HospitalizationView` troca o leito (libera o antigo,
   ocupa o novo, evento `transfer`). A diária não é recalculada.
8. **Alta**: `HospitalizationView` pede confirmação e executa
   `onDischarge` num único `TTransaction`: lança diárias e medicações na conta,
   baixa o estoque e libera o leito.

## Regras

- **Diária**: `max(1, ceil(horas/24))` entre `admitted_at` e a alta, com o
  valor copiado do leito na admissão. Item com valor 0 não é lançado.
- **Atraso de 30 min**: "atrasado" é derivado, sem job. Pendente com
  `scheduled_at` mais de 30 min no passado é `late`; feito com `performed_at`
  acima da tolerância é `done_late`.
- **Agenda até 30 dias**: `ends_at` é obrigatório e no máximo 30 dias após o
  início; frequência de 1 a 168 h; fim exclusivo. Medicação contínua é
  re-prescrita.
- **Baixa de estoque e cobrança na alta**: só administrações `done` de
  prescrições com produto. Quantidade = `quantity_per_administration` ×
  feitas, agregada por produto, motivo `hospitalization_consumption`
  (`StockService::consume`, FEFO). O preço vem de `product.sale_price_cents`;
  com `NULL` ou 0 o item não é cobrado, mas o estoque é baixado. Itens da
  conta entram como `hospitalization_stay` e `hospitalization_administration`
  (idempotentes pelo UNIQUE `(account_id, source_type, source_id)`).
- **Recusa**: estoque insuficiente ou conta do atendimento fechada recusa a
  alta inteira (o `TTransaction` desfaz tudo).
- **Suspender prescrição**: cancela as administrações pendentes futuras.
- **Autorização**: toda action passada a service é `Classe::método`
  (por exemplo `HospitalizationView::onDischarge`); a unidade vem do recurso
  persistido (`requiresUnitScope`).

## Banco de dados

Migration `20261005_0010_phase6a_hospitalization` (e o `.verify.sql` ao lado,
só `SELECT`), em `src/app/database/migrations/`:

| Tabela | Papel |
| --- | --- |
| `bed` | Leito por unidade; `bed_occupancy_ck` liga `occupied` a `current_hospitalization_id` (sem FK, evita ciclo) |
| `hospitalization` | Internação; `hospitalization_discharge_ck` liga `discharged` a `discharged_at` |
| `hospitalization_order` | Prescrição; CHECKs de tipo, via, status, frequência, período e produto |
| `hospitalization_administration` | Agenda; UNIQUE `(order_id, scheduled_at)` |
| `hospitalization_event` | Admissão, transferência, evolução, parâmetros e alta |

CHECKs alterados (`DROP CHECK` seguido de `ADD CONSTRAINT`):

- `encounter_account_item_source_type_ck` ganha `hospitalization_stay` e
  `hospitalization_administration`;
- `stock_movement_reason_ck` ganha `hospitalization_consumption`.

No total são 16 CHECKs (14 novos e 2 ampliados). Entre o `DROP CHECK` e o
`ADD CONSTRAINT` a tabela fica sem a regra: aplique em janela sem uso.

## Permissões

Oito programas, concedidos ao grupo 1 (`Template - Admin`) e ao grupo
`Clínico – Internação`; os grupos 2 e 3 não recebem nada:

`BedList`, `BedForm`, `HospitalizationBoard`, `HospitalizationAdmissionForm`,
`HospitalizationView`, `HospitalizationOrderForm`,
`HospitalizationAdministrationForm` e `HospitalizationEventForm`.

A DML está em `.claude/tasks/mar-20261005-1521-fase-6a-internacao/sql/`
(`T-05-programs.sql`, `.verify.sql`, `.rollback.sql`) e o seed de instalação
nova em `src/app/database/seeds/initial-application-programs.sql`. Os nomes
dos programas são ASCII; o nome do grupo tem acento, por isso os arquivos
começam com `SET NAMES utf8mb4;` e o cliente roda com
`--default-character-set=utf8mb4`. O verify confere 20 caracteres / 25 bytes.

## Aplicação e rollback

**Aplicação (MySQL 8)**

1. Backup: `./scripts/backup.sh` e `gzip -t var/backups/centralvet-<timestamp>.sql.gz`.
2. Anotar, só com `SELECT`, `COUNT(*)`/`MAX(id)` de `system_group`,
   `system_program` e `system_group_program`, e `COUNT(*)` de
   `encounter_account_item` e `stock_movement`.
3. `sha256sum` da migration em uma cópia temporária com o checksum de zeros
   trocado (procedimento em [`migrations.md`](./migrations.md)).
4. Aplicar a 0010 com o usuário de migration (`MIGRATION_DB_USER`) em
   `centralvet`; em `centralvet_test` com aprovação própria (bancos novos já
   nascem com ela via `scripts/test-db/provision.sh`).
5. Rodar o `.verify.sql`: 5 tabelas e os 16 CHECKs.
6. Aplicar `T-05-programs.sql` em `centralvet` e rodar o verify: 8
   concessões no grupo 1, 8 no `Clínico – Internação`, 0 nos grupos 2 e 3.

**MySQL 5.7 (hospedagem)**: gere o pacote com
`python3 scripts/prepare-mysql57.py` (ver
[`shared-hosting-mysql57.md`](./shared-hosting-mysql57.md)). O preparador
traduz `DROP CHECK` para `DROP TRIGGER IF EXISTS <ck>_bi/_bu` e adapta o
verify. Os `timestamp(6)` têm `DEFAULT CURRENT_TIMESTAMP(6)` para o 5.7 sem
`explicit_defaults_for_timestamp`. Confira com `python3
scripts/test-prepare-mysql57.py`.

**Rollback**: caminho preferido é restaurar o backup anterior
([`restore-backup.md`](./restore-backup.md),
[`migration-rollback.md`](./migration-rollback.md)), com aprovação própria.
Para desfazer só os programas, use `T-05-programs.rollback.sql` (DELETE por
nome, com nova aprovação). Desfazer a 0010 isolada exige migration reversa
(não redigida): remover os itens de conta e movimentos de estoque
`hospitalization*`, restaurar os dois CHECKs sem os valores novos, `DROP
TABLE` das 5 tabelas na ordem inversa das FKs e apagar a linha da versão em
`schema_migrations`.

## Limites do MVP

- Sem mapa gráfico de leitos, escalas clínicas avançadas (Glasgow, dor por
  escala validada), integração com cirurgia (6B), comunicação com o tutor
  (Fase 7) nem IA.
- Sem admissão direto do paciente (só via atendimento) e sem internação sem
  conta do atendimento. A admissão simultânea do mesmo paciente não tem guarda
  no banco.
- Sem item na Central de Pendências (ainda não há tela); o flowboard cobre os
  atrasos.
- Sem prescrição sem data de fim, sem recálculo de diária por transferência e
  sem cobrança de alimentação ou procedimento sem produto vinculado.
- Não altera `sale`/`sale_item`, o PDV nem `syncAutomaticItems`.
