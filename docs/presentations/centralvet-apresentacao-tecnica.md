# Central Vet Pro — Apresentação técnica

Data de referência: 04/10/2026. Conteúdo extraído do repositório local; sem nova homologação nesta entrega.

## 01. Central Vet Pro

Apresentação técnica • 04 de outubro de 2026

- Gestão clínica, operação e financeiro em uma jornada integrada.
- Visão de evolução: ecossistema com inteligência territorial e conexão entre clínicas e profissionais.
- Base: PRD v1.1, roadmap v1.3, código do Core, ADRs e registros de execução.

## 02. Como ler esta apresentação

Escopo e maturidade

- Existente: há código e/ou registros de execução no repositório. Isso não comprova homologação comercial integral.
- Parcial: a base existe, mas o escopo completo do PRD exige evolução ou validação.
- Planejado: requisito ou proposta documental; não é apresentado como funcionalidade disponível.
- Os resultados de testes citados são históricos. Esta entrega não executou uma nova homologação do sistema.

## 03. Três camadas do produto

Visão de ecossistema

- Gestão privada — agenda, pacientes, atendimento, prontuário, estoque, vendas e financeiro de cada organização.
- Ei, Vet! — rede planejada para localizar profissionais e negociar serviços avulsos.
- Central Intelligence — camada planejada de mapa de mercado, indicadores próprios e benchmark elegível.
- Participar da rede não concede acesso aos dados privados de outra clínica.

## 04. Pessoas e permissões

Requisitos do PRD • implantação por papel a validar

- Veterinário: atendimento, prontuário, prescrição, exames e evolução.
- Recepção: cadastros, agenda, fila e recebimentos autorizados.
- Auxiliar e gestor: procedimentos, estoque, financeiro e indicadores conforme permissão.
- Administrador da clínica: equipe, unidades, configurações e acessos.
- Administração SaaS: tenants, planos e suporte — sem bypass implícito do isolamento.

## 05. Jornada integrada

Núcleo existente • automações completas ainda em evolução

- Agenda → check-in/fila → atendimento → prontuário e conduta.
- Prescrição / exame / vacina / procedimento → documentos, materiais e conta.
- Conta → recebível → pagamento → caixa e lançamento financeiro.
- Retorno, comunicação e automações ampliadas fazem parte das próximas fases.
- Contexto do paciente e atendimento acompanha as ações, reduzindo recadastro.

## 06. Tutores, pacientes e serviços

Existente • Fase 1

- Cadastros de tutores e pacientes, listagens e formulários com pesquisa contextual.
- Catálogo de serviços e importação de serviços como apoio à operação.
- Resumo clínico e acesso contextual ao paciente; relações protegidas pelo tenant.
- Endereço estruturado, localização da unidade e geocodificação são evoluções previstas.
- O conjunto completo de campos e relações do PRD deve ser conferido na homologação.

## 07. Agenda e recepção

Existente • Fase 1 e navegação

- Agenda, agendamento e fila operacional com serviços dedicados.
- Regras de conflito e vínculo entre unidade, paciente e profissional.
- Entrada no atendimento a partir do fluxo da recepção.
- Buscas por nomes substituem IDs técnicos nos formulários ajustados.
- Visões, bloqueios, salas e recursos avançados do PRD requerem verificação específica.

## 08. Central de Atendimento e prontuário

Existente • Fase 2

- EncounterService organiza início, pausa, retomada e finalização do atendimento.
- Autosave do rascunho clínico, timeline e resumo clínico.
- Ações contextuais para prescrição, exames, vacinação, procedimentos e conta.
- Documentos do atendimento por serviço próprio.
- A interface de assistência IA existe com implementação Null; não comprova integração com um modelo.

## 09. Conduta e prescrições

Existente / parcial • Fases 2–3

- Prescrição vinculada ao contexto clínico, itens e templates de prescrição.
- PRD prevê medicamento, dose, via, frequência, duração, orientações e documentos.
- Diagnósticos e plano clínico estruturado integram a visão do atendimento.
- Alertas clínicos avançados, assinatura e toda a automação do plano precisam de homologação própria.
- Organização por IA e ditado por voz permanecem na trilha de evolução.

## 10. Exames e vacinação

Existente • Fase 3

- Catálogo de exames, solicitações, registro de resultados e consulta de pendências.
- Catálogo de vacinas, protocolos, aplicações, histórico e carteira vacinal.
- Formulários contextuais preservam paciente e atendimento.
- Lotes, validade, próxima dose, alertas e baixa automática devem ser validados por cenário.
- Integração automatizada com laboratórios e lembretes pertence ao roadmap.

## 11. Procedimentos, estoque e PDV

Existente • Fase 4

