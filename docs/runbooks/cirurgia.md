# Cirurgia (Fase 6B)

A cirurgia cobre salas, agendamento a partir do atendimento com equipe,
consentimento, checklist de segurança em três momentos, eventos clínicos,
materiais e conclusão integrada à conta do atendimento (Fase 5), ao estoque
(Fase 4), à internação (6A) e à agenda. O MVP é operacional: a cirurgia parte
sempre de um atendimento (`surgery.encounter_id NOT NULL`), porque a conta é
por atendimento. A entrada é a ação `surgery` do plano clínico em
`EncounterView`.

> Nenhum passo com efeito real (DDL/DML) roda sem autorização SQL específica
> do usuário (`sql-write-approval`). Ver [`migrations.md`](./migrations.md).

## Fluxos

1. **Sala**: `SurgeryRoomList` e `SurgeryRoomForm` cadastram, ativam e
   desativam salas por unidade (código único por unidade).
2. **Agendamento**: no atendimento, `SurgeryScheduleForm` escolhe sala,
   horário, procedimento do catálogo, cirurgião e equipe (cirurgião,
   anestesista, auxiliar, circulante). Nome e preço do procedimento são
   copiados do catálogo. A equipe é trocável em `scheduled`/`pre_op`
   (`SurgeryScheduleForm&id=`). Evento `scheduled`.
3. **Consentimento**: `SurgeryConsentForm` registra signatário, texto
   integral aceito, data e quem registrou (colunas de `surgery`, evento
   `consent`). Pode ser regravado até o início. O texto padrão vem da chave de
   tradução `Surgery consent default text`; o POST é a única via de gravação.
4. **Pré-operatório**: `SurgeryView` leva `scheduled` para `pre_op` (evento
   `status`).
5. **Checklist**: `SurgeryChecklistForm` confirma uma fase por vez
   (`sign_in` com 5 itens e `time_out` com 4, em `pre_op`; `sign_out` com 4,
   em `in_progress`). Todos os itens da fase são marcados juntos.
6. **Início**: `SurgeryView` leva a `in_progress`.
7. **Eventos e materiais**: `SurgeryEventForm` registra `pre_op`,
   `anesthesia`, `intra_op`, `complication` e `post_op` (texto obrigatório);
   `SurgeryMaterialForm` registra e remove materiais, só em `in_progress`.
8. **`sign_out`**: confirmado em `in_progress`, antes de concluir.
9. **Conclusão**: `SurgeryView::onComplete` roda num único `TTransaction`:
   baixa o estoque dos materiais, lança o procedimento e os materiais na
   conta e conclui.
10. **Internação e retorno**: depois de concluída, a ficha oferece a
    internação pós-operatória (abre `HospitalizationAdmissionForm` da 6A, em
    transação separada; o motivo não vai pela URL) e o agendamento do retorno
    (`AppointmentService::schedule` com o cirurgião; `followup_appointment_id`
    gravado sob trava, um retorno por cirurgia).
11. **Cancelamento**: só em `scheduled`/`pre_op`, com motivo obrigatório e só
    por POST (evento `cancellation`). Remarcar é cancelar e agendar de novo.

Telas de consulta: `SurgeryList` (lista) e `SurgeryAgendaView` (agenda de
salas, sem programa próprio). A ficha tem abas resumo, checklist, materiais e
eventos.

## Regras

- **Sobreposição de sala**: o banco não impede. O service trava a linha da
  sala (`SELECT ... FOR UPDATE`) e recusa horário que se sobreponha a
  cirurgia `scheduled`, `pre_op` ou `in_progress` da mesma sala. Conflito de
  agenda do cirurgião fica fora do MVP. Sala de outra unidade é recusada
  (`Surgery room <id> belongs to another unit`).
- **Transições**: `scheduled → pre_op → in_progress → completed`;
  `cancelled` só a partir de `scheduled`/`pre_op`. Transição inválida lança
  `InvalidStatusTransitionException`.
- **Requisitos de início**: consentimento registrado, `sign_in` e `time_out`
  confirmados.
- **Requisito de conclusão**: `sign_out` confirmado.
- **Checklist**: uma linha por item, UNIQUE `(surgery_id, phase, item_code)`.
  Toque duplo vira `Checklist phase "<fase>" is already confirmed for surgery
  <id>`; fase incompleta vira `All checklist items of phase "<fase>" must be
  checked`.
