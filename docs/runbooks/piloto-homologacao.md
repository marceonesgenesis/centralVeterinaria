# Piloto: roteiro de homologação

Roteiro manual para homologar a jornada do piloto, repetido em **2 tenants** (A com plano Pro, B com plano Business). Cada passo tem resultado esperado observável; anote a evidência (captura de tela, ID do registro ou trecho do log) na última coluna. Use apenas dados fictícios: tutor "Tutor Teste A/B", paciente "Paciente Teste A/B" e e-mails de caixa de teste da equipe (`*@exemplo.invalid` onde não houver entrega real).

Sem credencial real neste documento. Qualquer comando de escrita em banco exige autorização explícita antes de rodar.

## Parte automatizada

`src/tests/Integration/PilotJourneyIntegrationTest.php` (`PilotJourneyIntegrationTest`) cobre a jornada sem navegador: dois tenants (A `pro`, B `business`), perfis restritos negados fora dos seus programas, isolamento entre tenants (B não vê o paciente nem paga o recebível de A) e a quota de usuários no plano Starter. Rode antes do roteiro manual (ver [`tests.md`](./tests.md)). O roteiro abaixo cobre o que só o navegador mostra.

## Preparação

- Ambiente com migrations 0001–0020 e os seeds de [`piloto-go-live.md`](./piloto-go-live.md) aplicados (com autorização).
- Dois tenants criados pelo checkout (ou pelo provisionamento de teste), cada um com administrador e Stripe em modo de teste (ver [`smoke-billing.md`](./smoke-billing.md)).
- Registre o ambiente, o commit (`RELEASE_MANIFEST`) e a data no topo da planilha de evidências.

## Roteiro por perfil

Repita cada bloco no tenant A e no tenant B.

### 1. Administrador

| # | Passo | Resultado esperado | Evidência |
|---|---|---|---|
| 1.1 | Abrir o link de convite/primeiro acesso e entrar | Diálogo de aceite dos Termos de Uso e da Política de Privacidade; sem marcar, o login não segue | |
| 1.2 | Marcar o aceite e confirmar | Entra no sistema; menu superior abre em "Primeiros passos" | |
| 1.3 | Abrir "Primeiros passos" | Checklist de onboarding com os passos pendentes; guia do primeiro atendimento acessível | |
| 1.4 | Abrir "Dados da clínica", preencher nome fantasia e salvar | Toast "Registro salvo"; checklist marca o passo | |
| 1.5 | Convidar um usuário de cada perfil (Recepção, Veterinário, Financeiro) | Convites enviados; cada usuário aparece com o perfil escolhido | |
| 1.6 | Marcar a equipe como revisada | Passo da equipe concluído no checklist | |
| 1.7 | Ler os Termos e a Política pelo rodapé/ajuda (`legal.php?doc=termos`, `legal.php?doc=privacidade`) | Páginas abrem sem login; texto sem a marca `RASCUNHO — revisar com jurídico` (após a revisão jurídica) | |

### 2. Recepção

| # | Passo | Resultado esperado | Evidência |
|---|---|---|---|
| 2.1 | Primeiro acesso do usuário de Recepção, com aceite dos termos | Entra; guia do primeiro atendimento e "Ajuda" acessíveis; "Primeiros passos" (checklist do administrador) não aparece | |
| 2.2 | Cadastrar tutor e paciente de teste | Registros salvos; aparecem na busca | |
| 2.3 | Agendar uma consulta (Agenda e formulário de agendamento) | Agendamento visível na agenda | |
| 2.4 | Tentar abrir Financeiro, Atendimento clínico, Assinatura, Equipe e Dados da clínica pela URL | Acesso negado com mensagem em pt-BR; nenhuma tela abre | |

### 3. Veterinário

| # | Passo | Resultado esperado | Evidência |
|---|---|---|---|
| 3.1 | Primeiro acesso com aceite dos termos | Entra; guia do primeiro atendimento acessível; sem checklist de onboarding | |
| 3.2 | Abrir o atendimento do paciente, registrar anamnese, prescrição e exame | Atendimento salvo | |
| 3.3 | Emitir a prescrição do atendimento (PDF assíncrono, pela solicitação de documento) | PDF da prescrição gerado, listado em Documentos e baixável; abre sem erro. É o PDF homologado do piloto | |
| 3.4 | Tentar abrir Financeiro, Assinatura e Equipe pela URL | Acesso negado em pt-BR | |
| 3.5 | Confirmar que o assistente de IA não aparece | Ausente do menu (módulo fora do recorte) | |

### 4. Financeiro

