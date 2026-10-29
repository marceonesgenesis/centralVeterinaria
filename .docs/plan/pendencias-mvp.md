# Pendências do MVP e backlog pós-MVP — Central Vet Pro

Atualizado em 29/10/2026. Consolida a [avaliação de prontidão do MVP](../audits/2026-10-09-prontidao-mvp.md), o [roadmap](./centralvet-roadmap.md) e as revisões finais das Etapas 3, 4 e 5 (`.claude/tasks/*/reviews/final.md`). As listas completas de sugestões continuam nas `notes.md § Pendências` de cada etapa; aqui ficam só os itens que pedem ação ou decisão.

Legenda de prioridade: **P0** bloqueia o piloto · **P1** antes de ampliar além do piloto · **P2** melhoria/dívida.

## 1. Onde estamos

| Etapa da auditoria | Entrega | Situação |
|---|---|---|
| 1 — Segurança | S1–S4, revisão S5/S6 | Concluída na branch `task/etapa-1-seguranca-mvp` |
| 2 — Dinheiro e estoque | F1–F3 (preço do PDV, concorrência, venda conciliada) | Concluída em `task/etapa-2-dinheiro-estoque` |
| 3 — Contratação | Landing com checkout Stripe (cartão e Pix), webhook, provisionamento, direitos por plano, estorno de `login_conflict` | Concluída em `task/etapa-3-contratacao` |
| 4 — Operação | Backup cifrado (Docker/VPS e hospedagem compartilhada), restauração comprovada, CI com banco isolado, release e rollback ensaiados, monitor e alertas, LGPD do payload | Concluída em `task/etapa-4-operacao` |
| 5 — Piloto e lançamento | Onboarding, 3 perfis restritos, termos e privacidade com aceite versionado, cancelamento pelo app, exportação LGPD, recorte da oferta, jornada por perfil, runbooks do piloto | Concluída em `task/etapa-5-piloto-lancamento` (23 tasks; revisão final rodada 2 aprovada com sugestões) |

As branches estão encadeadas (1 ⊂ 2 ⊂ 3 ⊂ 4 ⊂ 5) e **nenhuma foi integrada em `dev` nem em `main`**.

Recorte do piloto implementado na Etapa 5: núcleo clínico, exames, vacinação, financeiro, estoque/PDV e várias unidades; os 4 planos (Starter, Pro, Business, Enterprise); perfis Administrador, Recepção, Veterinário e Financeiro; suporte por e-mail e WhatsApp, seg–sex 8h–18h; retenção de 30 dias após o cancelamento. Nesse recorte, comunicação, internação/cirurgia, IA e governança da rede ficaram "em breve", desligadas por flag.

**Decisão de 29/10/2026:** internação, cirurgia, comunicação, documentos, IA (copiloto), MCP e governança da rede **passam a fazer parte do MVP**. O código desses módulos já existe. Falta homologá-los e ligá-los na oferta (seção 2.7).

## 2. Pendências do MVP

### 2.1 Para fechar o código (P0)

- [x] Revisão final da Etapa 5 (rodada 2) aprovada. O bloqueante do Pix pago depois do cancelamento foi resolvido, com 0 pendências promovidas. As três sugestões da T-21 (corrida com a fase 3, ordem de trava e ramo `succeeded`) não causam cobrança sem estorno; ficam em 2.6.
- [ ] Commitar o fechamento da Etapa 5 (`final.md`, eficiência) na branch.

### 2.2 Integração e publicação (P0)

- [ ] Autenticar o GitHub CLI (`gh auth login`); sem isso nenhum PR sai daqui.
- [ ] PR da Etapa 4 para `dev`, pela branch `merge/task/etapa-4-operacao-dev` (conflito com a rodada 4 já resolvido; CI local 2422/2422).
- [ ] PR da Etapa 5 para `dev`, que leva junto as etapas 1–4. Precisa de nova branch `merge/...-dev` se conflitar.
- [ ] Primeira execução real do workflow `.github/workflows/ci.yml` no GitHub.
- [ ] Testar na `dev` online; depois, PR da mesma branch para `main`.

### 2.3 Banco e ambientes (P0)

