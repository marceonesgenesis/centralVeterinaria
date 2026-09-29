# Plano: Consistência visual — design system aplicado

## Objetivo
Fazer as ~37 telas construídas nas Fases 1-5 usarem de fato o design system que já existe em `custom.css` (tokens `--cv-*`, paleta verde-petróleo, classes `.cv-page`/`.cv-state`) e a marca visual das telas de referência aprovadas pelo usuário, em vez do tema padrão do Adianti sem nenhuma customização. Não adiciona funcionalidade nova — é consistência do que já foi construído.

## Premissas
- **Fundação já existe, não será recriada**: `src/app/templates/adminbs5/custom.css` já define a paleta (`--cv-color-primary: #176b5b` e variantes), tipografia base, foco de acessibilidade e classes utilitárias (`.cv-page`, `.cv-page-header`, `.cv-page-title`, `.cv-section`, `.cv-state--loading/empty/error/success/denied`, `.cv-table-scroll`) documentadas em `src/design-system.html`. Confirmado funcionando (botões primários já saem verde-petróleo onde a classe Bootstrap `.btn-primary` é usada). Esta fase aplica esses tokens/classes de forma consistente, não inventa um sistema novo.
- **`layout.html` é um arquivo único** (`src/app/templates/adminbs5/layout.html`) que gera sidebar/topbar/logo via find/replace de marcadores de template (menu, título da página, nome/e-mail/unidade do usuário) — não é gerado por classe PHP nem por tela. Trocar logo/marca e cor de fundo da sidebar é edição centralizada nesse arquivo, não em cada uma das 37 telas.
- **3 grupos de telas por custo de retrabalho** (levantado por exploração técnica prévia, registrada em `notes.md`):
  - **Grupo A (18 telas `TStandardForm`/`TStandardList` + 2 `TPage` equivalentes — `PatientList`, `TutorList`)**: geradas quase 100% por componentes nativos do Adianti: herdam CSS global automaticamente, custo baixo — o trabalho aqui é garantir que usam `.cv-page-header`/`.cv-state` de forma consistente, não é reescrita.
  - **Grupo B (5 telas `TPage` com HTML/layout próprio extenso, confirmado por contagem de linhas)**: `EncounterView.php` (~1300 linhas), `EncounterAccountForm.php` (~709 linhas), `SaleForm.php` (~803 linhas), `PaymentForm.php` (~458 linhas), `ProcedureExecutionForm.php` (~347 linhas). Essas precisam de reestruturação individual pra bater com as telas de referência.
  - **Grupo C (demais 12 telas `TPage`: `PrescriptionForm`, `ExamRequestForm`, `ExamResultForm`, `VaccinationForm`, `VaccineProtocolForm`, `ProcedureInputForm`, `AppointmentForm`, `QueueEntryView`, `AgendaView`, `GlobalSearchController`, `VaccinationCardView`, `ExamCatalogForm`-like)**: não tiveram o tamanho/complexidade confirmado individualmente pela exploração prévia. Recebem o mesmo tratamento do Grupo A nesta fase (aplicar classes utilitárias); se alguma se revelar, na prática, tão rica quanto o Grupo B (ex.: `PrescriptionForm`, cuja referência do usuário mostra layout de 2 colunas com abas Nova/Histórico/Modelos), a task correspondente documenta isso como pendência em `notes.md` em vez de forçar um resultado raso — não é reescrita garantida, é best-effort com o mesmo padrão do Grupo A, sinalizando se não for suficiente.
