# Tasks

| ID | Camada | Descrição | Dependências | Paralelizável | Complexidade | Agente | Status |
|---|---|---|---|---|---|---|---|
| T-01 | frontend | Fundação: cor de sidebar, logo e tipografia em custom.css/layout.html | — | não | média | Athena | [x] |
| T-02 | frontend | Grupo A/C — telas de Recepção (10 arquivos) | T-01 | sim | média | Aang | [x] |
| T-03 | frontend | Grupo A/C — telas de Prontuário clínico (10 arquivos) | T-01 | sim | média | Tesla | [x] |
| T-04 | frontend | Grupo A/C — telas de Estoque (6 arquivos) | T-01 | sim | média | Jaspion | [x] |
| T-05 | frontend | Grupo A/C — telas Financeiras (6 arquivos) | T-01 | sim | média | Jaspion | [x] |
| T-06 | frontend | Grupo B — reestruturação visual do EncounterView | T-01 | não | alta | Athena | [x] |
| T-07 | frontend | Grupo B — reestruturação visual da conta do atendimento | T-01 | sim | alta | Tesla | [x] |
| T-08 | frontend | Grupo B — reestruturação visual do PDV | T-01 | sim | alta | Aang | [x] |
| T-09 | frontend | Grupo B — reestruturação visual do pagamento | T-01 | sim | alta | Tesla | [x] |
| T-10 | frontend | Grupo B — reestruturação visual da execução de procedimento | T-01 | sim | alta | Jaspion | [x] |
| T-11 | shared | Validação visual por amostragem e regressão funcional do EncounterView | T-06,T-07,T-08,T-09,T-10 | não | alta | Levi | [x] |
| T-12 | shared | Revisão final de consistência visual | T-11 | não | média | Levi | [x] |

## Detalhamento

### T-01 — Fundação: cor de sidebar, logo e tipografia em custom.css/layout.html

**Camada:** frontend
**Dependências:** nenhuma
**Paralelizável:** não
**Complexidade:** média
**Agente:** Athena

**Arquivos prováveis**
- `src/app/templates/adminbs5/custom.css`
- `src/app/templates/adminbs5/layout.html`

**Interface**
- Produz: regra CSS de fundo da sidebar (seletor real do template, ex. `.sidebar`/`.app-menu`, a confirmar lendo `layout.html`) usando um tom escuro derivado de `--cv-color-primary` (ex. `#0f2a24` ou equivalente, mais escuro que `#176b5b`, não o cinza/preto padrão do Bootstrap), coerente com a sidebar escura já usada nas 4 imagens de referência do usuário
- Produz: substituição do `<img src="favicon.png">` do topo da sidebar em `layout.html` por um wordmark "CENTRAL VET PRO" com ícone de pata (SVG inline, mesmo padrão de ícone inline já usado nos mocks — sem depender de upload de arquivo de imagem novo)
- Produz: `font-family` do body herdando `'Source Sans Pro', system-ui, sans-serif` (mesma fonte dos mocks) em `custom.css`, com fallback de sistema se a fonte não estiver instalada localmente (sem adicionar dependência de rede/CDN nova — usar só o fallback de sistema se a fonte não estiver em `src/lib/`)
- Consome: nada

**Critério de aceite**
- `grep` em `custom.css` mostra uma regra de fundo escuro para o seletor real da sidebar, com valor de cor diferente do padrão Bootstrap/adminbs5 original.
- `layout.html` não contém mais `<img src="favicon.png">` na área da marca — contém um `<svg>` inline ou wordmark de texto com "CENTRAL VET PRO".
- Login carregado via Playwright mostra a sidebar com o novo fundo e a nova marca (evidência de screenshot).

**Validação**
- `layout.html` mistura HTML com marcadores de template não-XML, então `DOMDocument` não se aplica — validar com `grep -c "favicon.png" src/app/templates/adminbs5/layout.html` (evidência: `0`)
- Screenshot via Playwright da tela de login pós-rebuild (evidência: sidebar com fundo escuro derivado da paleta e marca nova visíveis)

---

### T-02 — Grupo A/C — telas de Recepção (10 arquivos)

