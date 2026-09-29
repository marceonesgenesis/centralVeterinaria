# Plano: Fase 3 — Prescrição/exames/vacinas

## Objetivo
Entregar o critério de saída do PRD para a Fase 3: "ambulatório integrado".
As ações inline de prescrição, exame e vacina, hoje só uma casca de UI na
Central de Atendimento (Fase 2), ganham persistência real: prescrições com
itens, catálogo e solicitação/resultado de exames, catálogo/protocolo e
aplicação de vacinas.

## Premissas
- Escopo segue literalmente o PRD (seções 8.11-8.13, 24, 26): prescrições
  (medicamento/dose/unidade/via/frequência/duração/orientação), exames
  (catálogo/preço/parceiro, solicitação vinculada ao atendimento, status,
  resultado estruturado/arquivo, pendência de análise), vacinação
  (catálogo/protocolo configurável, fabricante/lote/validade/dose/
  profissional, próxima dose, carteira, baixa de estoque).
- "Baixa de estoque" de vacina (citada em 8.13) vira um contador simples
  (`vaccine_catalog_item.stock_quantity`, decrementado ao aplicar) — não um
  módulo completo de estoque/lotes de produto/compras, que é escopo formal
  da Fase 4 ("Clínica impacta estoque/vendas", seção 8.17).
- "Carteira de vacinação" é uma listagem do histórico de `vaccination` de um
  paciente — não uma funcionalidade nova separada.
- Resultado de exame "estruturado/arquivo": campo de texto para o resultado
  estruturado; anexo de arquivo reaproveita o padrão já criado na Fase 2
  (`StorageInterface`, mesmo esquema de chave por tenant/entidade).
- Excluído por inviabilidade real no stack atual (documentado, não
  simulado): assinatura digital de prescrição (sem infraestrutura de
  certificado), alertas de interação medicamentosa (sem base de dados
  farmacológica), templates/favoritos de prescrição e busca rápida de
  medicamento (sem catálogo de medicamentos nesta fase — `medication_name`
  é texto livre). Geração de PDF simples da prescrição é viável (dompdf já
  no `composer.json`) e faz parte do escopo.
- "Procedimento" continua fora de escopo (ainda casca de UI no
  `EncounterView`, fica para a Fase 4).
- Lição das Fases 1/2: toda tela nova precisa de `menu.xml` **e**
  `system_program`/`system_group_program` (RBAC nativo) para ficar
  acessível de verdade — não só uma das duas.
- `EncounterView.php` (Fase 2) é um arquivo grande e já existente — as 3
  novas ações inline viram telas dedicadas próprias (arquivos novos, sem
  colisão), e uma única task no final faz uma edição pontual em
  `EncounterView.php` para linkar os botões já existentes a essas telas,
  em vez de várias tasks paralelas editando o mesmo arquivo.
- `RbacAuthorizationService`/`AuthorizationPolicyInterface` integrado desde
  o início nos três Application services novos (não como retrofit),
  fechando o boundary por unidade em cada criação (prescrição/solicitação
  de exame/aplicação de vacina herdam a unidade do `encounter` de origem).
- O projeto não é repositório Git; não inicializar git.

## Escopo

### Incluso
- Migration preparada (não aplicada) para `prescription`,
  `prescription_item`, `exam_catalog_item`, `exam_request`, `exam_result`,
  `vaccine_catalog_item`, `vaccine_protocol`, `vaccination`.
- Contratos de Domain para as 7 interfaces de repositório envolvidas.
- `PrescriptionService`, `ExamCatalogService`/`ExamService`,
  `VaccineCatalogService`/`VaccineProtocolService`/`VaccinationService`,
  todos fail-closed por tenant/unidade via `AuthorizationPolicyInterface`.
- Tela de Prescrição (formulário + PDF).
- Telas de Exame: catálogo (admin) + solicitação + registro de resultado.
- Telas de Vacina: catálogo (admin) + protocolo (admin) + aplicação +
  carteira (histórico por paciente).
- Edição pontual em `EncounterView.php` linkando as 3 ações inline
  existentes às telas novas.