- Catálogo de procedimentos com insumos e registro de execução.
- Produtos, lotes, movimentos de estoque, recebimento e consumo.
- Vendas com carrinho de produtos/procedimentos e contexto opcional de paciente/tutor.
- Regras de saldo e exceção para estoque insuficiente presentes no domínio.
- Compras, transferências, inventário e alertas ampliados são requisitos a validar ou evoluir.

## 12. Financeiro integrado

Existente • Fase 5

- Conta do atendimento com itens automáticos de exames/procedimentos e itens manuais.
- Desconto com ação de permissão própria; fechamento cria recebível.
- Pagamentos parciais, controle de saldo e recusa de pagamento acima do devido.
- Abertura e fechamento de caixa; totais por forma de pagamento.
- Contas a pagar, lançamentos, contas bancárias, visão financeira e recebíveis pendentes.
- Venda e recebimento são fatos distintos: não presumir integração financeira total do PDV.

## 13. Busca, documentos e administração

Existente / parcial

- Controlador de busca global e atalhos contextuais; catálogo completo do PRD ainda exige validação.
- Documentos clínicos e bibliotecas de PDF, planilhas, códigos de barras e QR code.
- Adaptador de storage S3-compatible; disponibilidade depende da configuração do ambiente.
- Template Adianti fornece administração, grupos, programas, logs e recursos de comunicação.
- Mensagens do template não equivalem ao CRM clínico e às automações de follow-up previstas.

## 14. Landing e comercialização

Landing existente • operação SaaS planejada

- Landing com planos, comparativo, FAQ, seleção de plano e formulário de ativação/leads.
- Português, inglês e espanhol; preferência local e preços formatados em BRL.
- Captação, listagem administrativa e exportação de leads, conforme configuração.
- Criar conta conduz ao carrinho; isso não comprova provisionamento automático ou pagamento.
- Billing, trial, quotas, entitlements, SSO e governança enterprise pertencem à Fase 9.

## 15. Arquitetura do Core

Monólito modular • ADR 0001

- Interface Adianti / TPage → serviços Application → entidades e contratos Domain → persistência e adapters.
- Namespace CentralVet via PSR-4 em src/app/Core.
- Services e repositories independentes de TPage; regras reutilizáveis em adapters futuros.
- MySQL mantém a persistência operacional; Redis e storage entram por contratos.
- REST, workers e MCP são caminhos de exposição previstos; não assumir catálogo público já disponível.

## 16. Dados e integridade

Modelo operacional existente

- Tenant → unidades e usuários; tutores → pacientes → agenda/fila → atendimentos.
- Atendimento → prescrições, exames, vacinas, procedimentos e conta.
- Produtos → lotes → movimentos; vendas → itens.
- Conta → itens → recebível → pagamentos; caixa e lançamentos organizam a operação financeira.
- Repositories aplicam escopo tenant; chaves estrangeiras e validações reforçam integridade.
- Migrations são versionadas; evolução de schema é separada da publicação de código.

## 17. Isolamento e segurança

Fundação existente • ADR 0002

- TenantContext vem da sessão autenticada; contexto ausente ou inválido interrompe a operação.
- Consultas tenant-aware começam pelo filtro obrigatório da organização.
- RBAC e contexto de unidade protegem ações; parâmetros do cliente não concedem identidade ou privilégios.
- Limitação de login, CSRF, sessões e logs compõem os mecanismos disponíveis.
- No Docker: filesystem somente leitura, processo sem privilégios e downloads autenticados.
- Políticas completas de MFA, revogação, auditoria e uploads exigem validação por ambiente.

## 18. Ambientes e implantação

Dois perfis documentados

- Local: Docker Compose com PHP 8.4-FPM, Adianti 8.6, Nginx, MySQL 8 e Redis 7.
- Online: hospedagem compartilhada com MySQL 5.7 e adaptação revisada de migrations.
- Sessão e rate limit por arquivos são opções explícitas para servidor único sem Redis.
- Filas, registro distribuído de sessões e tokens da landing continuam exigindo infraestrutura própria.
- Runbook online registra instalação de migrations 0001–0009 e seed de 45 controladores em 02/10/2026.
- Multi-instância e escala horizontal dependem de serviços compartilhados e homologação.

## 19. Operação e qualidade

Evidências e limites

- Health checks /live e /health, logs estruturados, correlation_id e adapters de observabilidade.
- Backup lógico MySQL e runbooks de restauração, migrations, rollback e ambientes.
- Pipeline documentado de build e testes; publicação automática não é comprovada.
- Registros da navegação citam 155/155 testes históricos e validações de fluxos clínicos/financeiros.
- Runbook online registra dez telas abertas sem erros após configuração de permissões.
- Criptografia externa de backup, PITR, RPO/RTO e teste de recuperação são metas a operacionalizar.

## 20. Cirurgia, internação e comunicação

Planejado • Fases 6–7

