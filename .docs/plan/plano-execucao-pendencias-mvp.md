# Plano de Decomposição e Execução em Paralelo — Pendências do MVP Central Vet Pro

Atualizado em 29/10/2026. Detalha a execução paralela das pendências mapeadas em [pendencias-mvp.md](./pendencias-mvp.md), incluindo a liberação dos novos módulos (2.7) e a implantação completa da Área de Gestão / Back-office (2.8).

---

## 1. Visão Geral da Estratégia de Paralelismo

Para acelerar a entrega sem conflitos de código ou bloqueios operacionais, o trabalho foi decomposto em **4 Trilhas de Trabalho Paralelas (Workstreams)**. As trilhas A, B, C e D possuem escopos desacoplados e podem ser executadas simultaneamente por diferentes desenvolvedores ou subagentes:

```
┌─────────────────────────────────────────────────────────────────────────────┐
│                       TRILHAS EXECUTÁVEIS EM PARALELO                       │
├─────────────────┬──────────────────┬──────────────────┬─────────────────────┤
│   TRILHA A      │    TRILHA B      │    TRILHA C      │     TRILHA D        │
│ DevOps, CI/CD   │ Módulos Clínicos │ Área de Gestão   │ Jurídico, Dívida &  │
│  & Ambientes    │   & IA no MVP    │  (Back-office)   │   Piloto em Campo   │
│  (Seções 2.1-4) │   (Seção 2.7)    │   (Seção 2.8)    │  (Seções 2.5, 2.6)  │
└────────┬────────┴────────┬─────────┴────────┬─────────┴──────────┬──────────┘
         │                 │                  │                    │
         └─────────────────┼──────────────────┼────────────────────┘
                           ▼                  ▼
                   HOMOLOGAÇÃO INTEGRADA & PILOTO EM CAMPO
```

---

## 2. Detalhamento das Trilhas e Tasks Menores

### Trilha A: DevOps, Integração e Ambientes
*Foco: Fechamento de branch, PRs, migrations, cron e configuração de homologação/produção.*

| Task ID | Descrição da Tarefa | Dependência | Critério de Aceite |
|---|---|---|---|
| **A-1** | Commitar fechamento da Etapa 5 na branch atual | Nenhuma | `task/etapa-5-piloto-lancamento` limpa e com commit registrado |
| **A-2** | Autenticação GitHub CLI e abertura do PR da Etapa 4 para `dev` | A-1 | PR aberto via `gh pr create` a partir da branch `merge/...` |
| **A-3** | Abertura do PR da Etapa 5 (acumulado 1–5) para `dev` e teste de CI | A-2 | Workflow do GitHub Actions verde com todos os testes passando |
| **A-4** | Aplicação da migration 0020 no banco dev e registro real de SHA-256 | A-3 | `migration-status.php` sem checksums `0000...` e migration aplicada |
| **A-5** | Reconstrução da imagem Docker do app e teste de fumaça | A-4 | `:8081` exibindo legal.php, diálogos de termos e telas novas |
| **A-6** | Configuração do Cron real (reconciliador 15m, backup, monitor, LGPD) | A-5 | `scripts/cron-run.sh` ativo no host/painel e validado |
| **A-7** | Configuração do bucket S3 compatível (R2/B2) para backup externo | A-6 | Backup criptografado enviado ao bucket e ensaio de restauração concluído |

---

### Trilha B: Módulos Clínicos & IA Integrados ao MVP
*Foco: Habilitar e homologar Internação, Cirurgia, Documentos, Comunicação e Copiloto de IA na oferta.*

| Task ID | Descrição da Tarefa | Dependência | Critério de Aceite |
|---|---|---|---|
| **B-1** | Atualizar `OfferScope` e flags de ambiente para liberar módulos | Nenhuma | Módulos deixam de responder `not_released` e menus exibem itens ativos |
| **B-2** | Atualizar `LandingCatalog` e matriz de planos (`PlanEntitlements`) | B-1 | Planos Pro/Business/Enterprise refletem novos módulos sem deploy futuro |
| **B-3** | Ajustar perfis de acesso das clínicas (`ClinicRoleProfiles` e `role-groups.sql`) | B-2 | Veterinário, Recepção e Admin com permissões corretas em internação e cirurgia |
| **B-4** | Homologação clínica de leitos, internação e prescrições | B-3 | Fluxo completo de internação testado e documentado |
| **B-5** | Homologação de cirurgia, checklist e consumo de materiais no PDV | B-3 | Cirurgias agendadas debitando estoque e gerando registros |
| **B-6** | Configuração de Quotas e Custo de IA por Tenant (AI Gateway) | B-2 | Chave de IA de produção configurada e trava de limite de tokens ativa |
| **B-7** | Extensão dos testes de jornada (`PilotJourneyIntegrationTest`) | B-4, B-5 | Suíte de testes automatizados cobrindo a jornada com internação e IA |

