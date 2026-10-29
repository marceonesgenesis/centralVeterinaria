# Piloto: roteiro de homologação

Roteiro manual para homologar a jornada do piloto, repetido em **2 tenants** (A com plano Pro, B com plano Business) e na **Área de Gestão Central Vet** (`/gestao.php`). Cada passo tem resultado esperado observável; anote a evidência (captura de tela, ID do registro ou trecho do log) na última coluna. Use apenas dados fictícios: tutor "Tutor Teste A/B", paciente "Paciente Teste A/B" e e-mails de caixa de teste da equipe (`*@exemplo.invalid` onde não houver entrega real).

Sem credencial real neste documento. Qualquer comando de escrita em banco exige autorização explícita antes de rodar.

## Parte automatizada

- `src/tests/Integration/PilotJourneyIntegrationTest.php` (`PilotJourneyIntegrationTest`) cobre a jornada completa sem navegador: dois tenants (A `pro`, B `business`), perfis restritos (Recepção, Veterinário, Financeiro), módulos integrados no MVP (Internação, Cirurgia, Comunicação, Copiloto IA), isolamento estrito entre tenants e quota de usuários no plano Starter.
- `src/tests/Unit/BackofficeCoreTest.php` cobre as regras de negócio, 2FA, perfis e auditoria da Área de Gestão Central Vet.
Rode a suíte automatizada antes do roteiro manual (ver [`tests.md`](./tests.md)).

## Preparação

- Ambiente com migrations 0001–0021 e os seeds de [`piloto-go-live.md`](./piloto-go-live.md) (`plan-groups.sql` e `role-groups.sql`) aplicados no banco de dados.
- Dois tenants criados pelo checkout (ou pelo provisionamento de teste), cada um com administrador e Stripe em modo de teste (ver [`smoke-billing.md`](./smoke-billing.md)).
- Um usuário da equipe interna do Central Vet criado com perfil de Gestão para acesso em `/gestao.php`.
- Registre o ambiente, o commit (`RELEASE_MANIFEST`) e a data no topo da planilha de evidências.

## Roteiro por perfil da clínica

Repita cada bloco no tenant A e no tenant B.

### 1. Administrador da Clínica

| # | Passo | Resultado esperado | Evidência |
|---|---|---|---|
| 1.1 | Abrir o link de convite/primeiro acesso e entrar | Diálogo de aceite dos Termos de Uso e da Política de Privacidade (com cláusulas de IA e comunicação); sem marcar, o login não segue | |
| 1.2 | Marcar o aceite e confirmar | Entra no sistema; menu superior abre em "Primeiros passos" | |
| 1.3 | Abrir "Primeiros passos" | Checklist de onboarding com os passos pendentes; guia do primeiro atendimento acessível | |
| 1.4 | Abrir "Dados da clínica", preencher nome fantasia e salvar | Toast "Registro salvo"; checklist marca o passo | |
| 1.5 | Convidar um usuário de cada perfil (Recepção, Veterinário, Financeiro) | Convites enviados; cada usuário aparece com o perfil escolhido | |
| 1.6 | Marcar a equipe como revisada | Passo da equipe concluído no checklist | |
| 1.7 | Ler os Termos e a Política pelo rodapé/ajuda (`legal.php?doc=termos`, `legal.php?doc=privacidade`) | Páginas abrem sem login; texto atualizado cobrindo IA e consentimento de tutores | |

### 2. Recepção

| # | Passo | Resultado esperado | Evidência |
|---|---|---|---|
| 2.1 | Primeiro acesso do usuário de Recepção, com aceite dos termos | Entra; guia do primeiro atendimento e "Ajuda" acessíveis; "Primeiros passos" (checklist do administrador) não aparece | |
| 2.2 | Cadastrar tutor e paciente de teste | Registros salvos; aparecem na busca | |
| 2.3 | Registrar preferências de comunicação do tutor | Opções de lembretes por WhatsApp/E-mail salvas com consentimento auditado | |
| 2.4 | Agendar uma consulta (Agenda e formulário de agendamento) | Agendamento visível na agenda clínica | |
| 2.5 | Realizar o check-in do paciente na fila de espera | Paciente marcado como presente, pronto para atendimento | |
| 2.6 | Tentar abrir Financeiro, Atendimento clínico, Assinatura e Dados da clínica pela URL | Acesso negado com mensagem amigável em pt-BR; nenhuma tela abre | |

