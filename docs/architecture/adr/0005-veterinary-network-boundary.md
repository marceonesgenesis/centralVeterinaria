# ADR 0005: Ecossistema veterinário e fronteira da rede Ei, Vet!

- Status: proposta
- Data: 2026-10-02
- Referências: [PRD Ei, Vet!](../../../.docs/prd/ei-vet.md), [roadmap](../../../.docs/plan/centralvet-roadmap.md), ADRs 0001, 0002 e 0004

## Contexto

A direção definida para a Central Vet Pro é conectar clínicas, veterinários, especialistas e serviços, preservando o ambiente privado de cada clínica. O Ei, Vet! atende a contratação de serviços avulsos, especialmente quando a clínica ainda não consegue manter um plantonista. Essa colaboração não pode ser implementada removendo filtros tenant dos repositórios existentes.

## Decisão proposta

Manter três contextos com contratos explícitos: operação clínica privada, rede de colaboração e inteligência territorial. A rede começa como módulo do monólito PHP, respeitando a separação Application/Domain/Infrastructure/Presentation da ADR 0001. Não exige microserviços nem novo banco para o MVP.

Separar identidade autenticada, perfil profissional da rede e membership de organização. Uma pessoa pode atuar como profissional independente e como colaborador de uma ou mais clínicas, mas cada ação declara um contexto que o servidor autoriza. `TenantContext` continua obrigatório no ERP; o profissional independente recebe contexto de identidade próprio no módulo da rede, sem tenant artificial e sem fallback de escopo.

Repositórios da rede não reutilizam consultas clínicas sem escopo. A descoberta lê apenas perfis/projeções publicados voluntariamente. A autorização de demanda combina `clinic_tenant_id`, unidade autorizada, permissão funcional e identidade explicitamente convidada. Conversas e propostas são restritas à clínica autora e ao profissional do respectivo convite; profissionais concorrentes não compartilham conversa. Operadores recebem permissões específicas e auditadas para suporte, sem bypass do ERP.

Não confiar em `tenant_id`, `user_id`, remetente ou papéis enviados pelo navegador. Listagens e carregamento por ID aplicam a mesma política. IDs opacos reduzem enumeração, mas nunca substituem autorização. Cache e downloads futuros também aplicam essa política.

Uma confirmação bilateral registra uma versão imutável dos termos e seleciona um único profissional dentro de transação. Idempotência e concorrência são requisitos de domínio. Para prevenir sobreposição de serviços confirmados, serializar confirmação por profissional e revalidar reservas em transação; checar disponibilidade antes da transação não é suficiente. Notificações têm entrega recuperável, sem determinar sucesso da contratação.

Disponibilidade da rede é declarada e expira. Não publicar a agenda interna nem inferir localização em tempo real. Serviços e categorias da rede podem mapear catálogos privados por adapters explícitos, sem tornar preços internos públicos.

Conexão profissional não concede acesso clínico. Caso futuro exija compartilhar dados de um atendimento, especificar concessão limitada por recurso/campo, destinatário, finalidade, validade e revogação. O acesso acontece por serviço autorizado da clínica; nunca por membership global ou consulta cross-tenant genérica. O MVP não inclui essa concessão.

A Intelligence não recebe mensagens, demandas ou dados clínicos da rede automaticamente. Qualquer indicador derivado precisa de contrato, finalidade e governança próprios, conforme ADR 0004.

## Operação e alternativas

O ambiente compartilhado PHP/MySQL 5.7 favorece polling incremental paginado para mensagens e cron para notificações/expirações, com validação de expiração também no comando. Workers persistentes, Redis, WebSocket e PostGIS não são dependências iniciais da rede. Jobs são idempotentes e isolados da operação clínica.

- Reutilizar membership clínico para todo profissional simplificaria login, mas concederia escopo indevido e impediria participação independente.
- Abrir repositórios tenant-aware para a rede enfraqueceria a ADR 0002; projeções publicadas e autorização por participantes permitem conexão com limites explícitos.
- Extrair a rede para serviço remoto agora acrescentaria operação antes da validação; os contratos modulares permitem avaliar essa extração futuramente.

## Consequências e condições de aceite

Novos testes precisam cobrir identidade sem tenant, troca de contexto, IDs de outra organização, convites concorrentes, privacidade entre convidados, propostas alteradas e dupla confirmação. O core privado permanece fail-closed.

Antes de implementar, definir RBAC real, verificação profissional, política de cancelamento/contestação, retenção, suporte, termos e região piloto. Preparar migrations aditivas separadamente. Esta ADR registra direção arquitetural: não cria tabelas, permissões, contas ou integrações e não modifica dados.