- **Fora de escopo desta fase (não é reskin, é funcionalidade nova)**: dashboards com cards de estatística numérica (ex. "248 produtos em estoque"), badges "Gerado por IA"/"Organizar com IA"/"Sugerir diagnósticos (IA)", fluxo de atendimento como wizard de 5 passos (Anamnese/Exame físico/Diagnóstico/Plano clínico/Finalização) substituindo a tela única atual. Essas features aparecem nas imagens de referência mais recentes do usuário mas não existem em NENHUMA tela hoje e não foram pedidas em nenhuma fase anterior — decisão de escopo documentada, não esquecimento. Ficam como candidatas a uma fase futura dedicada (ligada, inclusive, à Fase 8A do PRD, "AI Gateway e IA contextual", já que os badges de IA pressupõem funcionalidade de IA que ainda não existe no backend).
- **`EncounterView.php` é o maior risco desta fase**: já tem lógica de negócio testada e conectada nas Fases 2-5 (ações inline de prescrição/exame/procedimento/vacina/conta do atendimento, autosave, resumo de IA-placeholder, timeline). A task que mexe nela é isolada em onda própria, escritor único, com regressão funcional completa (suíte de 153 testes + clique manual nas 5 ações inline via Playwright) depois da mudança estrutural — não só visual.
- Nenhuma migration, nenhuma mudança de schema, nenhuma mudança de Application service nesta fase — é só `app/templates/`, `app/control/clinic/*.php` (HTML/CSS inline) e, onde necessário, `app/model/clinic/*.php` (rótulos de campo).
- Projeto não é repositório Git.

## Escopo

### Incluso
- Cor de fundo da sidebar, logo/marca e tipografia em `layout.html`/`custom.css` batendo com as telas de referência aprovadas.
- `.cv-page-header`/`.cv-page-title`/`.cv-state` aplicados de forma consistente nas 32 telas do Grupo A e Grupo C.
- Reestruturação visual das 5 telas do Grupo B pra bater com o layout específico de cada referência (conta do atendimento, PDV, pagamento, execução de procedimento, central de atendimento).
- Validação visual por amostragem (Playwright) + regressão funcional completa do EncounterView.

### Excluído
- Dashboards com cards de estatística numérica.
- Badges/fluxos de IA (aceitar/editar resumo, sugestão de diagnóstico, ditado organizado por IA).
- Conversão do EncounterView de tela única para wizard de 5 passos.
- Qualquer mudança de Application service, migration ou schema.
- Módulo de relatórios (PRD 8.24).

## Contexto técnico
- Camadas envolvidas: frontend (templates Adianti, CSS, HTML inline em `TPage`).
- Projeto/base analisada: `/var/www/html/centralvet` (Adianti Framework 8.6, Docker Compose, acesso real testado via Playwright em `http://127.0.0.1:8081`).
- Integrações: nenhuma nova.

## Exploração read-only
- Caminhos relevantes: `src/app/templates/adminbs5/custom.css` (tokens prontos), `src/app/templates/adminbs5/layout.html`/`login.html`/`layout-basic.html`/`public.html`/`iframe.html` (todos já linkam `custom.css`, corrigido nesta sessão), `src/design-system.html` (referência viva dos componentes/estados), `src/app/control/clinic/*.php` (37 telas).
- Padrões identificados: `TStandardForm`/`TStandardList` renderizam via componentes nativos (`TPanelGroup`, `BootstrapFormBuilder`, `BootstrapDatagridWrapper`) — pouco HTML próprio; telas `TPage` custom escrevem `<div style="...">` inline diretamente, sem reaproveitar `.cv-*`.
- Scripts úteis: `docker compose exec app php tests/run.php` (153 testes atuais, cobre só lógica de negócio — nenhuma asserção visual); Playwright MCP (`mcp__plugin_playwright_playwright__*`) para verificação visual real, login com usuário `admin` (credencial em `.env`, `CENTRALVET_ADMIN_PASSWORD`).
- Riscos identificados: `EncounterView.php` acumula lógica de 3 fases diferentes (T-11 de cada uma delas religou uma ação inline) — qualquer refatoração estrutural precisa preservar os pontos de navegação exatos (`__adianti_goto_page` com `encounter_id`/`patient_id`) e o mecanismo de autosave. `layout.html` sendo um arquivo central significa que T-01 bloqueia visualmente a verificação de todas as outras tasks — deve ser a primeira a fechar.

## Mapa de arquivos