### 3. Veterinário

| # | Passo | Resultado esperado | Evidência |
|---|---|---|---|
| 3.1 | Primeiro acesso com aceite dos termos | Entra; guia do atendimento acessível; sem checklist de onboarding | |
| 3.2 | Abrir o atendimento do paciente, registrar anamnese, prescrição e exame | Atendimento registrado e salvo com sucesso | |
| 3.3 | Emitir a prescrição do atendimento (PDF assíncrono pela solicitação de documento) | PDF da prescrição gerado com sucesso, listado em Documentos e baixável | |
| 3.4 | Abrir Copiloto de IA no atendimento | Painel de IA abre; sugestão clínica exibida; revisão humana explícita obrigatória antes de incorporar ao prontuário; tokens registrados | |
| 3.5 | Internação: Admitir o paciente internado em um leito (`HospitalizationAdmissionForm`) | Paciente internado; leito marcado como ocupado; evolução clínica e prescrição hospitalar salvas | |
| 3.6 | Cirurgia: Agendar cirurgia e preencher checklist cirúrgico (`SurgeryForm`, `SurgeryChecklistForm`) | Cirurgia agendada com sala e equipe; checklist pré-operatório preenchido e validado | |
| 3.7 | Tentar abrir Financeiro, Assinatura e Gestão de Equipe pela URL | Acesso negado em pt-BR | |

### 4. Financeiro

| # | Passo | Resultado esperado | Evidência |
|---|---|---|---|
| 4.1 | Primeiro acesso com aceite dos termos | Entra; sem checklist de onboarding administrativo | |
| 4.2 | Abrir a sessão de caixa, abrir a conta do atendimento e fechá-la | Recebível criado em "Recebíveis pendentes" com o total devido da conta | |
| 4.3 | Registrar um pagamento parcial e depois o restante (dinheiro e Pix) | Saldo em aberto diminui a cada pagamento; recebível quitado no pagamento final | |
| 4.4 | Conferir saldo e fechamento do caixa | Totais da sessão de caixa e visão financeira batem 100% com os pagamentos | |
| 4.5 | Tentar abrir Atendimento clínico e Configurações de Equipe pela URL | Acesso negado em pt-BR | |

## 5. Área de Gestão Central Vet (`/gestao.php`)

Homologação exclusiva da equipe interna de gestão da plataforma (back-office):

| # | Passo | Resultado esperado | Evidência |
|---|---|---|---|
| 5.1 | Acessar o endpoint exclusivo `/gestao.php` | Tela de Login da Gestão abre com identidade visual exclusiva e campo de código 2FA TOTP | |
| 5.2 | Tentar logar com credenciais de cliente comum | Bloqueio sumário com mensagem "Acesso restrito à equipe interna Central Vet" | |
| 5.3 | Entrar com credencial de operador interno e código TOTP 2FA válido | Acesso liberado; menu de gestão exclusivo exibido | |
| 5.4 | Dashboard Executivo (`GestaoDashboardView`) | Indicadores de assinaturas ativas, MRR, inadimplência e taxa de conversão visíveis | |
| 5.5 | Clientes & Licenças (`GestaoClientList`) | Lista das clínicas cadastradas com plano, status e faturas; ações de prorrogar e suspender disponíveis | |
| 5.6 | Convites com Degustação (`GestaoInviteList`) | Criar convite com 15 dias de bônus gratuito (sem pedir cartão); convite gerado e auditado | |
| 5.7 | Trilha de Auditoria (`GestaoAuditLogList`) | Registra quem fez a ação, IP, timestamp e diff antes/depois | |
| 5.8 | Isolamento de Dados Clínicos | Confirmar que nenhum operador da gestão tem acesso a prontuários, tutores ou pacientes clínicos dos tenants | |