- [ ] Aplicar a migration 0020 (Etapa 5) no banco de dev. Exige autorização explícita de SQL.
- [ ] Aplicar 0015–0020 no ambiente de homologação/produção, na ordem e com backup testado antes (`docs/runbooks/migrations.md`).
- [ ] Rodar os seeds `plan-groups.sql` e `role-groups.sql` em homologação/produção, nessa ordem. Não reaplicar o seed inicial depois deles sem conferir os grants de IA.
- [ ] Registrar o SHA-256 real em `schema_migrations`: 0015–0020 foram registradas com o checksum provisório `0000…`. Também exige autorização de SQL.
- [ ] Reconstruir a imagem do `app` (o :8081 está sem `legal.php` e sem as telas novas) e fazer a fumaça no navegador:
  - diálogo de aceite no primeiro acesso;
  - telas de assinatura, onboarding, suporte e exportação;
  - links legais da landing;
  - download real do ZIP de exportação.

### 2.4 Configuração de produção (P0)

- [ ] Chaves Stripe live (cartão e Pix), price IDs dos 4 planos, segredo do webhook e endpoint registrado no Stripe.
- [ ] Domínio, TLS, `trusted-proxies.production.conf` com os proxies reais e cookies Secure conferidos atrás do proxy.
- [ ] SMTP real com remetente configurado; validar a entrega de convite, reset de senha e alerta ao operador.
- [ ] `BILLING_OPERATOR_EMAIL`, `SUPPORT_EMAIL`/WhatsApp, `OFFER_PLANS` e flags do recorte no `.env` de produção; `bin/preflight.php --with-db` sem `FAIL`.
- [ ] Cron instalado (crontab ou agendador do painel, via `scripts/cron-run.sh`):
  - reconciliador a cada 15 min;
  - backup;
  - ops-monitor;
  - retenção LGPD.
- [ ] Destino real da cópia externa do backup: escolher o bucket S3 compatível (R2, B2 ou outro) ou o disco montado, e fazer uma restauração de teste a partir dele.
- [ ] Na hospedagem compartilhada: executar de verdade o cron e a restauração. Até agora só foram exercitados no Docker.

### 2.5 Jurídico, comercial e operação do piloto (P0)

- [ ] Revisão jurídica dos termos de uso e da política de privacidade, hoje marcados `RASCUNHO — revisar com jurídico`: encarregado (DPO), foro, reembolso e bases legais.
- [ ] Confirmar com o dono do piloto os prazos de resposta do suporte e a atualização a cada 2 h úteis (`docs/runbooks/piloto-operacao.md`, hoje "propostos").
- [ ] Escolher as clínicas piloto e executar o roteiro `docs/runbooks/piloto-homologacao.md` com 2 tenants e os 4 perfis.
- [ ] Rodar o checklist `docs/runbooks/piloto-go-live.md` e definir quem acompanha incidentes e critérios de saída (`piloto-operacao.md`).

### 2.6 Dívida técnica de maior risco (P1)

Nenhum item abaixo bloqueia o piloto. Todos foram triados nas revisões finais.

**Operação (Etapa 4)**
- Um flag de manutenção órfão (queda do host durante o restore) fazia o cron pular todos os jobs. A T-05 da Etapa 5 tratou o caso de processo morto. Falta alerta quando um job é pulado por muito tempo.
- `X-Forwarded-Proto` aceito de qualquer cliente no nginx de dev; `trusted-proxies.production.conf` não está no `.gitignore`.
- `scripts/ci/run.sh`: falha do `openssl rand` gera segredo vazio; `CI_PROJECT` só recusa `centralvet`; a guarda de symlink só olha o último componente.
- `php_target=8.4` declarado e não conferido no build; senha do usuário de migration passada em `-e`; `GRANT` fixo em `centralvet`.*.

**Billing (Etapas 3 e 5)**
- Teste do guarda novo de `Subscription::markInvoicePaid`.
- `$wasVoid` sozinho já impede avançar a assinatura, sem conferir o cancelamento.
- Corrida do cancelamento Pix com o reconciliador, que a revisão em andamento vai julgar.

**Aceite legal (T-16)**
- Recusar o aceite conta como senha errada no limitador.
- O aceite é gravado antes do 2FA.
- O checkbox não tem `.cv-touch-target` nem `for` no rótulo.

**Exportação LGPD (T-11)**
- `ai_interaction.*_tokens` e `monthly_token_quota` ficam fora porque casam com o padrão de segredo; refinar o filtro.

**Testes**
- `AiNavigationIntegrationTest` depende do estado do banco e deve filtrar os grupos "Plano".
- A exclusão dos perfis nos grants de IA só tem prova textual.
- A idempotência dos vínculos de perfil não tem cobertura.

### 2.7 Módulos já construídos que entram no MVP (P0)

Os módulos abaixo já existem no código, mas a Etapa 5 os deixou fora da oferta. Cada um precisa ser homologado e ligado antes do piloto.