- Cirurgia: agenda, equipe, sala, checklists e etapas pré, intra e pós-operatórias.
- Internação: admissão, leitos, prescrições internas, parâmetros, evolução, flowboard e alta.
- Comunicação: consentimentos, templates, WhatsApp/e-mail, inbox e histórico de envio.
- Automações: confirmação, retorno, vacinas, cobrança, resultado e documentos.
- Pendências e relatórios ampliados devem reunir prioridade, responsável, prazo e links contextuais.

## 21. IA contextual e Central Vet MCP

Planejado • Fases 8A–8C

- AI Gateway desacopla fornecedor, prompts e limites de uso.
- Copiloto: organizar anamnese, resumir paciente/exame, busca natural e rascunhos de comunicação.
- MCP oferece ferramentas autorizadas que chamam os serviços; o modelo não acessa SQL diretamente.
- Fluxo proposto: usuário → UX → Gateway/orquestrador → MCP → autorização → serviços → dados.
- Primeiro ferramentas de leitura; depois ações graduais, auditáveis e com confirmação conforme política.
- Sugestões clínicas são revisadas pelo profissional antes da persistência.

## 22. Central Intelligence

Planejado • piloto definido em Imperatriz/MA

- Mapa público de oferta veterinária/pet, contexto demográfico, fontes, datas e cobertura.
- Filtros por categoria, comparação de até três regiões e potencial territorial estimado.
- Visão própria: origem regional de clientes, mix de serviços, sazonalidade e horários observados.
- Benchmark: agregados elegíveis, adesão, supressão e proteção contra inferência.
- Escopos separados: public_market, own_business e network_benchmark.
- Potencial demográfico não comprova demanda; PostGIS e bibliotecas de mapas são candidatos futuros.

## 23. Ei, Vet!

Planejado • rede de serviços avulsos

- Identidade profissional independente da assinatura de uma clínica.
- Perfil, serviços, região e disponibilidade declarada com validade.
- Clínica busca → cria demanda → convida → conversa → negocia proposta → confirma com o profissional.
- Confirmação bilateral da mesma versão; seleção única, idempotência e controle de concorrência.
- Conclusão, cancelamento, contestação, denúncias e suporte com histórico.
- Chat inicial por polling; região piloto, verificação, preço e políticas ainda pendentes.

## 24. Fronteiras do ecossistema

ADRs 0004–0005 • propostas

- ERP privado, dados públicos de mercado e colaboração da rede têm políticas próprias.
- Profissional sem clínica não recebe tenant fictício nem leitura global do ERP.
- Conversas e propostas são privadas entre clínica e convidado; outros convidados não têm acesso.
- Perfil publicado é projeção explícita; não expõe agenda, prontuário ou financeiro.
- Pipeline analítico e rede devem falhar sem interromper o atendimento.
- Mensagens e dados clínicos não alimentam automaticamente a Intelligence.

## 25. Roadmap e critérios de lançamento

Sequência de entrega

- Consolidar Fases 0–5: validar clínica, financeiro, permissões e operação online.
- Fases 6–7: cirurgia, internação, comunicação e automações.
- Fases 8A–8C: Gateway, MCP de leitura e ações controladas.
- Fase 9: onboarding, billing, planos/limites, hardening e recuperação.
- Intelligence: descoberta → arquitetura → mapa → visão própria → benchmark → expansão.
- Ei, Vet!: validação → identidade/rede → negociação → piloto → expansão. Sem datas contratadas.

## 26. Matriz de recursos do PRD

Cobertura resumida • referência para homologação

- Existente/parcial: core multi-tenant; autenticação; unidades/equipes; tutores; pacientes; agenda/fila; atendimento; prontuário; diagnóstico/plano; prescrições; exames; vacinação.
- Existente/parcial: procedimentos; estoque; vendas; financeiro; documentos; busca; auditoria; indicadores e exportações pontuais.
- Planejado/ampliado: cirurgia; internação; compras avançadas; CRM clínico; Central de Pendências completa; BI e relatórios completos; administração SaaS comercial.
- Planejado: IA contextual em produção; servidor MCP; agentes; Central Intelligence; Ei, Vet!.
- A nomenclatura acompanha as 26 áreas funcionais do PRD, com os complementos do roadmap.

## 27. Fontes e próximos passos

Rastreabilidade técnica

- PRD_Central_Vet_Pro_v1_1_MCP.docx — visão, 26 domínios, IA/MCP e requisitos de lançamento.
- .docs/plan/centralvet-roadmap.md e .docs/prd/ — ecossistema, Intelligence e Ei, Vet!.
- docs/architecture/adr/ — fronteiras e decisões arquiteturais.
- src/app/Core/ e src/app/control/clinic/ — evidência de código.
- .tasks/02–09 e docs/runbooks/ — registros históricos de execução e implantação.
- Próximo passo técnico: homologação ponta a ponta, por papel e ambiente, com matriz de aceite do PRD.