**Camada:** frontend
**Dependências:** T-01
**Paralelizável:** sim
**Complexidade:** média
**Agente:** Aang

**Arquivos prováveis**
- `src/app/control/clinic/TutorForm.php`
- `src/app/control/clinic/TutorList.php`
- `src/app/control/clinic/PatientForm.php`
- `src/app/control/clinic/PatientList.php`
- `src/app/control/clinic/ServiceForm.php`
- `src/app/control/clinic/ServiceList.php`
- `src/app/control/clinic/AgendaView.php`
- `src/app/control/clinic/AppointmentForm.php`
- `src/app/control/clinic/QueueEntryView.php`
- `src/app/control/clinic/GlobalSearchController.php`

**Interface**
- Produz: cabeçalho de cada tela usando a estrutura `.cv-page-header`/`.cv-page-title` (mesmo HTML de `src/design-system.html`), com o texto do título vindo de `_t('...')` já existente (não inventar título novo)
- Produz: estado vazio de listagem (`TutorList`, `PatientList`, `ServiceList`) usando `.cv-state--empty` em vez do texto padrão do Adianti, se a tela expõe esse ponto de customização (`TDataGrid`/`BootstrapDatagridWrapper` aceitam mensagem de vazio customizável — confirmar lendo a API antes de assumir)
- Consome: nada (só aplica classes CSS já definidas em T-01/`custom.css`, sem mudar nenhum Application service)

**Critério de aceite**
- `grep -l "cv-page-header" <arquivo>` retorna as 10 telas.
- Nenhuma chamada a `Core/Application`/`Core/Persistence`/`Core/Domain` foi alterada em nenhum dos 10 arquivos (diff restrito a HTML/CSS/estrutura de tela).

**Validação**
- `docker compose exec app php -l <cada arquivo>` (evidência: `No syntax errors detected`, repetido para os 10)
- `grep -c "cv-page-header" <cada arquivo>` (evidência: >=1 em cada um)

---

### T-03 — Grupo A/C — telas de Prontuário clínico (10 arquivos)

**Camada:** frontend
**Dependências:** T-01
**Paralelizável:** sim
**Complexidade:** média
**Agente:** Tesla

**Arquivos prováveis**
- `src/app/control/clinic/PrescriptionForm.php`
- `src/app/control/clinic/ExamCatalogForm.php`
- `src/app/control/clinic/ExamCatalogList.php`
- `src/app/control/clinic/ExamRequestForm.php`
- `src/app/control/clinic/ExamResultForm.php`
- `src/app/control/clinic/VaccineCatalogForm.php`
- `src/app/control/clinic/VaccineCatalogList.php`
- `src/app/control/clinic/VaccineProtocolForm.php`
- `src/app/control/clinic/VaccinationForm.php`
- `src/app/control/clinic/VaccinationCardView.php`

**Interface**
- Produz: cabeçalho de cada tela usando `.cv-page-header`/`.cv-page-title`, mesmo padrão de T-02
- Produz: se, ao ler `PrescriptionForm.php`, o arquivo se revelar tão extenso/customizado quanto as telas do Grupo B (ex. já tem HTML de múltiplas colunas próprio, não só um formulário simples), aplicar só o cabeçalho `.cv-page-header` e registrar em `notes.md` que essa tela precisa de uma task de reestruturação completa equivalente ao Grupo B — não forçar um layout de 2 colunas com abas Nova/Histórico/Modelos nesta task
- Consome: nada

**Critério de aceite**
- `grep -l "cv-page-header" <arquivo>` retorna as 10 telas.
- Nenhuma chamada a `Core/Application`/`Core/Persistence`/`Core/Domain` foi alterada em nenhum dos 10 arquivos.

**Validação**
- `docker compose exec app php -l <cada arquivo>` (evidência: `No syntax errors detected`, repetido para os 10)
- `grep -c "cv-page-header" <cada arquivo>` (evidência: >=1 em cada um)

---

### T-04 — Grupo A/C — telas de Estoque (6 arquivos)

**Camada:** frontend
**Dependências:** T-01
**Paralelizável:** sim
**Complexidade:** média
**Agente:** Jaspion