| Módulo | Origem | O que falta para entrar na oferta |
|---|---|---|
| Internação | Fase 6A | Homologar o fluxo de leitos e a prescrição em internação; incluir na jornada por perfil |
| Cirurgia | Fase 6B | Homologar a agenda cirúrgica, o checklist e o consumo de materiais |
| Comunicação e automações | Fase 7A | Testar envio e entrega reais (hoje só há SMTP; o WhatsApp é link, não automação) |
| Documentos | Fase 7B | Homologar os modelos e decidir se o recibo de recebimento entra (hoje marcado fora do MVP em `documentos.md`) |
| Copiloto de IA (AI Gateway) | Fase 8A | Custo e quota por tenant, termos de uso de IA, revisão clínica das respostas, chave do provedor em produção |
| Ferramentas MCP | Fase 8B | Políticas de escopo por perfil e auditoria das chamadas |
| Governança da rede / várias clínicas | — | Papéis entre clínicas e relatórios consolidados |
| Suporte dedicado | Oferta | Já anunciado; definir o que muda em relação ao suporte padrão |

Impactos no que a Etapa 5 entregou:

- [ ] **Recorte da oferta.** Ligar os módulos em `OfferScope` e nas flags de ambiente. A guarda deixa de responder `not_released` para eles.
- [ ] **Landing e checkout.** Tirar o "em breve" desses módulos e alinhar o que cada plano inclui (`LandingCatalog`, `PlanEntitlements`). Hoje IA, internação e cirurgia ficam só no Enterprise.
- [ ] **Perfis restritos.** A T-19 excluiu os perfis Recepção, Veterinário e Financeiro dos grants de IA. Decidir quais perfis acessam IA, internação, cirurgia e comunicação, e então ajustar `ClinicRoleProfiles`, `role-groups.sql` e os testes.
- [ ] **Jornada e roteiros.** Estender a `PilotJourneyIntegrationTest` e o `piloto-homologacao.md` aos novos módulos.
- [ ] **Termos e privacidade.** Cobrir o uso de IA (dados enviados ao provedor, revisão humana) e as mensagens a tutores (consentimento de contato).
- [ ] **Exportação LGPD.** Conferir se as tabelas de internação, cirurgia, comunicação e IA entram no ZIP, incluindo as colunas `*_tokens`, hoje filtradas.
- [ ] **Operação.** Alertas para mensagens e documentos com falha, que a auditoria pedia e o monitor ainda não cobre, e o custo de IA por tenant.

### 2.8 Área de gestão do sistema (back-office) (P0)

**Decisão de 29/10/2026:** o MVP passa a ter uma área de gestão do sistema, usada pela equipe do Central Vet. Ela fica separada da administração de cada clínica e não dá acesso a dados clínicos dos tenants.

| Recurso | O que deve fazer | O que existe hoje |
|---|---|---|
| **Clientes e licenças** | Listar as clínicas que compraram licença, com plano, status da assinatura (ativa, em atraso, suspensa, cancelada, em bônus), período, faturas, administrador e quota de usuários e unidades. Ações: suspender, reativar, trocar de plano, prorrogar e ver o histórico. | Os dados existem (`tenant`, `subscription`, `subscription_invoice`, `billing_order`), mas não há tela de gestão. A troca de plano e a suspensão manual não existem. |
| **Cadastro de planos e ofertas** | Criar e editar planos: nome, preço mensal, price ID do Stripe, quotas, módulos e recursos incluídos, plano ativo ou oculto, ordem na landing. A landing, o checkout, a guarda e as quotas passam a ler o cadastro. | Os planos são constantes no código (`PlanEntitlements::PLANS` e `GATED`, `LandingCatalog`). Mudar uma oferta hoje exige deploy. |
| **Convite com bônus de tempo** | Enviar convite por e-mail a um possível cliente, com N dias de acesso grátis. A clínica é provisionada sem cobrança e, no fim do bônus, a cobrança começa. Avisos antes do vencimento. Sem forma de pagamento ao fim do bônus, o acesso é bloqueado; os dados ficam retidos conforme a política. Painel de convites enviados, aceitos, em bônus e convertidos. | Não há período grátis. O provisionamento só acontece depois do pagamento. O Stripe aceita `trial_period_days` no cartão; no Pix seria preciso adiar a primeira fatura. |
| **Marketing** | Visão dos possíveis clientes: leads da landing, checkouts abandonados, convites não convertidos, clínicas em bônus e o uso delas (último acesso, módulos usados). Filtros e exportação para campanhas. | Os leads são gravados (`LeadRepository`) e há a lista `LandingLeadList` com exportação CSV. Falta juntar os outros sinais. |
| **Horários de atendimento do suporte** | Tabela editável de dias e horários, com feriados e exceções, e os canais (e-mail, WhatsApp). Ela alimenta a tela de suporte, a landing, os termos e as mensagens de "fora do horário". | O horário é um texto único no `.env` (`SUPPORT_HOURS`, com padrão "Segunda a sexta, das 8h às 18h"). Os termos de uso repetem o texto fixo (pendência da T-06). |