- Registro de todas as telas novas em `menu.xml` + RBAC nativo, com
  verificação de acesso real (não só a linha no banco).
- Testes unitários e de integração.
- Revisão de segurança e quality gate de fechamento da fase.

### Excluído
- Assinatura digital de prescrição, alertas de interação medicamentosa,
  templates/favoritos de prescrição, busca rápida de medicamento (sem
  catálogo de medicamentos nesta fase).
- Módulo completo de estoque (lotes de produto, compras, movimentos,
  rastreabilidade) — Fase 4.
- "Procedimento" (ainda casca de UI no `EncounterView`) — Fase 4.
- Aplicação real da migration no MySQL e registro real em
  `system_program`/`system_group_program` (checkpoints de autorização SQL,
  como sempre).

## Contexto técnico
- Camadas envolvidas: database, backend (Core PHP), backend/frontend
  (Presentation Adianti), infra (`menu.xml`/RBAC nativo), shared (testes).
- Projeto/base: `/var/www/html/centralvet` (Adianti 8.6, PHP 8.4, MySQL 8,
  Redis, Docker Compose, sem Git).
- Integrações: nenhuma externa nova. `dompdf`/`pdfdesigner` já disponíveis
  via `composer.json` para o PDF da prescrição.

## Exploração read-only
- Caminhos relevantes: `src/app/Core/Application/EncounterService.php`,
  `AppointmentService.php` (Fase 1/2 — padrão de `AuthorizationPolicyInterface`
  integrado desde o construtor, `$action` passado pelo chamador); `src/app/Core/Storage/`
  (Fase 0 — padrão de anexo a reaproveitar no resultado de exame);
  `src/app/control/clinic/EncounterView.php` (onde as 3 ações inline hoje só
  gravam `audit_log`); `src/app/control/clinic/ServiceForm.php`/`ServiceList.php`
  (Fase 1 — padrão de tela de catálogo simples a replicar para
  exame/vacina); `src/composer.json` (dompdf/pdfdesigner já instalados).
- Padrões identificados: toda entidade nova segue Domain/Persistence/
  Application (Core) + Form/List Adianti (Presentation); `AbstractTenantRepository`
  para isolamento por tenant; `RbacAuthorizationService` para isolamento por
  unidade + auditoria.
- Scripts úteis: `docker compose exec app php tests/run.php` (suíte real,
  130 testes ao fim da Fase 2 + follow-ups); `docker compose build app worker`
  após qualquer mudança em `src/` (containers não usam bind-mount).
- Riscos identificados: `EncounterView.php` não pode ser editado por mais de
  uma task ao mesmo tempo (arquivo grande e já existente) — só T-09 o toca,
  numa onda isolada; `menu.xml` só é tocado por T-10, no fim.

## Mapa de arquivos