**Arquivos prováveis**
- `src/app/control/clinic/ProductForm.php`
- `src/app/control/clinic/ProductList.php`
- `src/app/control/clinic/StockBatchForm.php`
- `src/app/control/clinic/ProcedureCatalogForm.php`
- `src/app/control/clinic/ProcedureCatalogList.php`
- `src/app/control/clinic/ProcedureInputForm.php`

**Interface**
- Produz: cabeçalho de cada tela usando `.cv-page-header`/`.cv-page-title`, mesmo padrão de T-02
- Consome: nada

**Critério de aceite**
- `grep -l "cv-page-header" <arquivo>` retorna as 6 telas.
- Nenhuma chamada a `Core/Application`/`Core/Persistence`/`Core/Domain` foi alterada em nenhum dos 6 arquivos.

**Validação**
- `docker compose exec app php -l <cada arquivo>` (evidência: `No syntax errors detected`, repetido para os 6)
- `grep -c "cv-page-header" <cada arquivo>` (evidência: >=1 em cada um)

---

### T-05 — Grupo A/C — telas Financeiras (6 arquivos)

**Camada:** frontend
**Dependências:** T-01
**Paralelizável:** sim
**Complexidade:** média
**Agente:** Jaspion

**Arquivos prováveis**
- `src/app/control/clinic/CashSessionForm.php`
- `src/app/control/clinic/CashSessionList.php`
- `src/app/control/clinic/PayableForm.php`
- `src/app/control/clinic/PayableList.php`
- `src/app/control/clinic/FinancialEntryForm.php`
- `src/app/control/clinic/FinancialEntryList.php`

**Interface**
- Produz: cabeçalho de cada tela usando `.cv-page-header`/`.cv-page-title`, mesmo padrão de T-02
- Consome: nada

**Critério de aceite**
- `grep -l "cv-page-header" <arquivo>` retorna as 6 telas.
- Nenhuma chamada a `Core/Application`/`Core/Persistence`/`Core/Domain` foi alterada em nenhum dos 6 arquivos.

**Validação**
- `docker compose exec app php -l <cada arquivo>` (evidência: `No syntax errors detected`, repetido para os 6)
- `grep -c "cv-page-header" <cada arquivo>` (evidência: >=1 em cada um)

---

### T-06 — Grupo B — reestruturação visual do EncounterView

**Camada:** frontend
**Dependências:** T-01
**Paralelizável:** não
**Complexidade:** alta
**Agente:** Athena

**Arquivos prováveis**
- `src/app/control/clinic/EncounterView.php`

**Interface**
- Produz: layout de 3 colunas batendo com a referência "Central de Atendimento" (linha do tempo/ficha rápida à esquerda, cards de anamnese/sinais vitais/exame físico/diagnóstico/plano clínico ao centro, ações inline + resumo financeiro à direita), usando `.cv-page`/tokens `--cv-*` no lugar dos estilos inline atuais
- Produz: preservação literal de TODOS os pontos de navegação existentes — os 5 `__adianti_goto_page` das ações inline (prescrição/exame/procedimento/vacina/conta do atendimento) continuam apontando para as mesmas classes com os mesmos parâmetros (`encounter_id`, `patient_id`), só o HTML/CSS ao redor muda
- Produz: preservação literal do mecanismo de autosave (`setInterval` + `__adianti_ajax_exec`) e da captura de voz (`enableSpeechRecognition`)
- Consome: nada (view pura sobre o mesmo `EncounterService`/`ProcedureExecutionService`/etc. já injetados)

**Critério de aceite**
- `grep -c "__adianti_goto_page" src/app/control/clinic/EncounterView.php` antes e depois da mudança retorna o mesmo número, com os mesmos 5 nomes de classe (`PrescriptionForm`, `ExamRequestForm`, `ProcedureExecutionForm`, `VaccinationForm`, `EncounterAccountForm`) presentes nos dois.
- `docker compose exec app php tests/run.php` → `Failed: 0` depois da mudança (nenhum teste de Fase 2-5 quebrou).

