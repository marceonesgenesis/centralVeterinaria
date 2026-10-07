# PRD — Ei, Vet!

> Módulo de conexão da Central Vet Pro para clínicas encontrarem veterinários
> disponíveis e combinarem serviços avulsos dentro da plataforma. Atende especialmente
> clínicas que ainda não conseguem manter um plantonista, preservando seu ambiente privado.

Versão 0.1 · 02/10/2026 · Status: especificação proposta, não implementada.

## 1. Visão geral

A Central Vet Pro é um ecossistema veterinário integrado: gestão privada de cada clínica, conexão entre participantes e inteligência de mercado. O Ei, Vet! é a ferramenta de conexão para demandas avulsas, atendimentos pontuais e especialistas. Participar da rede não abre agenda interna, prontuários, tutores, estoque ou financeiro para outros participantes.

A clínica procura profissionais por região, serviço e disponibilidade, abre uma solicitação e conversa com os convidados. Um veterinário mantém perfil próprio, informa disponibilidade, responde e combina escopo, horário e valor. Um serviço confirmado possui participantes explícitos e histórico. A identidade profissional pode existir sem o veterinário possuir uma clínica ou assinatura do ERP; condições comerciais desse acesso ainda precisam ser definidas.

**Propostas para o MVP:** atendimento presencial, convite direcionado e negociação com confirmação bilateral; disponibilidade declarada, com validade, sem promessa de resposta imediata. Não há despacho automático nem garantia de atendimento emergencial. Estas escolhas são recomendações de escopo, sujeitas à validação.

## 2. Objetivos e limites

- Reduzir o tempo entre uma demanda da clínica e a confirmação de um profissional.
- Permitir comunicação e negociação dentro da plataforma, com rastreabilidade.
- Dar ao veterinário autonomia para definir serviços, região e disponibilidade.
- Preparar a rede para especialistas, encaminhamentos e outros serviços futuros.

Pagamento intermediado, comissão, avaliações públicas, teleatendimento, credenciamento automatizado e compartilhamento de prontuário completo ficam fora do MVP proposto. A gestão clínica continua responsável pelo registro do atendimento; o chat não substitui prontuário.

## 3. Personas e permissões

| Participante | Ações | Limite |
|---|---|---|
| Gestor/colaborador autorizado da clínica | Procurar, convidar, negociar, confirmar e acompanhar serviços | Organização e unidades autorizadas |
| Veterinário independente | Editar perfil, disponibilidade, responder convites e executar serviços | Identidade própria e solicitações das quais participa |
| Veterinário vinculado a clínicas | Atuar como profissional ou colaborador | Contextos separados e troca explícita de atuação |
| Operador da rede | Verificar perfis e tratar denúncias | Acesso específico e auditado, sem acesso automático ao ERP |

Permissões propostas: `eivet.profile.manage`, `eivet.search`, `eivet.request.manage`, `eivet.request.respond`, `eivet.message.send` e `eivet.moderation.manage`. Mapear para RBAC real antes da implementação. Entitlement e autorização são verificações distintas.

## 4. Fluxo principal

1. Veterinário cadastra nome profissional, registro declarado, serviços, região e perfil visível na rede; informa janela de disponibilidade.
2. Clínica autorizada abre “Ei, Vet!”, filtra profissionais e consulta a data da última atualização e o status de verificação do perfil.
3. Cria demanda com unidade, serviço, resumo sem dados identificáveis do paciente/tutor, janela de atendimento e condição comercial proposta.
4. Convida um ou mais profissionais. Cada convite inicia uma conversa privada entre clínica e convidado; concorrentes não veem mensagens ou propostas uns dos outros.
5. Veterinário aceita conversar, recusa ou propõe condições. A proposta registra valor, moeda, horário e escopo.
6. Ambas as partes confirmam a mesma versão dos termos; o servidor reserva um único profissional e encerra os demais convites.
7. Os participantes acompanham chegada, início e conclusão; a clínica confirma a entrega ou registra contestação. Cancelamentos registram autor e motivo.
8. O registro clínico permanece no ERP da clínica. Qualquer acesso clínico futuro exige concessão própria, limitada e auditada.