Cuidados:

- **Acesso:** só para a equipe interna (operador e admin do sistema), com 2FA obrigatório, trilha de auditoria de cada ação e CSRF. O isolamento entre as clínicas continua valendo: a gestão vê dados comerciais e de uso, não prontuários nem tutores.
- **LGPD no marketing:** usar só quem deu consentimento de contato comercial (o lead já registra isso) ou dados de uso agregados por clínica. Termos e política de privacidade precisam citar esse uso, e o descadastro tem de ser possível.
- **Planos editáveis:** quem já assina mantém o preço e os recursos contratados até trocar de plano; mudar um plano não pode alterar silenciosamente uma assinatura ativa. O preço no Stripe é imutável, então um preço novo gera um price ID novo.
- **Bônus:** o convite não vira uma porta para contas duplicadas. Valem a mesma regra de e-mail já cadastrado da Etapa 3 e um bônus por clínica.

Decisões tomadas em 29/10/2026:

1. **Bônus configurável, padrão de 15 dias.** Cada convite tem dois campos: os dias de bônus, com padrão de 15 (`TRIAL_DEFAULT_DAYS`, editável na gestão), e a validade do convite. O convite que não for aceito até a validade expira e não pode mais ser usado; a equipe pode reenviar, o que gera um convite novo.
2. **Forma de pagamento só no fim.** O cadastro pelo convite não pede cartão. Nos últimos dias do bônus, o administrador da clínica recebe avisos com o link para escolher cartão ou Pix. Se não escolher, o acesso é bloqueado no fim do bônus, a exportação de dados continua disponível e vale a retenção de 30 dias.
3. **Marketing exporta e envia campanhas.** O painel exporta a lista (CSV) e também envia campanhas por e-mail pelo próprio sistema, com modelo, público filtrado, agendamento, registro de envio e link de descadastro em toda mensagem. Ninguém que pediu descadastro volta a receber.
4. **A equipe interna tem perfis de acesso.** A gestão é usada só pela equipe do Central Vet, não pelas clínicas clientes. Os perfis estão abaixo.

Perfis da equipe de gestão do sistema:

| Recurso / ação | Administrador do sistema | Comercial | Financeiro | Suporte |
|---|---|---|---|---|
| Clientes e licenças: consultar | ✔ | ✔ | ✔ | ✔ |
| Suspender, reativar, trocar plano, prorrogar | ✔ | — | ✔ | — |
| Faturas, estornos e conciliação com o Stripe | ✔ | — | ✔ | consulta |
| Cadastro de planos e ofertas | ✔ | consulta | consulta | — |
| Convites com bônus: enviar, reenviar, cancelar | ✔ | ✔ | — | reenviar |
| Prazo padrão do bônus e validade do convite | ✔ | — | — | — |
| Marketing: painel, exportação | ✔ | ✔ | — | — |
| Marketing: enviar campanhas | ✔ | ✔ | — | — |
| Horários de atendimento do suporte | ✔ | — | — | ✔ |
| Usuários e perfis da equipe de gestão | ✔ | — | — | — |
| Trilha de auditoria da gestão | ✔ | — | consulta | — |

Regras desses perfis:

- Todos usam 2FA obrigatório.
- Toda ação que muda dados fica na trilha de auditoria, com quem fez, quando, o antes e o depois.
- Nenhum perfil acessa prontuários, tutores ou dados clínicos das clínicas.
- Só o Administrador do sistema cria usuários da equipe e define os perfis deles.
- Os perfis são fixos no MVP; o editor de perfis da gestão fica para depois.
- Esses perfis não se confundem com os perfis das clínicas (Administrador, Recepção, Veterinário, Financeiro).

### 2.9 Documentação desatualizada (P2)