| Arquivo | Responsabilidade | Ação | Tasks que tocam |
|---|---|---|---|
| `src/app/templates/adminbs5/custom.css` | Cor de fundo da sidebar, tipografia, logo | modificar | T-01 |
| `src/app/templates/adminbs5/layout.html` | Logo/marca da sidebar | modificar | T-01 |
| `src/app/control/clinic/TutorForm.php` | Header/estado consistente | modificar | T-02 |
| `src/app/control/clinic/TutorList.php` | Header/estado consistente | modificar | T-02 |
| `src/app/control/clinic/PatientForm.php` | Header/estado consistente | modificar | T-02 |
| `src/app/control/clinic/PatientList.php` | Header/estado consistente | modificar | T-02 |
| `src/app/control/clinic/ServiceForm.php` | Header/estado consistente | modificar | T-02 |
| `src/app/control/clinic/ServiceList.php` | Header/estado consistente | modificar | T-02 |
| `src/app/control/clinic/AgendaView.php` | Header/estado consistente | modificar | T-02 |
| `src/app/control/clinic/AppointmentForm.php` | Header/estado consistente | modificar | T-02 |
| `src/app/control/clinic/QueueEntryView.php` | Header/estado consistente | modificar | T-02 |
| `src/app/control/clinic/GlobalSearchController.php` | Header/estado consistente | modificar | T-02 |
| `src/app/control/clinic/PrescriptionForm.php` | Header/estado consistente | modificar | T-03 |
| `src/app/control/clinic/ExamCatalogForm.php` | Header/estado consistente | modificar | T-03 |
| `src/app/control/clinic/ExamCatalogList.php` | Header/estado consistente | modificar | T-03 |
| `src/app/control/clinic/ExamRequestForm.php` | Header/estado consistente | modificar | T-03 |
| `src/app/control/clinic/ExamResultForm.php` | Header/estado consistente | modificar | T-03 |
| `src/app/control/clinic/VaccineCatalogForm.php` | Header/estado consistente | modificar | T-03 |
| `src/app/control/clinic/VaccineCatalogList.php` | Header/estado consistente | modificar | T-03 |
| `src/app/control/clinic/VaccineProtocolForm.php` | Header/estado consistente | modificar | T-03 |
| `src/app/control/clinic/VaccinationForm.php` | Header/estado consistente | modificar | T-03 |
| `src/app/control/clinic/VaccinationCardView.php` | Header/estado consistente | modificar | T-03 |
| `src/app/control/clinic/ProductForm.php` | Header/estado consistente | modificar | T-04 |
| `src/app/control/clinic/ProductList.php` | Header/estado consistente | modificar | T-04 |
| `src/app/control/clinic/StockBatchForm.php` | Header/estado consistente | modificar | T-04 |
| `src/app/control/clinic/ProcedureCatalogForm.php` | Header/estado consistente | modificar | T-04 |
| `src/app/control/clinic/ProcedureCatalogList.php` | Header/estado consistente | modificar | T-04 |
| `src/app/control/clinic/ProcedureInputForm.php` | Header/estado consistente | modificar | T-04 |
| `src/app/control/clinic/CashSessionForm.php` | Header/estado consistente | modificar | T-05 |
| `src/app/control/clinic/CashSessionList.php` | Header/estado consistente | modificar | T-05 |
| `src/app/control/clinic/PayableForm.php` | Header/estado consistente | modificar | T-05 |
| `src/app/control/clinic/PayableList.php` | Header/estado consistente | modificar | T-05 |
| `src/app/control/clinic/FinancialEntryForm.php` | Header/estado consistente | modificar | T-05 |
| `src/app/control/clinic/FinancialEntryList.php` | Header/estado consistente | modificar | T-05 |
| `src/app/control/clinic/EncounterView.php` | Reestruturação visual (Grupo B) | modificar ⚠ | T-06 |
| `src/app/control/clinic/EncounterAccountForm.php` | Reestruturação visual (Grupo B) | modificar | T-07 |
| `src/app/control/clinic/SaleForm.php` | Reestruturação visual (Grupo B) | modificar | T-08 |
| `src/app/control/clinic/PaymentForm.php` | Reestruturação visual (Grupo B) | modificar | T-09 |
| `src/app/control/clinic/ProcedureExecutionForm.php` | Reestruturação visual (Grupo B) | modificar | T-10 |
| `.tasks/08-design-system-consistencia-visual/notes.md` | Evidências de validação e parecer final | modificar ⚠ | T-11, T-12 |