---

### Trilha C: Área de Gestão do Sistema (Back-office Central Vet)
*Foco: Gestão de clientes, planos, convites com 15 dias de bônus, marketing, equipe interna e auditoria.*

> [!NOTE]
> Os **mocks visuais** e o **login separado** já foram criados nesta etapa como fundação!

| Task ID | Descrição da Tarefa | Dependência | Critério de Aceite |
|---|---|---|---|
| **C-1** | **Mocks das Telas da Gestão** *(Concluído)* | — | Arquivo `src/gestao-mocks.html` e `.docs/mocks/gestao/README.md` criados |
| **C-2** | **Login Separado da Gestão** *(Concluído)* | — | `src/gestao.php`, `GestaoLoginForm.php` e `menu-gestao.xml` criados |
| **C-3** | Tela de Clientes & Licenças (`GestaoClientList`) | C-2 | Tabela de clínicas, ações de suspender, reativar, trocar plano e prorrogar |
| **C-4** | Gestão Dinâmica de Planos e Ofertas (`GestaoPlanList`) | C-2 | Cadastro de planos no banco, sincronizado com Stripe price IDs e quotas |
| **C-5** | Emissão de Convites com Bônus de 15 Dias (`GestaoInviteForm` / `List`) | C-2 | Convite por e-mail com trial de 15 dias sem pedir cartão; provisionamento |
| **C-6** | Painel de Marketing, Leads e Disparo de Campanhas (`GestaoMarketingView`) | C-2 | Agrupamento de leads/carrinhos abandonados, exportação CSV e e-mail com opt-out |
| **C-7** | Grade Editável de Horários do Suporte (`GestaoSupportHoursForm`) | C-2 | Horários salvos no banco e lidos dinamicamente pela tela de suporte das clínicas |
| **C-8** | Controle da Equipe Interna (4 Perfis) e 2FA Obrigatório | C-2 | Admin, Comercial, Financeiro e Suporte com controle de acesso estrito |
| **C-9** | Trilha de Auditoria com Diff de Alterações (`GestaoAuditLogList`) | C-3 a C-8 | Toda mutação grava log indelével com quem fez, quando, IP e diff antes/depois |

---

### Trilha D: Jurídico, Dívida Técnica e Operação do Piloto
*Foco: Revisão jurídica, LGPD, correções P1 e roteiro de go-live.*

| Task ID | Descrição da Tarefa | Dependência | Critério de Aceite |
|---|---|---|---|
| **D-1** | Revisão dos Termos de Uso e Política de Privacidade (IA, DPO, mensagens) | Nenhuma | Textos atualizados retirando marca de "Rascunho", cobrindo IA e consentimento |
| **D-2** | Correção de exportação LGPD para tabelas novas e tokens de IA | Nenhuma | Exportação do ZIP gerando dados completos sem vazar segredos |
| **D-3** | Correção de dívida técnica de billing ($wasVoid e corrida Pix) | Nenhuma | Testes unitários cobrindo cenários de reconciliação e cancelamento |
| **D-4** | Correção de acessibilidade no aceite de termos (touch target e for) | Nenhuma | Checkbox de aceite com tamanho mínimo de 44px e rótulo associado |
| **D-5** | Execução do roteiro de homologação com 2 clínicas piloto | A-4, B-3 | `piloto-homologacao.md` executado e aprovado |
| **D-6** | Go-live do piloto e acompanhamento de incidentes | Todas | Checklist `piloto-go-live.md` concluído com sucesso |

---

## 3. Matriz de Paralelismo e Atribuição

| Frente de Trabalho | Tasks Independentes | Bloqueios Externos |
|---|---|---|
| **DevOps / Infra** | A-1, A-2, A-3, A-4, A-5, A-6, A-7 | Depende de credenciais `gh` e Stripe live |
| **Core Clínico & IA** | B-1, B-2, B-3, B-4, B-5, B-6, B-7 | Nenhuma dependência com Trilha C (código isolado) |
| **Back-office / Gestão** | C-3, C-4, C-5, C-6, C-7, C-8, C-9 | Nenhuma dependência com Trilha B (telas da gestão em `gestao.php`) |
| **Jurídico & Compliance** | D-1, D-2, D-3, D-4 | Não bloqueia nem é bloqueada pelo desenvolvimento |