## 5. Requisitos funcionais e telas

- **Encontrar veterinário:** filtros por município/região, serviço e janela; lista paginada; perfil com registro declarado, verificação e disponibilidade. Não mostrar localização pessoal em tempo real.
- **Meu perfil profissional:** serviços, região atendida, resumo, visibilidade e disponibilidade. Contatos pessoais não ficam públicos por padrão.
- **Nova demanda:** formulário com unidade autorizada, escopo, prazo e termos; alerta para não publicar dados de tutores/pacientes.
- **Minhas demandas / Meus serviços:** separação entre atuação pela clínica e pelo profissional; filtros por estado; detalhe com histórico e participantes.
- **Conversa do convite:** mensagens textuais e propostas estruturadas; confirmação exige versão vigente. Anexos ficam para etapa posterior com política própria.
- **Notificações:** convite, mensagem, proposta, confirmação e cancelamento; conteúdo sensível não aparece em prévias. Falha de notificação não altera o estado do serviço.
- **Denúncia e bloqueio:** motivos e trilha auditada; bloqueio impede novos convites. Serviços em andamento conservam canal mínimo de resolução com suporte autorizado.

Estados de interface: carregando, lista vazia, nenhuma disponibilidade, perfil pendente de verificação, convite vencido, profissional indisponível, termos alterados, conflito de confirmação e falha temporária. A interface usa o design existente e funciona em celular.

## 6. Modelo de dados proposto

Entidades futuras, sem migrations nesta entrega. IDs públicos opacos; nomes físicos serão definidos no desenho técnico.

| Entidade | Campos e responsabilidade |
|---|---|
| `NetworkProfessional` | `id`, `user_id`, nome profissional, registro/UF declarados, `verification_status`, visibilidade, região, timestamps; identidade global sem tenant fictício |
| `NetworkClinicProfile` | `id`, `tenant_id`, unidades publicadas, nome e região; projeção autorizada da clínica |
| `ProfessionalService` | profissional, categoria de serviço e descrição; catálogo da rede separado do catálogo privado |
| `AvailabilityWindow` | profissional, início/fim UTC, fuso, região, `expires_at`, estado; sem copiar agenda privada |
| `ServiceRequest` | `id`, `clinic_tenant_id`, `unit_id`, criador, categoria, resumo mínimo, janela, estado, selecionado, `version` |
| `ServiceInvitation` | demanda, profissional, estado, expiração; único por demanda/profissional |
| `ServiceProposal` | convite, autor, versão, escopo, horário, `amount_minor`, moeda, validade e confirmações das partes |
| `NetworkConversation` / `NetworkMessage` | convite, remetente, texto, timestamps; clínica autorizada e convidado são participantes |
| `NetworkAuditEvent` | recurso, ator, contexto, ação, versão, timestamp; sem replicar textos sensíveis em logs |
| `NetworkReport` / `NetworkBlock` | denunciante, recurso/alvo, motivo, tratamento ou bloqueio |

A proposta confirmada é preservada como snapshot. Valores usam unidades monetárias inteiras; ausência de valor significa “a combinar”, nunca gratuidade implícita.

## 7. Estados e regras de negócio

`ServiceRequest`: `draft → open → confirmed → in_progress → awaiting_completion → completed`. `draft/open → cancelled`; `open → expired`; `confirmed/in_progress/awaiting_completion → cancelled` exige motivo e política aplicável. `awaiting_completion → disputed → completed/cancelled` exige resolução auditada. A política de contestação é pendente; não lançar esse fluxo sem definição.

`ServiceInvitation`: `pending → negotiating/declined/expired/withdrawn`; `pending/negotiating → selected` somente após confirmação bilateral. Quando há seleção, os demais convites ativos ficam `closed`.