`EncounterView.php` (⚠) é a única tela cuja edição estrutural exige regressão funcional completa (T-06 sozinha na Onda 3), por concentrar lógica de negócio de 3 fases.

## Decisões de arquitetura

| Decisão | Alternativas consideradas | Motivo da escolha |
|---|---|---|
| Aplicar tokens/classes já existentes em `custom.css`, não criar um sistema novo | Escrever CSS novo do zero pra bater exatamente com as 4 imagens de referência | `custom.css` já foi desenhado com a mesma paleta das imagens (verificado: `#176b5b` em ambos); reaproveitar evita duas fontes de verdade de design divergentes |
| Excluir dashboards/badges de IA/wizard de atendimento desta fase | Incluir tudo que aparece nas imagens de referência | Essas peças não existem em nenhuma forma hoje — são funcionalidade nova, não inconsistência visual do que já foi construído; misturar os dois infla o escopo e mistura "corrigir" com "construir" |
| Grupo C recebe o mesmo tratamento do Grupo A, não uma reestruturação garantida | Tratar todas as 12 telas do Grupo C como potencialmente tão complexas quanto o Grupo B, adicionando 12 tasks de reestruturação completa | A exploração técnica não confirmou a complexidade real de cada uma; a decisão conservadora é aplicar o tratamento barato e deixar cada task sinalizar se não for suficiente, em vez de assumir um retrabalho grande sem evidência |

## Diagrama de dependências

```text
T-01 -> T-02
T-01 -> T-03
T-01 -> T-04
T-01 -> T-05
T-01 -> T-06 -> T-11 -> T-12
T-01 -> T-07 -> T-11
T-01 -> T-08 -> T-11
T-01 -> T-09 -> T-11
T-01 -> T-10 -> T-11
```

## Estratégia de execução
- Branch de trabalho: não aplicável — projeto não é repositório Git.
- Branch base: não aplicável.
- Commit por onda: não aplicável (sem Git); registro de progresso em `tasks.md`/`notes.md`.
- Isolamento em ondas com edições paralelas: escritor único por arquivo em toda onda; `EncounterView.php` isolado na Onda 3, sem nenhuma outra task rodando em paralelo com ela. `notes.md` (⚠) é tocado por T-11 (Onda 5) e T-12 (Onda 6) em ondas diferentes e sequenciais — sem colisão real, resolvido por ordem de execução, sem necessidade de worktree.

## Ondas de execução

### Onda 1
- T-01

### Onda 2
- T-02
- T-03
- T-04
- T-05

### Onda 3
- T-06

### Onda 4
- T-07
- T-08
- T-09
- T-10

### Onda 5
- T-11

### Onda 6
- T-12

## Agentes

| Agente | subagent_type | model | Tasks |
|---|---|---|---|
| Sun Tzu — orquestrador | — | — | todas |
| Athena | general-purpose | herdado | T-01, T-06 |
| Aang | general-purpose | herdado | T-02, T-08 |
| Tesla | general-purpose | herdado | T-03, T-07, T-09 |
| Jaspion | general-purpose | herdado | T-04, T-05, T-10 |
| Levi | general-purpose | herdado | T-11, T-12 |

## Critérios gerais de aceite
- `docker compose exec app php tests/run.php` termina em `Failed: 0` (153 testes, sem regressão — esta fase não adiciona teste novo, só não pode quebrar os existentes).
- Nenhuma tela usa cor azul Bootstrap padrão (`#0d6efd`) em botão primário — todas usam os tokens `--cv-*`.
- `EncounterView.php` continua navegando corretamente para as 5 ações inline (prescrição/exame/procedimento/vacina/conta do atendimento) depois da reestruturação.
- Nenhuma migration, Application service ou contrato de Domain foi alterado.