## 6. Isolamento entre tenants

| # | Passo | Resultado esperado | Evidência |
|---|---|---|---|
| 6.1 | No tenant B, buscar o "Paciente Teste A" | Nenhum resultado retornado | |
| 6.2 | No tenant B, abrir por URL o recebível do tenant A | Mensagem amigável de registro não encontrado em pt-BR, sem vazamento técnico | |
| 6.3 | No tenant B, tentar acessar a internação ou cirurgia do tenant A | Registro não encontrado / acesso negado | |

## 7. Mensagens de erro esperadas (pt-BR)

Toda mensagem ao usuário aparece em português, sem texto técnico em inglês, sem pilha e sem SQL.

| # | Passo | Resultado esperado | Evidência |
|---|---|---|---|
| 7.1 | Entrar com senha errada | Mensagem de credencial inválida em pt-BR | |
| 7.2 | Salvar um formulário com campo obrigatório em branco | Mensagem de campo obrigatório em pt-BR | |
| 7.3 | Abrir uma tela fora do perfil | Mensagem de acesso negado em pt-BR | |
| 7.4 | Abrir um registro inexistente ou de outro tenant | Mensagem de registro não encontrado em pt-BR | |
| 7.5 | Convidar além da quota de usuários | Mensagem amigável de quota excedida em pt-BR | |

## 8. Recuperação de falhas

| # | Cenário | Passo | Resultado esperado | Evidência |
|---|---|---|---|---|
| 8.1 | Pagamento recusado | No checkout de teste, pagar com o cartão de recusa do Stripe | Mensagem em pt-BR; nenhuma assinatura ativa criada; nova tentativa possível | |
| 8.2 | Convite expirado | Abrir um convite vencido | Mensagem em pt-BR orientando a pedir novo convite; o administrador reenvia e o novo convite funciona | |
| 8.3 | Quota de usuários | Em tenant Starter, convidar além do limite (2 usuários) | Recusa com mensagem clara de quota excedida | |

## 9. Módulos fora do recorte do MVP (roadmap pós-MVP)

Os recursos abaixo continuam marcados como "em breve" no catálogo da landing page e não fazem parte do MVP:

| # | Passo | Resultado esperado | Evidência |
|---|---|---|---|
| 9.1 | Conferir na landing e planos os itens "em breve" | "Relatórios avançados", "Integrações", "Governança da rede" e "SSO e SLA" exibidos com tag "Em breve" | |

## 10. Suporte, cancelamento e exportação LGPD

| # | Passo | Resultado esperado | Evidência |
|---|---|---|---|
| 10.1 | Abrir "Ajuda" | Canal de suporte: e-mail, WhatsApp (se configurado) e horários de atendimento vigentes | |
| 10.2 | Como administrador, abrir Assinatura e "Cancelar assinatura" | Tela de confirmação com a data do fim do período e o prazo de retenção (30 dias) | |
| 10.3 | Confirmar o cancelamento | Cancelamento agendado para o fim do período (resultado `scheduled`); status mostra a data; acesso segue até lá | |
| 10.4 | Repetir a confirmação | Mensagem de cancelamento já agendado, sem duplicar solicitação | |
| 10.5 | Abrir "Exportar dados da clínica" e baixar | ZIP com um CSV por tabela (incluindo internação, cirurgia e IA com contadores de tokens preservados); dados confidenciais (senhas, hashes) removidos; manifesto íntegro | |
| 10.6 | Conferir que a exportação segue acessível com a assinatura cancelada/suspensa | Download do ZIP de exportação continua funcionando durante a janela de retenção | |

## Fechamento

Homologado quando todos os passos dos 2 tenants e da Área de Gestão têm evidência e resultado conforme o esperado. Defeito encontrado: registre com o passo, o tenant, a captura e a severidade (ver [`piloto-operacao.md`](./piloto-operacao.md)).
