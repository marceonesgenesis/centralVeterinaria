# Plano de desenvolvimento — Central Vet Pro

Versão de planejamento: 1.3 · Atualizado em 02/10/2026 · Piloto da Central Intelligence: **Imperatriz/MA**.

Este documento complementa `PRD_Central_Vet_Pro_v1_1_MCP.docx`, preservando sua trilha operacional e acrescentando inteligência de mercado e a rede Ei, Vet!. Não representa uma nova versão implementada da aplicação nem altera planos comerciais existentes. O histórico de execução permanece em `.tasks/`.

## Direção do produto

A Central Vet Pro evolui para um **ecossistema veterinário integrado**: infraestrutura que conecta clínicas, veterinários, especialistas e serviços, enquanto cada clínica mantém seu ambiente e informações privadas. Três camadas compõem essa direção:

- **Gestão privada:** agenda, prontuário, estoque, vendas, financeiro, cirurgia, internação e comunicação da própria clínica.
- **Rede de conexão — Ei, Vet!:** descoberta de veterinários disponíveis, comunicação e combinação de serviços avulsos; primeira ferramenta de colaboração do ecossistema.
- **Central Intelligence:** visão territorial de oferta, contexto demográfico, sinais próprios de consumo e, quando houver condições, indicadores agregados da rede.

O Ei, Vet! resolve especialmente a necessidade de clínicas que ainda não conseguem manter um plantonista e precisam acionar um profissional para uma demanda pontual. O veterinário participa com identidade profissional própria, inclusive sem possuir uma clínica no ERP. Especialistas e outros serviços podem ampliar a rede em etapas futuras.

A colaboração acontece por perfis publicados e recursos compartilhados com participantes autorizados. Cada clínica mantém seus dados operacionais isolados: entrar na rede ou contratar um profissional não abre prontuários, tutores, agenda interna, estoque ou financeiro. A visão do ecossistema orienta a arquitetura desde agora; o módulo permanece trabalho futuro.

## Situação e prioridades

O repositório contém planos e registros de execução das fases 0–5, além das trilhas de design e navegação. O ambiente online foi adaptado ao MySQL 5.7 e à hospedagem compartilhada; detalhes em [runbook](../../docs/runbooks/shared-hosting-mysql57.md). A confirmação de cada requisito concluído deve consultar os registros e testes da respectiva tarefa: existência de uma fase no repositório não comprova prontidão comercial integral.

A prioridade continua sendo estabilizar a jornada clínica e financeira, validar a aplicação online e fechar requisitos comerciais e operacionais. A Intelligence começa com descoberta e arquitetura; o desenvolvimento do mapa e da rede não bloqueia essas entregas. O desenho da identidade profissional e da autorização por participantes deve anteceder a implementação do Ei, Vet!.

| Trilha do PRD v1.1 | Resultado preservado | Relação com a Intelligence |
|---|---|---|
| Fase 0 — Fundação | Multi-tenant, autenticação, RBAC, auditoria, infraestrutura | Isolamento, permissões e conectores reutilizáveis |
| Fase 1 — Cadastros/agenda | Tutores, pacientes, agenda, entrada operacional | Planejar endereço estruturado e localização da unidade |
| Fase 2 — Núcleo clínico | Atendimento, prontuário e plano clínico | Dados clínicos individuais permanecem privados |
| Fase 3 — Prescrição/exames/vacinas | Conduta, exames, prevenção e documentos | Não exportar textos clínicos para inteligência de mercado |
| Fase 4 — Procedimentos/estoque/vendas | Procedimentos, consumo de materiais e PDV | Taxonomia de serviços e indicadores próprios futuros |
| Fase 5 — Financeiro | Contas, caixa, recebimentos e lançamentos | Definir venda, receita, estorno e período sem dupla contagem |
| Fase 6 — Cirurgia/internação | Operação clínica avançada | Mantida na trilha operacional |
| Fase 7 — Comunicação/automações | Lembretes, inbox e follow-up | Ações próprias derivadas de diagnósticos futuros |
| Fase 8A — AI Gateway | Copiloto contextual | Explicações somente sobre indicadores calculados |
| Fase 8B — MCP | Ferramentas de leitura com políticas | Ferramentas analíticas futuras com controle de escopo |
| Fase 8C — Actions/Agents | Ações controladas e auditadas | Nenhuma ação automática de expansão ou compartilhamento |
| Fase 9 — SaaS/escala | Billing, planos, limites, hardening e recuperação | Entitlement premium e custo por cidade/uso |