| Arquivo | Responsabilidade | Ação | Tasks que tocam |
|---|---|---|---|
| `src/app/database/migrations/20260922_0004_phase3_prescription_exam_vaccine.sql` | Schema das 8 tabelas | criar | T-01 |
| `src/app/database/migrations/20260922_0004_phase3_prescription_exam_vaccine.verify.sql` | Verificação somente leitura | criar | T-01 |
| `src/app/Core/Domain/Contract/PrescriptionRepositoryInterface.php` | Contrato de Prescrição | criar | T-02 |
| `src/app/Core/Domain/Contract/ExamCatalogRepositoryInterface.php` | Contrato de catálogo de exame | criar | T-02 |
| `src/app/Core/Domain/Contract/ExamRequestRepositoryInterface.php` | Contrato de solicitação de exame | criar | T-02 |
| `src/app/Core/Domain/Contract/ExamResultRepositoryInterface.php` | Contrato de resultado de exame | criar | T-02 |
| `src/app/Core/Domain/Contract/VaccineCatalogRepositoryInterface.php` | Contrato de catálogo de vacina | criar | T-02 |
| `src/app/Core/Domain/Contract/VaccineProtocolRepositoryInterface.php` | Contrato de protocolo de vacina | criar | T-02 |
| `src/app/Core/Domain/Contract/VaccinationRepositoryInterface.php` | Contrato de aplicação de vacina | criar | T-02 |
| `src/app/Core/Domain/Prescription.php` | Entidade de domínio Prescrição | criar | T-03 |
| `src/app/Core/Domain/PrescriptionItem.php` | Entidade de domínio Item de prescrição | criar | T-03 |
| `src/app/Core/Persistence/PrescriptionRepository.php` | Persistência de Prescrição | criar | T-03 |
| `src/app/Core/Application/PrescriptionService.php` | Casos de uso de Prescrição | criar | T-03 |
| `src/app/Core/Domain/ExamCatalogItem.php` | Entidade de domínio catálogo de exame | criar | T-04 |
| `src/app/Core/Domain/ExamRequest.php` | Entidade de domínio solicitação de exame | criar | T-04 |
| `src/app/Core/Domain/ExamResult.php` | Entidade de domínio resultado de exame | criar | T-04 |
| `src/app/Core/Persistence/ExamCatalogRepository.php` | Persistência do catálogo de exame | criar | T-04 |
| `src/app/Core/Persistence/ExamRequestRepository.php` | Persistência de solicitação de exame | criar | T-04 |
| `src/app/Core/Persistence/ExamResultRepository.php` | Persistência de resultado de exame | criar | T-04 |
| `src/app/Core/Application/ExamCatalogService.php` | Casos de uso do catálogo de exame | criar | T-04 |
| `src/app/Core/Application/ExamService.php` | Casos de uso de solicitação/resultado de exame | criar | T-04 |
| `src/app/Core/Domain/VaccineCatalogItem.php` | Entidade de domínio catálogo de vacina | criar | T-05 |
| `src/app/Core/Domain/VaccineProtocol.php` | Entidade de domínio protocolo de vacina | criar | T-05 |
| `src/app/Core/Domain/Vaccination.php` | Entidade de domínio aplicação de vacina | criar | T-05 |
| `src/app/Core/Persistence/VaccineCatalogRepository.php` | Persistência do catálogo de vacina | criar | T-05 |
| `src/app/Core/Persistence/VaccineProtocolRepository.php` | Persistência de protocolo de vacina | criar | T-05 |
| `src/app/Core/Persistence/VaccinationRepository.php` | Persistência de aplicação de vacina | criar | T-05 |
| `src/app/Core/Application/VaccineCatalogService.php` | Casos de uso do catálogo de vacina | criar | T-05 |
| `src/app/Core/Application/VaccinationService.php` | Casos de uso de aplicação de vacina | criar | T-05 |
| `src/app/control/clinic/PrescriptionForm.php` | Tela de Prescrição | criar | T-06 |
| `src/app/model/clinic/Prescription.php` | Model Adianti de Prescrição | criar | T-06 |
| `src/app/control/clinic/ExamCatalogForm.php` | Tela de catálogo de exame | criar | T-07 |
| `src/app/control/clinic/ExamCatalogList.php` | Tela de catálogo de exame | criar | T-07 |
| `src/app/control/clinic/ExamRequestForm.php` | Tela de solicitação de exame | criar | T-07 |
| `src/app/control/clinic/ExamResultForm.php` | Tela de registro de resultado de exame | criar | T-07 |
| `src/app/model/clinic/ExamCatalogItem.php` | Model Adianti de catálogo de exame | criar | T-07 |
| `src/app/control/clinic/VaccineCatalogForm.php` | Tela de catálogo de vacina | criar | T-08 |
| `src/app/control/clinic/VaccineCatalogList.php` | Tela de catálogo de vacina | criar | T-08 |
| `src/app/control/clinic/VaccineProtocolForm.php` | Tela de protocolo de vacina | criar | T-08 |
| `src/app/control/clinic/VaccinationForm.php` | Tela de aplicação de vacina | criar | T-08 |
| `src/app/control/clinic/VaccinationCardView.php` | Carteira de vacinação | criar | T-08 |
| `src/app/model/clinic/VaccineCatalogItem.php` | Model Adianti de catálogo de vacina | criar | T-08 |
| `src/app/control/clinic/EncounterView.php` | Linkar ações inline às telas novas | modificar | T-09 |
| `src/menu.xml` | Registro das telas novas no menu | modificar | T-10 |
| `src/tests/Unit/PrescriptionServiceTest.php` | Teste unitário de Prescrição | criar | T-11 |
| `src/tests/Unit/ExamServiceTest.php` | Teste unitário de Exame | criar | T-11 |
| `src/tests/Unit/VaccinationServiceTest.php` | Teste unitário de Vacinação | criar | T-11 |
| `.tasks/05-fase-3-prescricao-exames-vacinas/notes.md` | Parecer final da fase | modificar | T-12 |