| # | Passo | Resultado esperado | Evidência |
|---|---|---|---|
| 4.1 | Primeiro acesso com aceite dos termos | Entra; guia do primeiro atendimento acessível; sem checklist de onboarding | |
| 4.2 | Abrir a sessão de caixa, abrir a conta do atendimento e fechá-la | Recebível criado em "Recebíveis pendentes" com o total da conta | |
| 4.3 | Registrar um pagamento parcial e depois o restante | Saldo em aberto cai a cada pagamento; com o total pago o recebível fica quitado | |
| 4.4 | Conferir saldo e caixa | Totais da sessão de caixa e visão financeira batem com os pagamentos registrados | |
| 4.5 | Conferir que não há PDF de recibo para o Financeiro | Sem botão de recibo: recibo de recebimento está fora do recorte do piloto (ver [`documentos.md`](./documentos.md)); o PDF homologado é a prescrição (passo 3.3) | |
| 4.6 | Tentar abrir Atendimento clínico e Equipe pela URL | Acesso negado em pt-BR | |

## Isolamento entre tenants

| # | Passo | Resultado esperado | Evidência |
|---|---|---|---|
| 5.1 | No tenant B, buscar o "Paciente Teste A" | Nenhum resultado | |
| 5.2 | No tenant B, abrir por URL o recebível do tenant A | Mensagem amigável de registro não encontrado, sem detalhe técnico | |

## Mensagens de erro esperadas (pt-BR)

Toda mensagem ao usuário aparece em português, sem texto técnico em inglês, sem pilha e sem SQL.

| # | Passo | Resultado esperado | Evidência |
|---|---|---|---|
| 9.1 | Entrar com senha errada | Mensagem de credencial inválida em pt-BR | |
| 9.2 | Salvar um formulário com campo obrigatório em branco | Mensagem de campo obrigatório em pt-BR | |
| 9.3 | Abrir uma tela fora do perfil (passos 2.4, 3.4 e 4.6) | Mensagem de acesso negado em pt-BR | |
| 9.4 | Abrir um registro inexistente ou de outro tenant (passo 5.2) | Mensagem de registro não encontrado em pt-BR | |
| 9.5 | Convidar além da quota (passo 6.3) | Mensagem de quota excedida em pt-BR | |

## Recuperação de falhas

| # | Cenário | Passo | Resultado esperado | Evidência |
|---|---|---|---|---|
| 6.1 | Pagamento recusado | No checkout de teste, pagar com o cartão de recusa do Stripe | Mensagem em pt-BR; nenhuma assinatura ativa criada; nova tentativa possível | |
| 6.2 | Convite expirado | Abrir um convite vencido | Mensagem em pt-BR orientando a pedir novo convite; o administrador reenvia e o novo convite funciona | |
| 6.3 | Quota de usuários | Em tenant Starter, convidar além do limite | Recusa com mensagem de quota excedida | |

## Módulo fora do recorte

| # | Passo | Resultado esperado | Evidência |
|---|---|---|---|
| 7.1 | Abrir Comunicação, Internação/Cirurgia ou IA avançada com `OFFER_PREVIEW_FEATURES` vazio | Mensagem `Este módulo ainda não está disponível no piloto`; nenhuma tela do módulo abre | |
| 7.2 | Conferir a página de planos | Itens fora do recorte marcados como "em breve" | |

## Suporte, cancelamento e exportação

| # | Passo | Resultado esperado | Evidência |
|---|---|---|---|
| 8.1 | Abrir "Ajuda" | Canal de suporte: e-mail, WhatsApp (se configurado) e horário de segunda a sexta, das 8h às 18h | |
| 8.2 | Como administrador, abrir Assinatura e "Cancelar assinatura" | Tela de confirmação com a data do fim do período e o prazo de retenção (30 dias) | |
| 8.3 | Confirmar o cancelamento | Cancelamento agendado para o fim do período (resultado `scheduled`); status mostra a data; acesso segue até lá | |
| 8.4 | Repetir a confirmação | Mensagem de cancelamento já agendado, sem erro | |
| 8.5 | Abrir "Exportar dados da clínica" e baixar | ZIP com um CSV por tabela e manifesto; sem colunas de senha, token ou segredo; `files_included: false` | |
| 8.6 | Conferir que a exportação segue acessível com a assinatura cancelada/suspensa | Download funciona | |

## Fechamento

Homologado quando todos os passos dos 2 tenants têm evidência e resultado conforme o esperado. Defeito encontrado: registre com o passo, o tenant, a captura e a severidade (ver [`piloto-operacao.md`](./piloto-operacao.md)).