- Somente a clínica abre ou cancela a demanda; o profissional pode recusar convite ou solicitar cancelamento do serviço, conforme política a definir.
- Uma demanda tem no máximo um profissional selecionado no MVP. Confirmação ocorre atomicamente; concorrência retorna conflito, sem dupla contratação.
- Termos alterados geram nova versão e invalidam confirmações anteriores. Proposta vencida não pode ser confirmada.
- Disponibilidade é indicação declarada, não reserva. A confirmação revalida janela e serviços já confirmados pelo Ei, Vet!, prevenindo sobreposição nesse módulo.
- Perfil oculto não recebe novos convites; serviços existentes permanecem acessíveis aos participantes.
- Verificação pendente é exibida como tal; registro declarado não aparece como registro verificado. **TODO:** definir processo de verificação e se será obrigatório antes de receber convites.
- Estado, autor, tenant, verificação e participantes são determinados no servidor.

## 8. Contratos de API propostos

Contratos aditivos a validar com os adapters PHP/Adianti; não pressupõem REST já implementado. Autenticação obrigatória; clínica usa contexto tenant resolvido no servidor e profissional usa identidade autenticada da rede.

| Método e rota | Contrato principal e autorização |
|---|---|
| `GET /api/v1/ei-vet/professionals?city=Imperatriz&service=clinical_support&available_from=...&cursor=...` | Projeções visíveis na rede; paginação e filtros limitados |
| `PUT /api/v1/ei-vet/me/profile` | Profissional edita apenas o próprio perfil |
| `POST /api/v1/ei-vet/me/availability` | Janela própria, com validade |
| `POST /api/v1/ei-vet/requests` | Clínica autorizada; unidade pertencente ao tenant |
| `GET /api/v1/ei-vet/requests` | Demandas da clínica ativa ou convites do profissional conforme contexto de atuação |
| `POST /api/v1/ei-vet/requests/{id}/invitations` | Clínica autora convida perfil elegível |
| `GET /api/v1/ei-vet/invitations/{id}/messages` | Participante, com paginação |
| `POST /api/v1/ei-vet/invitations/{id}/messages` | Participante ativo; texto limitado |
| `POST /api/v1/ei-vet/invitations/{id}/proposals` | Participante ativo; nova versão de termos |
| `POST /api/v1/ei-vet/proposals/{id}/confirmations` | Cada parte confirma a versão; segunda confirmação seleciona atomicamente |
| `POST /api/v1/ei-vet/requests/{id}/transitions` | Ação e versão esperada, validadas por estado e papel |
| `POST /api/v1/ei-vet/reports` | Participante denuncia recurso acessível |

Exemplo de criação:

```json
{"unit_id":12,"service_category":"clinical_support","summary":"Apoio presencial para demanda avulsa","starts_at":"2026-10-10T17:00:00Z","ends_at":"2026-10-10T19:00:00Z","timezone":"America/Fortaleza"}
```

Resposta:

```json
{"id":"req_example","status":"draft","version":1}
```

Criação nasce em rascunho; o comando `publish` na rota de transições abre a demanda após validar seus campos. Confirmação recebe `{"proposal_version":2,"request_version":3}`. Comandos repetíveis usam `Idempotency-Key` e versão esperada. Erros: `401` sem autenticação, `403` sem permissão funcional, `404` para recurso fora do escopo, `409` para conflito de versão/seleção e `422` para entrada ou transição inválida. Nunca aceitar `tenant_id`, remetente ou profissional selecionado arbitrários do cliente.

## 9. Privacidade, arquitetura e operação

Contexto `Network/EiVet` separado dos repositórios clínicos. Descoberta usa projeções publicadas voluntariamente; demandas/conversas exigem clínica autora ou profissional explicitamente convidado. Um vínculo na rede não cria membership no tenant e um usuário autenticado não possui leitura global das demandas.