As durações originais do PRD são estimativas históricas. Não foram convertidas em datas de entrega neste complemento.

## Nova trilha: Ei, Vet!

Prioridade proposta: **P1 para validação e fronteiras arquiteturais; P2 para MVP e piloto**. Região do piloto, modelo comercial e datas ainda não estão definidos. Não assumir que o piloto de Imperatriz da Intelligence já se aplica à rede.

| Etapa | Entrega | Dependência e condição de avanço |
|---|---|---|
| EV-0 — Validação | Serviços iniciais, entrevistas com clínicas/profissionais, verificação e políticas | Responsáveis, termos e operação de suporte definidos |
| EV-1 — Fundação da rede | Identidade independente, perfis publicáveis, serviços e disponibilidade com validade | Autorização por participante sem enfraquecer isolamento |
| EV-2 — Serviço avulso | Busca, demanda, convite, conversa, proposta e confirmação bilateral | Controle de concorrência, idempotência e notificações |
| EV-3 — Operação piloto | Conclusão, cancelamento, contestação, denúncias e métricas | Suporte e critérios de aceite do PRD verificados |
| EV-4 — Expansão | Especialistas, encaminhamentos e integrações | Evidência do piloto e especificações adicionais |

O MVP proposto usa convites direcionados e negociação interna. Disponibilidade declarada não garante resposta ou atendimento imediato. Pagamento intermediado, comissões e acesso a prontuários exigem decisões próprias. Requisitos no [PRD Ei, Vet!](../prd/ei-vet.md); fronteiras na [ADR 0005](../../docs/architecture/adr/0005-veterinary-network-boundary.md).

## Nova trilha: Central Intelligence

Prioridade proposta: **P1 para descoberta e desenho; P2 para MVP e expansão**, sujeita à validação do piloto. Estimativas abaixo são faixas preliminares de esforço de engenharia em pessoa-semana, não calendário nem orçamento contratado. Devem ser refeitas após CI-0; contratação de dados e validação jurídica podem dominar o prazo.

| Etapa | Entregas | Dependências | Critério para avançar | Esforço indicativo |
|---|---|---|---|---|
| CI-0 — Descoberta local | Entrevistas, inventário de dados, auditoria de amostra de POIs, protótipo e orçamento | Responsável pelo piloto e acesso às fontes | Problema recorrente, cobertura aceitável e custo viável | 1–2 |
| CI-1 — Preparação arquitetural | Contratos, taxonomia, proposta de localização, permissões, proveniência e desenho de jobs | Fundação e revisão das ADRs | Desenho revisado sem enfraquecer isolamento; infraestrutura definida | 1–2 |
| CI-2 — Mapa público de Imperatriz | Pontos de oferta, população, renda quando disponível, comparação territorial, índice explicável de potencial | CI-0/1; licença, geocodificação e limites territoriais validados | Critérios do PRD e avaliação com usuários do piloto | 3–5 |
| CI-3 — Inteligência do próprio negócio | Origem regional de clientes, mix de serviços, sazonalidade e horários de atendimentos próprios | Qualidade dos cadastros, vendas/financeiro, extração confiável | Tenant isolation, reconciliação e utilidade comprovadas | 2–4 |
| CI-4 — Benchmark da rede | Agregados por região/serviço/período com supressão e governança | Adesão suficiente, avaliação jurídica e testes de inferência | Nenhum recorte insuficiente; regras de publicação aprovadas | Reestimar após volume real |
| CI-5 — Integrações/expansão | Novas cidades, mobilidade e dados comerciais licenciados; premium escalável | Demanda paga, contratos, capacidade de operação | Margem e qualidade sustentáveis por cidade | Reestimar por integração |

CI-2 usa **potencial estimado**, não “demanda comprovada”. CI-3 mostra comportamento da própria operação, não todo o mercado local. CI-4 pode permanecer indisponível em Imperatriz por falta de contribuidores; não é requisito para lançar CI-2.

## Backlog novo

Os identificadores E01–E19 do PRD permanecem preservados. Acrescentar:

| Épico | Resultado | Prioridade inicial |
|---|---|---|
| E20 — Intelligence: descoberta e fontes | Pesquisa local, licenças e auditoria de dados | P1 |
| E21 — Geografia e mapa de mercado | Regiões versionadas, estabelecimentos e indicadores públicos | P2 |
| E22 — Análise territorial própria | Indicadores privados por tenant e unidade | P2 |
| E23 — Governança e benchmark | Adesão, supressão, retenção, controles e revisão de risco | P1 no desenho; P2 na execução |
| E24 — Oportunidades e explicações | Comparação de regiões, metodologia reproduzível, IA opcional | P2 |
| E25 — Comercialização Intelligence | Entitlements, limites, cidades e orçamento de dados | P2 |
| E26 — Fundação do ecossistema | Identidade profissional independente, perfis publicáveis e autorização por participantes | P1 no desenho; P2 na execução |
| E27 — Ei, Vet!: descoberta | Serviços, região e disponibilidade com validade | P2 |
| E28 — Ei, Vet!: demandas e comunicação | Solicitações, convites, conversas e propostas privadas | P2 |
| E29 — Ei, Vet!: contratação e operação | Confirmação bilateral, conclusão, cancelamento, contestação e suporte | P2 |
| E30 — Governança e evolução da rede | Verificação, denúncias, métricas, termos e expansão para especialistas | P1 no desenho; P2 na execução |

### Preparar nas próximas mudanças do core

- Separar identidade autenticada, perfil profissional e vínculo com clínica; profissional independente não recebe tenant fictício.
- Reservar contexto modular da rede e políticas por participantes, sem remover filtros dos repositories clínicos.
- Publicar perfis de clínicas/profissionais somente por projeções explícitas; não derivar disponibilidade de agendas privadas.
- Prever confirmação bilateral versionada, seleção única e idempotência nas demandas avulsas.

- Formalizar a localização da unidade e os campos estruturados de endereço dos tutores como evolução aditiva. Hoje o cadastro possui endereço textual; não tratar cadastros antigos como automaticamente geocodificáveis.
- Padronizar categorias de serviços com mapeamento dos catálogos de cada tenant. Evitar inferir categorias de textos clínicos ou nomes livres sem revisão.
- Definir semântica de indicadores: atendimento realizado, venda concluída, recebimento, cancelamento, estorno, moeda e fuso. Venda e recebimento são fatos diferentes.
- Desenhar extração incremental idempotente ou outbox transacional, inclusive correções e exclusões. A outbox é uma proposta, não infraestrutura já pronta.
- Reservar interfaces de consulta e fornecedores geográficos no Core. Controllers Adianti e ferramentas MCP consumirão services autorizados.
- Prever flags desativadas por padrão, limites, auditoria e separação de dados públicos, privados e agregados.

Essa preparação é backlog, não autorização para criar tabelas ou instalar serviços nesta atualização documental.

## Validação comercial do piloto

Proposta: entrevistar 8–12 proprietários/gestores de clínicas, pet shops e banho e tosa em Imperatriz, com negócios independentes e de múltiplas unidades. Registrar decisões recentes sobre expansão, campanhas e portfólio; fontes usadas; frequência; custo do erro; confiança em dados; e disposição de pagar. Validar com tarefas concretas de comparação de regiões, em vez de perguntar apenas se “gostariam de um mapa”.

Gate proposto após CI-0: pelo menos 6 entrevistados descrevem problema recorrente, pelo menos 3 aceitam testar um protótipo e existe custo de infraestrutura/dados compatível com a oferta. Esses números são critérios internos propostos, não resultados de pesquisa realizada. O gate comercial de lançamento exige teste com preço e compromisso real, a definir; interesse verbal não prova demanda paga.

Hipótese de oferta: complemento premium **Central Intelligence**, com mapa público e análise própria; benchmark disponibilizado somente onde houver elegibilidade. Separar módulos por feature/entitlement, sem amarrar regras a nomes de planos e sem alterar o carrinho ou preços agora. Preço e pacote dependem da validação.

## Decisões pendentes

**TODO:** responsável pelo piloto, participantes, capacidade da equipe, orçamento máximo mensal, fornecedor de mapa/geocodificação, disponibilidade de limites oficiais de bairros, condições contratuais das fontes, bases legais e responsabilidades de tratamento, metas comerciais e preço.

Decisão já definida pelo usuário: **Imperatriz/MA**. Decisões técnicas recomendadas estão na [ADR proposta](../../docs/architecture/adr/0004-central-intelligence-analytical-boundary.md). Requisitos e aceite estão no [PRD](../prd/central-intelligence.md); evidências e limitações, na [pesquisa](../research/central-intelligence-mercado-tecnologia.md).


### Pendências específicas do Ei, Vet!

**TODO:** cidade piloto, serviços iniciais, verificação dos registros profissionais, acesso e monetização, validade/limites dos convites, cancelamento/contestação, retenção e responsáveis por suporte. A visão de ecossistema e a finalidade do Ei, Vet! foram definidas pelo usuário; esses detalhes permanecem propostas ou pendências no PRD.