**Validação**
- `docker compose exec app php -l src/app/control/clinic/EncounterView.php` (evidência: `No syntax errors detected`)
- `grep -o "class=[A-Za-z]*Form&\|class=[A-Za-z]*View&" src/app/control/clinic/EncounterView.php | sort -u` (evidência: as mesmas 5 classes antes/depois)
- `docker compose exec app php tests/run.php` (evidência: `Failed: 0`)

---

### T-07 — Grupo B — reestruturação visual da conta do atendimento

**Camada:** frontend
**Dependências:** T-01
**Paralelizável:** sim
**Complexidade:** alta
**Agente:** Tesla

**Arquivos prováveis**
- `src/app/control/clinic/EncounterAccountForm.php`

**Interface**
- Produz: layout usando `.cv-page`/tokens `--cv-*` com painel de resumo financeiro destacado (itens da conta + total, mesmo padrão visual do "Resumo financeiro em tempo real" da referência), preservando literalmente as chamadas a `EncounterAccountService::openOrGet/syncAutomaticItems/addManualItem/applyDiscount/close` já existentes (mesmos nomes de `$action` por método, já que isso alimenta a permissão de desconto granular da Fase 5)
- Consome: nada (view pura sobre `EncounterAccountService` já injetado)

**Critério de aceite**
- `grep -c "EncounterAccountForm::on" src/app/control/clinic/EncounterAccountForm.php` antes e depois retorna o mesmo número, com os mesmos nomes de `$action` (`onLoad`, `onSave`, `onApplyDiscount`, `onClose`).
- `docker compose exec app php tests/run.php` → `Failed: 0` depois da mudança.

**Validação**
- `docker compose exec app php -l src/app/control/clinic/EncounterAccountForm.php` (evidência: `No syntax errors detected`)
- `docker compose exec app php tests/run.php` (evidência: `Failed: 0`)

---

### T-08 — Grupo B — reestruturação visual do PDV

**Camada:** frontend
**Dependências:** T-01
**Paralelizável:** sim
**Complexidade:** alta
**Agente:** Aang

**Arquivos prováveis**
- `src/app/control/clinic/SaleForm.php`

**Interface**
- Produz: layout usando `.cv-page`/tokens `--cv-*` para o carrinho (`TDataGrid` de itens) e resumo de total, preservando literalmente a chamada a `SaleService::create()` e a geração de PDF do recibo já existentes
- Consome: nada (view pura sobre `SaleService`/`ProductService`/`ProcedureCatalogService` já injetados)

**Critério de aceite**
- `grep -c "SaleService::create\|buildSaleService" src/app/control/clinic/SaleForm.php` antes e depois retorna o mesmo número.
- `docker compose exec app php tests/run.php` → `Failed: 0` depois da mudança.

**Validação**
- `docker compose exec app php -l src/app/control/clinic/SaleForm.php` (evidência: `No syntax errors detected`)
- `docker compose exec app php tests/run.php` (evidência: `Failed: 0`)

---

### T-09 — Grupo B — reestruturação visual do pagamento

**Camada:** frontend
**Dependências:** T-01
**Paralelizável:** sim
**Complexidade:** alta
**Agente:** Tesla

**Arquivos prováveis**
- `src/app/control/clinic/PaymentForm.php`

**Interface**
- Produz: layout usando `.cv-page`/tokens `--cv-*` para o saldo devido e formulário de pagamento, preservando literalmente a checagem de sessão de caixa aberta (`CashSessionRepositoryInterface::findOpenBySystemUnit`) e a chamada a `PaymentService::register()` já existentes
- Consome: nada (view pura sobre `PaymentService` já injetado)

**Critério de aceite**
- `grep -c "PaymentService::register\|findOpenBySystemUnit" src/app/control/clinic/PaymentForm.php` antes e depois retorna o mesmo número.
- `docker compose exec app php tests/run.php` → `Failed: 0` depois da mudança.

**Validação**
- `docker compose exec app php -l src/app/control/clinic/PaymentForm.php` (evidência: `No syntax errors detected`)
- `docker compose exec app php tests/run.php` (evidência: `Failed: 0`)