## Decisões de arquitetura

| Decisão | Alternativas consideradas | Motivo da escolha |
|---|---|---|
| Telas dedicadas para prescrição/exame/vacina, não embutidas no `EncounterView` | Implementar tudo dentro de `EncounterView.php` | Evita colisão de 3 tasks paralelas no mesmo arquivo grande; segue o mesmo padrão de tela própria já usado por Tutor/Paciente/Serviço |
| `exam_catalog_item`/`vaccine_catalog_item` próprios, não reaproveitando `service` | Modelar exame/vacina como registros de `service` com categoria | PRD nomeia entidades de dados distintas (seção 13); exame precisa de parceiro, vacina precisa de fabricante/lote/validade — atributos que não cabem no `service` genérico |
| Baixa de estoque como contador simples em `vaccine_catalog_item` | Módulo completo de estoque nesta fase | Fase 4 é a fase formal de estoque; PRD já antecipa a baixa pontual em 8.13 sem exigir o módulo completo |
| `medication_name` como texto livre, sem catálogo de medicamentos | Criar um catálogo de medicamentos com busca | Fora do escopo literal desta fase; evita inventar uma base farmacológica não pedida |
| PDF simples sem assinatura digital | Assinatura digital real | Sem infraestrutura de certificado no projeto; simular assinatura seria enganoso |

## Diagrama de dependências

```text
T-01 → T-02 → T-03 → T-06
              T-04 → T-07
              T-05 → T-08
T-06,T-07,T-08 → T-09 → T-10
T-03,T-04,T-05 → T-11
T-10,T-11 → T-12
```

## Estratégia de execução
- Branch de trabalho: não aplicável — projeto não é repositório Git.
- Isolamento em ondas com edições paralelas: escritor único por arquivo em
  todas as ondas (T-03/T-04/T-05 e T-06/T-07/T-08 criam arquivos próprios,
  sem sobreposição); `EncounterView.php` (T-09) e `menu.xml` (T-10) são
  cada um tocado por uma única task, em ondas isoladas.

## Ondas de execução

### Onda 1
- T-01

### Onda 2
- T-02

### Onda 3
- T-03, T-04, T-05

### Onda 4
- T-06, T-07, T-08

### Onda 5
- T-09

### Onda 6
- T-10, T-11

### Onda 7
- T-12

## Agentes

| Agente | subagent_type | model | Tasks |
|---|---|---|---|
| Sun Tzu — orquestrador | — | — | todas |
| Athena | general-purpose | herdado | T-01, T-02, T-03 |
| Jaspion | general-purpose | herdado | T-04, T-05 |
| Aang | general-purpose | herdado | T-06, T-09 |
| Tesla | general-purpose | herdado | T-07, T-08 |
| Naruto | general-purpose | herdado | T-10 |
| Levi | general-purpose | herdado | T-11, T-12 |

## Critérios gerais de aceite
- `docker compose exec app php tests/run.php` permanece com 0 falhas após
  cada onda que toque Core/testes.
- Nenhuma DDL/DML aplicada sem autorização SQL explícita apresentada com
  efeito, risco e backup, como sempre (inclui a migration de T-01 e os
  `INSERT`s de `system_program`/`system_group_program` de T-10).
- Um usuário do grupo "Template - Admin" consegue abrir cada tela nova pela
  aplicação real depois de T-10.
- Nenhum dado de outro tenant nem de outra unidade visível ou editável em
  nenhuma das telas novas.