- **Materiais**: só em `in_progress`, removíveis até a conclusão
  (quantidade de 1 a 9999). Remover duas vezes lança `Material <id> was
  already removed`, sem evento.
- **Baixa e cobrança na conclusão**: baixa por `StockService::consume`
  (FEFO), motivo `surgery_consumption`, agregada por produto. Na conta entram
  `surgery_procedure` (`source_id` = cirurgia, preço copiado no agendamento) e
  `surgery_material` (`source_id` = material, preço de
  `product.sale_price_cents`); idempotentes pelo UNIQUE `(account_id,
  source_type, source_id)`. Item com valor 0 não é lançado, mas o estoque é
  baixado.
- **Recusa**: estoque insuficiente (`InsufficientStockException`) ou conta do
  atendimento fechada recusa a conclusão inteira; o `TTransaction` desfaz
  tudo e a cirurgia continua `in_progress`.
- **Travas de concorrência**: `SurgeryRepository::save` de linha existente só
  grava se o status no banco ainda é o esperado (reconferência `FOR UPDATE`);
  material, conclusão, retorno e troca de equipe chamam `lockStatus` antes de
  decidir. Cirurgia inexistente ou de outro tenant lança
  `CrossTenantReferenceException`.
- **Autorização**: toda action passada a service é `Classe::método` (por
  exemplo `SurgeryView::onComplete`); a unidade vem do recurso persistido.

## Banco de dados

Migration `20261005_0011_phase6b_surgery` (e o `.verify.sql` ao lado, só
`SELECT`), em `src/app/database/migrations/`:

| Tabela | Papel |
| --- | --- |
| `surgery_room` | Sala por unidade; UNIQUE `(tenant_id, system_unit_id, code)`; sem coluna para cirurgia (sem ciclo de FK) |
| `surgery` | Cirurgia; copia nome e preço do procedimento; colunas de consentimento, início, conclusão, cancelamento e `followup_appointment_id`; CHECKs de status, período e consistência de cada fase |
| `surgery_team` | Equipe; UNIQUE `(surgery_id, system_user_id, role)` |
| `surgery_checklist` | Um item confirmado por linha; UNIQUE `(surgery_id, phase, item_code)` |
| `surgery_event` | Linha do tempo append-only (eventos clínicos e de sistema) |
| `surgery_material` | Material usado, com quantidade; o estoque só baixa na conclusão |

CHECKs alterados (`DROP CHECK` seguido de `ADD CONSTRAINT`):

- `encounter_account_item_source_type_ck` ganha `surgery_procedure` e
  `surgery_material`;
- `stock_movement_reason_ck` ganha `surgery_consumption`.

No total são 13 CHECKs (11 novos e 2 ampliados), 3 UNIQUEs e 27 FKs. Entre o
`DROP CHECK` e o `ADD CONSTRAINT` a tabela fica sem a regra: aplique em janela
sem uso. A migration não semeia dados.

## Permissões

Nove programas (controllers), concedidos ao grupo 1 (`Template - Admin`) e ao
grupo novo `Clínico – Cirurgia`; os grupos 2, 3 e `Clínico – Internação` não
recebem nada:

`SurgeryRoomList`, `SurgeryRoomForm`, `SurgeryList`, `SurgeryScheduleForm`,
`SurgeryView`, `SurgeryConsentForm`, `SurgeryEventForm`,
`SurgeryChecklistForm` e `SurgeryMaterialForm`.

A DML é idempotente e segue este formato (nomes ASCII `Central Vet - Surgery
...`; 1 grupo, 9 programas e 18 concessões). Cada INSERT deriva o id de
`COALESCE(MAX(id),0)+1` no próprio comando (as tabelas não têm
AUTO_INCREMENT) e só roda `WHERE NOT EXISTS`; é compatível com o MySQL 5.7:

```sql
SET NAMES utf8mb4;
START TRANSACTION;

INSERT INTO system_group (id, name)
SELECT (SELECT COALESCE(MAX(id),0)+1 FROM system_group current_groups), 'Clínico – Cirurgia'
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM system_group g WHERE g.name='Clínico – Cirurgia');

-- repetir para cada um dos 9 controllers
INSERT INTO system_program (id, name, controller)
SELECT (SELECT COALESCE(MAX(id),0)+1 FROM system_program current_programs),
       'Central Vet - Surgery List', 'SurgeryList'
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM system_program p WHERE p.controller='SurgeryList');

-- repetir para cada controller, para o grupo 1 e para o grupo 'Clínico – Cirurgia'
INSERT INTO system_group_program (id, system_group_id, system_program_id)
SELECT (SELECT COALESCE(MAX(id),0)+1 FROM system_group_program current_links), 1, p.id
FROM system_program p WHERE p.controller='SurgeryList'
AND NOT EXISTS (SELECT 1 FROM system_group_program l
                WHERE l.system_group_id=1 AND l.system_program_id=p.id);

COMMIT;
```

Para o grupo novo, troque o `1` por `(SELECT id FROM system_group WHERE
name='Clínico – Cirurgia')`. Os arquivos completos (`T-04-programs.sql`,
`.verify.sql`, `.rollback.sql`) estão em
`.claude/tasks/mar-20261005-2234-fase-6b-cirurgia/sql/`. O nome do grupo tem
acento, por isso o cliente roda com `--default-character-set=utf8mb4`. O
verify confere 18 caracteres / 21 bytes, 9 concessões no grupo 1, 9 em
`Clínico – Cirurgia` e 0 nos demais.

## Aplicação e rollback

**Aplicação (MySQL 8)**

1. Backup: `./scripts/backup.sh` e `gzip -t var/backups/centralvet-<timestamp>.sql.gz`.
2. Anotar, só com `SELECT`, `COUNT(*)`/`MAX(id)` de `system_group`,
   `system_program` e `system_group_program` (esperado 4/117/127 antes da
   DML) e `COUNT(*)` de `encounter_account_item`, `stock_movement`,
   `appointment` e `hospitalization`.
3. `sha256sum` da migration em uma cópia temporária com o checksum de zeros
   trocado (procedimento em [`migrations.md`](./migrations.md)); o arquivo
   commitado mantém o placeholder.
4. Aplicar a 0011 com o usuário de migration (`MIGRATION_DB_USER`) em
   `centralvet`; em `centralvet_test` com aprovação própria.
5. Rodar o `.verify.sql`: 6 tabelas e os 13 CHECKs (11 novos, 2 ampliados).
6. Aplicar a DML de programas em `centralvet` com
   `--default-character-set=utf8mb4` e rodar o verify. Depois, `system_group`
   deve ter +1, `system_program` +9 e `system_group_program` +18.

**MySQL 5.7 (hospedagem)**: ver a seção "Migration 0011 (cirurgia)" em
[`shared-hosting-mysql57.md`](./shared-hosting-mysql57.md). Confira com
`python3 scripts/test-prepare-mysql57.py`.

**Rollback**: caminho preferido é restaurar o backup anterior
([`restore-backup.md`](./restore-backup.md),
[`migration-rollback.md`](./migration-rollback.md)), com aprovação própria.
Para desfazer só os programas, use `T-04-programs.rollback.sql` (DELETE por
nome, com nova aprovação). Desfazer a 0011 isolada exige migration reversa
(não redigida): remover itens de conta `surgery_*` (recalculando os totais) e
movimentos `surgery_consumption` devolvendo o saldo dos lotes, restaurar os
dois CHECKs sem os valores novos, `DROP TABLE` das 6 tabelas na ordem inversa
das FKs e apagar a linha da versão em `schema_migrations`. Não derrube as
tabelas à mão enquanto houver itens de conta ou movimentos de cirurgia.

## Limites do MVP

- Sem assinatura digital nem PDF do consentimento (Fase 7), sem comunicação
  com o tutor e sem IA.
- Sem cirurgia sem atendimento e sem cirurgia sem conta do atendimento.
- Sem conflito de agenda do cirurgião nem da equipe; só a sala é protegida.
- Sem remarcação (cancelar e agendar de novo) e sem alterar sala, horário ou
  procedimento depois de agendada.
- Checklist fixo no código (5 + 4 + 4 itens), sem personalização por clínica.
- Sem tipo ou categoria de procedimento cirúrgico: qualquer procedimento
  ativo do catálogo pode ser agendado.
- Sem item na Central de Pendências. Não altera `sale`/`sale_item`, o PDV nem
  `syncAutomaticItems`.