Profissionais sem clínica usam contexto de identidade dedicado, sem relaxar `TenantContext` do ERP. Acesso clínico futuro será uma concessão explícita por atendimento, campos, finalidade e validade, com revogação; nenhum endpoint do MVP lê prontuários.

Services e repositories seguem ADR 0001, independentes de Adianti. Primeiro deployment deve ser compatível com PHP/MySQL 5.7 da hospedagem: chat com polling incremental e expirações por cron ou verificação no comando, sem exigir WebSocket, Redis ou worker persistente. Transições em transações curtas e entrega de notificações desacoplada. Falhas do Ei, Vet! não bloqueiam agenda e atendimento.

Validar tamanho de texto, datas, fuso, região, unidade, serviço e limites de convites. Rate limiting por ator e ação; URLs/HTML de mensagens tratados como texto seguro. Cache privado inclui ator, contexto e autorização. Nenhuma mensagem, demanda ou localização privada alimenta automaticamente a Intelligence.

**TODO:** termos, responsabilidades, retenção de mensagens/auditoria, tratamento de denúncias e exclusão de contas, com revisão especializada antes do lançamento. Não se presume regra jurídica ou profissional validada nesta especificação.

## 10. Critérios de aceite

- [ ] Veterinário sem tenant clínico consegue manter perfil e disponibilidade sem acessar o ERP.
- [ ] Clínica encontra perfis visíveis por região, serviço e janela, com validade/verificação explícitas.
- [ ] Unidade de outro tenant não pode ser usada para criar demanda.
- [ ] Clínica não participante e profissional não convidado não leem demanda, proposta ou conversa, mesmo com ID conhecido.
- [ ] Convidados da mesma demanda não leem propostas/mensagens de outros convidados.
- [ ] Duas confirmações concorrentes resultam em um único selecionado; reenvio idempotente não duplica convite, mensagem ou serviço.
- [ ] Alteração dos termos exige nova confirmação das duas partes; termos expirados são rejeitados.
- [ ] Expiração e indisponibilidade não são exibidas como disponibilidade atual.
- [ ] Serviço confirmado não se sobrepõe a outro serviço confirmado do profissional no módulo.
- [ ] Notificação com falha permite recuperação sem perder a confirmação.
- [ ] Conexão na rede não permite ler prontuários, tutores, agenda interna ou financeiro.
- [ ] Fluxo responsivo cobre estados vazios, conflitos, cancelamento e conclusão com auditoria.

## 11. Métricas e roadmap

Medir clínicas/profissionais ativos por região, perfis elegíveis, tempo até primeira resposta e confirmação, proporção de demandas confirmadas/concluídas/expiradas, cancelamentos e contestações. Metas dependem do piloto; não há promessa de SLA nesta etapa.

| Etapa | Entrega | Condição de avanço |
|---|---|---|
| EV-0 — Validação | Região piloto, entrevistas, processo de verificação, termos, negociação e suporte | Fluxo e responsabilidades definidos |
| EV-1 — Fundação da rede | Identidade profissional, projeção da clínica, autorização por participantes e disponibilidade | Testes de isolamento e identidade independente |
| EV-2 — Serviço avulso | Busca, demanda, convite, chat, proposta e confirmação bilateral | Concorrência, idempotência e notificações verificadas |
| EV-3 — Operação piloto | Conclusão, cancelamento, contestação, denúncias e métricas | Operação de suporte e políticas prontas |
| EV-4 — Expansão | Especialistas, encaminhamentos e integrações opcionais | Evidência do piloto e especificações próprias |

**TODO:** cidade piloto do Ei, Vet! (Imperatriz é definida apenas para a Intelligence), serviços iniciais, orçamento, preço/comissão, acesso gratuito ou pago para profissionais, responsáveis por suporte/verificação, limites e validade dos convites e política de cancelamento/contestação. Não há datas ou preços aprovados.

Referências: [roadmap](../plan/centralvet-roadmap.md), [arquitetura do ecossistema](../../docs/architecture/adr/0005-veterinary-network-boundary.md).