- `observabilidade.md:51` deve citar `src/bin/billing-reconcile.php`. A linha 55 diz que, sem `BILLING_OPERATOR_EMAIL`, o alerta vai ao `error_log`, mas o código só não envia.
- `smoke-billing.md:158` espera `assinatura-2026-10`; o valor atual é `termos-uso-2026-10`.
- `restore-backup.md § 4` não cita `CENTRALVET_MAINTENANCE_DIR`; `tests.md` não descreve a restrição do `CI_WORKDIR`.
- O contorno de `umask` ficou redundante em `release.md` e `rollback-drill.sh`.
- Comentários desatualizados em `role-groups.sql:10` e `initial-application-programs.sql:1056`.
- O template `reset_password.html` ficou sem uso.

## 3. Fora do MVP — desenvolvimento futuro

### 3.1 Evolução da plataforma (PRD v1.1)

- **Fase 8C — Actions/Agents:** ações controladas e auditadas a partir do copiloto, sem ação automática de expansão ou compartilhamento. Ainda não iniciada.
- **Fase 9 — SaaS e escala:** limites e entitlements por uso, hardening, recuperação de desastre e custo por cidade e uso. A base de billing e de direitos por plano foi feita na Etapa 3.
- **Editor de perfis** por clínica; hoje há só os 3 perfis fixos.
- **Exportação com arquivos binários** (fotos e PDFs); no piloto saem pelo suporte.
- **Exclusão automática** dos dados após os 30 dias de retenção; hoje é manual, pelo operador.

### 3.2 Central Intelligence (piloto Imperatriz/MA)

Prioridade P1 para descoberta e desenho e P2 para MVP e expansão. Requisitos no [PRD](../prd/central-intelligence.md); fronteira analítica na [ADR 0004](../../docs/architecture/adr/0004-central-intelligence-analytical-boundary.md).

| Etapa | Entrega |
|---|---|
| CI-0 | Descoberta local: entrevistas, inventário de dados, auditoria de POIs, protótipo e orçamento |
| CI-1 | Preparação arquitetural: contratos, taxonomia, localização, permissões, proveniência e jobs |
| CI-2 | Mapa público de Imperatriz: oferta, população, renda e índice explicável de potencial |
| CI-3 | Inteligência do próprio negócio: origem regional, mix de serviços, sazonalidade |
| CI-4 | Benchmark da rede, com supressão e governança |
| CI-5 | Novas cidades, dados licenciados e premium escalável |

Épicos: E20 (descoberta e fontes), E21 (geografia e mapa), E22 (análise territorial própria), E23 (governança e benchmark), E24 (oportunidades e explicações) e E25 (comercialização).

### 3.3 Ei, Vet! — rede veterinária

Prioridade P1 para validação e fronteiras arquiteturais e P2 para MVP e piloto. Requisitos no [PRD](../prd/ei-vet.md); fronteira na [ADR 0005](../../docs/architecture/adr/0005-veterinary-network-boundary.md).

| Etapa | Entrega |
|---|---|
| EV-0 | Validação: serviços iniciais, entrevistas, verificação e políticas |
| EV-1 | Fundação da rede: identidade profissional independente, perfis publicáveis, disponibilidade com validade |
| EV-2 | Serviço avulso: busca, demanda, convite, conversa, proposta e confirmação bilateral |
| EV-3 | Operação piloto: conclusão, cancelamento, contestação, denúncias e métricas |
| EV-4 | Expansão: especialistas, encaminhamentos e integrações |

Épicos: E26 (fundação do ecossistema), E27 (descoberta), E28 (demandas e comunicação), E29 (contratação e operação) e E30 (governança e evolução).

### 3.4 Preparação no core

Itens do roadmap para fazer nas próximas mudanças do core, sem criar tabelas nem serviços até haver decisão:

- Separar identidade autenticada, perfil profissional e vínculo com clínica, sem tenant fictício para profissional independente.
- Endereço estruturado de tutores e localização da unidade, como evolução aditiva.
- Taxonomia padronizada de serviços, com mapeamento por tenant.
- Semântica de indicadores: atendimento, venda, recebimento, cancelamento, estorno, moeda e fuso.
- Extração incremental idempotente ou outbox transacional.
- Interfaces de consulta e de fornecedores geográficos no core.
- Flags desligadas por padrão e separação entre dados públicos, privados e agregados.

### 3.5 Decisões em aberto

- **Central Intelligence:**
  - responsável pelo piloto e capacidade da equipe;
  - orçamento mensal, fornecedor de mapa e geocodificação e limites oficiais de bairros;
  - contratos das fontes, bases legais, metas comerciais e preço.
- **Ei, Vet!:**
  - cidade piloto e serviços iniciais;
  - verificação dos registros profissionais;
  - monetização, convites e prazos;
  - cancelamento e contestação, retenção e responsáveis pelo suporte.