---

### T-10 — Grupo B — reestruturação visual da execução de procedimento

**Camada:** frontend
**Dependências:** T-01
**Paralelizável:** sim
**Complexidade:** alta
**Agente:** Jaspion

**Arquivos prováveis**
- `src/app/control/clinic/ProcedureExecutionForm.php`

**Interface**
- Produz: layout usando `.cv-page`/tokens `--cv-*` para o formulário de execução, preservando literalmente a chamada a `ProcedureExecutionService::execute()` já existente, incluindo o parâmetro `$action`
- Consome: nada (view pura sobre `ProcedureExecutionService`/`ProcedureCatalogService` já injetados)

**Critério de aceite**
- `grep -c "ProcedureExecutionService::execute\|->execute(" src/app/control/clinic/ProcedureExecutionForm.php` antes e depois retorna o mesmo número.
- `docker compose exec app php tests/run.php` → `Failed: 0` depois da mudança.

**Validação**
- `docker compose exec app php -l src/app/control/clinic/ProcedureExecutionForm.php` (evidência: `No syntax errors detected`)
- `docker compose exec app php tests/run.php` (evidência: `Failed: 0`)

---

### T-11 — Validação visual por amostragem e regressão funcional do EncounterView

**Camada:** shared
**Dependências:** T-06,T-07,T-08,T-09,T-10
**Paralelizável:** não
**Complexidade:** alta
**Agente:** Levi

**Arquivos prováveis**
- `.tasks/08-design-system-consistencia-visual/notes.md`

**Interface**
- Produz: parecer registrado em `notes.md` com screenshot (via Playwright, login real com usuário `admin`) de pelo menos uma tela de cada grupo (A/C: uma do T-02, uma do T-03, uma do T-04, uma do T-05; B: as 5 do T-06 a T-10), confirmando visualmente a cor de fundo/marca da sidebar e os cabeçalhos `.cv-page-header`
- Produz: regressão funcional do `EncounterView`: login real, abrir um atendimento existente (ou criar um novo com dados de teste), clicar nas 5 ações inline (prescrição/exame/procedimento/vacina/conta do atendimento) e confirmar que cada uma navega pra tela certa sem erro fatal
- Consome: nada formalmente (valida as evidências já reportadas por T-01 a T-10)

**Critério de aceite**
- Todas as telas amostradas mostram a sidebar nova (cor/marca) e cabeçalho `.cv-page-header` onde aplicável.
- As 5 ações inline do `EncounterView` (prescrição/exame/procedimento/vacina/conta do atendimento) abrem cada uma sua tela de destino (`PrescriptionForm`/`ExamRequestForm`/`ProcedureExecutionForm`/`VaccinationForm`/`EncounterAccountForm`) com a URL final contendo o `encounter_id` correto, confirmado por captura de tela ou pela URL da página após o clique.
- `docker compose exec app php tests/run.php` → `Failed: 0`.

**Validação**
- `docker compose exec app php tests/run.php` (evidência: `Failed: 0`)
- Screenshots anexados/descritos em `notes.md` para cada tela amostrada

---

### T-12 — Revisão final de consistência visual

**Camada:** shared
**Dependências:** T-11
**Paralelizável:** não
**Complexidade:** média
**Agente:** Levi

**Arquivos prováveis**
- `.tasks/08-design-system-consistencia-visual/notes.md`

**Interface**
- Produz: parecer final registrado em `notes.md` cobrindo se alguma tela do Grupo C se revelou mais complexa que o esperado (pendência sinalizada por T-03/T-02/T-04/T-05, conforme premissa do plan.md) e se isso justifica uma task de reestruturação futura equivalente ao Grupo B
- Consome: nada formalmente

**Critério de aceite**
- Nenhum bloqueante em aberto; toda pendência conhecida (inclusive telas do Grupo C que precisem de reestruturação futura) é listada explicitamente em `notes.md`.

**Validação**
- `docker compose exec app php tests/run.php` (evidência: `Failed: 0`)

## Legenda

- [ ] pendente
- [~] em andamento
- [x] concluído com evidência
- [!] bloqueado
