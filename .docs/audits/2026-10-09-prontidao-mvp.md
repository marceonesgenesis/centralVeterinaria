# Avaliação de prontidão do primeiro MVP — Central Vet Pro

Data: 09/10/2026. Escopo: código do workspace, configuração Docker/Nginx, dependências Composer, testes unitários, documentação operacional e fluxos de contratação/PDV/financeiro.

## Parecer

**Não liberar ainda o lançamento público com contratação e cobrança automáticas.** O produto tem uma base clínica substancial, mas a landing é um captador de leads, e o ciclo comercial de assinatura não está implementado. Há também bloqueios de segurança e correção financeira.

Um piloto assistido com poucas clínicas pode ser o primeiro marco, **depois de corrigir os bloqueios de segurança e validar os fluxos operacionais incluídos no piloto**. Nesse modelo, contratação, provisionamento e cobrança são assistidos e isso precisa estar explícito na oferta. Ele não atende sozinho ao objetivo de compra automatizada solicitado.

Não atribuo percentual de conclusão: quantidade de telas ou testes não mede prontidão comercial. Recursos encontrados no código são classificados como implementados, não como homologados em produção.

## Evidência e limites da avaliação

- Stack local: app, worker, Nginx, MySQL, Redis e MinIO em execução e saudáveis. `/health` respondeu `status: ok`, com MySQL e Redis disponíveis; a landing respondeu HTTP 200 com CSP e demais headers de proteção.
- **Código atual: 1.002 testes unitários passaram, zero falhas e zero skips**, em PHP 8.4.13, usando a imagem da aplicação, o workspace montado somente para leitura e rede desabilitada. Foi usado um runner temporário derivado de `src/tests/run.php`, limitado à suíte Unit, sem os preflights e testes que acessam bancos reais.
- A imagem já em execução passou 999 testes unitários, mas há seis arquivos diferentes entre imagem e workspace nos diretórios comparados (`app/Core`, `tests`, `app/control/clinic`). A repetição isolada acima validou o código atual. A diferença precisa ser considerada ao homologar uma release.
- A execução inicial no PHP 8.3 do host teve quatro falhas e 29 skips por diferenças de ambiente; não foi usada como resultado de aprovação da aplicação. O teste em PHP 8.4 resolveu essas diferenças.
- `composer audit --locked --no-interaction --format=json` retornou **sete avisos em dois pacotes**, sem pacotes abandonados. Detalhes abaixo.
- Não executei migrations, seeds, pagamentos, disparos de comunicação, gravações SQL nem a suíte de integração com bancos. Não alterei o código funcional ou as alterações que já estavam staged.
- Não houve teste autenticado completo pelo navegador, simulação de concorrência real ou teste de um gateway de pagamentos. Exploração das lacunas de segurança não foi executada. Hospedagem pública, TLS, SMTP, restauração e credenciais de produção não foram homologados.

## Situação por área

| Área | O que existe | O que falta para o lançamento pretendido |
| --- | --- | --- |
| Landing e carrinho de planos | Catálogo com preços no servidor, seleção de um plano, persistência local, formulário, consentimento de contato e armazenamento do lead | Checkout, pedido, assinatura, confirmação de pagamento e ativação automática |
| Recorrência da assinatura SaaS | Não foi encontrado domínio de assinatura nem integração de cobrança no código próprio | Cliente no gateway, assinatura, faturas, renovação, falha de cobrança, cancelamento e recuperação |
| Baixa financeira | Registro manual de recebimento, pagamento parcial, alteração do saldo e lançamento financeiro | Confirmação externa autenticada, conciliação, deduplicação e tratamento de estorno |
| PDV | Carrinho de produtos/procedimentos, cálculo no servidor, venda, baixa de estoque e recibo PDF | Corrigir preço, fechar vínculo com recebimento, impedir duplicidade/concorrência e validar referências |
| Clínica | Tutores, pacientes, agenda, fila/atendimento, prontuário, prescrições, exames, vacinação e documentos | Homologação por perfil, onboarding real e evidência da jornada completa |
| Operação avançada | Código de internação, cirurgia, comunicação e IA | Validação operacional; não precisa bloquear o MVP se ficar fora da oferta inicial |
| Segurança | Hash de senha, limitação de login, contexto de tenant, RBAC, controles de upload e isolamento Docker | Fechar cadastro legado, proteção consistente de mutações, recuperação de senha, revisão de arquivos e dependências |
| Continuidade | Scripts de dump/restore e documentação | Backup externo de banco e arquivos, retenção, restauração ensaiada e alertas |

## Bloqueios de segurança e integridade

### S1 — Cadastro público legado continua acessível — prioridade P0

Evidência: `src/app/config/application.php:50` mantém `SystemRegistrationForm` entre as classes públicas, com `user_register = 1`, grupo padrão 2 e unidade padrão 1. O botão no login foi redirecionado à landing, mas isso não remove o endpoint legado. `SystemRegistrationForm::onSave()` cria usuário ativo, aplica `$object->fromArray($param)` e atribui grupos/unidades padrão; não cria tenant nem vínculo `tenant_user`.

Impacto: caminho alternativo de cadastro, contas sem onboarding de clínica e atribuição de unidade compartilhada. A atribuição ampla de campos do payload é uma superfície adicional a revisar; não foi comprovada escalada de privilégio nesta avaliação.

Necessário: desabilitar o cadastro legado no piloto ou substituí-lo pelo onboarding controlado; usar lista explícita de campos permitidos; criar tenant, unidade, usuário e permissões de forma consistente. Aceite: chamada direta ao cadastro antigo não cria conta; um onboarding válido cria apenas vínculos da própria clínica.

### S2 — Proteção CSRF não é aplicada de forma consistente — prioridade P0

Evidência: o framework inicia `csrf_validation = false`. Só encontrei `enableCSRFProtection()` nos formulários de perfil e 2FA da aplicação. `PaymentForm::onSave()` lê os parâmetros sem validação CSRF ou exigência explícita de POST; `SaleForm` valida campos, mas não habilita CSRF. `engine.php` usa `$_REQUEST`. A troca de unidade tem proteção própria, e a landing tem token/origem: esses controles não cobrem os demais formulários.

Impacto: mutações autenticadas sem uma barreira consistente contra requisições forjadas, inclusive em rotas que recebem parâmetros por GET. `SameSite=Lax` ajuda, mas não substitui essa proteção.

Necessário: inventariar as ações que gravam dados; exigir POST e token válido, incluindo ações de grid, exclusão, pagamento, cancelamento e alterações administrativas. Aceite: GET ou POST sem token não muda estado; formulário válido continua funcionando. Referência: [OWASP CSRF Prevention](https://cheatsheetseries.owasp.org/cheatsheets/Cross-Site_Request_Forgery_Prevention_Cheat_Sheet.html).

### S3 — Recuperação de senha precisa ser endurecida — prioridade P0

Evidência: `SystemRequestPasswordResetForm::onRequest()` constrói a URL a partir de `HTTP_REFERER`, diferencia usuário existente/inexistente e não usa o limitador de tentativas. O JWT inclui usuário e expiração de três horas; `SystemPasswordResetForm::onReset()` verifica a assinatura/expiração, mas não registra consumo único do token.

Necessário: URL pública canônica configurada, resposta uniforme, limite por IP/conta, token de uso único com expiração e invalidação após utilização. Rever revogação de sessões após reset. Aceite: o link sempre aponta ao domínio configurado; token consumido não pode ser reutilizado; tentativas excessivas são limitadas. Referência: [OWASP Forgot Password](https://cheatsheetseries.owasp.org/cheatsheets/Forgot_Password_Cheat_Sheet.html).

### S4 — Referências da venda não garantem pertencimento à clínica — prioridade P0

Evidência: `SaleService::create()` valida positividade de tutor/paciente/atendimento e resolve produtos/procedimentos no tenant, mas não resolve tutor, paciente e atendimento em repositórios do tenant. `SaleForm::onSave()` repassa IDs do formulário. As FKs da migration `20260924_0005_phase4_procedure_stock_sale.sql` referenciam IDs simples, sem garantir a mesma clínica.

Impacto: um ID existente de outra clínica pode ser associado a uma venda por payload manipulado; a ausência dessa validação está confirmada no fluxo inspecionado, mas não foi testada contra o banco real.

Necessário: resolver todas as referências no tenant e validar também a relação paciente–tutor e atendimento–paciente/unidade. Aceite: qualquer referência de outra clínica é rejeitada antes de gravar venda ou consumir estoque.

### S5 — Dependências com avisos de segurança — prioridade P1, tratar antes da publicação

O lock contém `dompdf/dompdf 3.1.5` e `firebase/php-jwt 6.11.1`. A auditoria retornou seis avisos no Dompdf (quatro médios, dois baixos) e um baixo no JWT. Nos avisos do Dompdf, a faixa afetada é `<3.1.6`; no JWT, `<7.0.0`.

Necessário: atualizar versões e avaliar compatibilidade, sobretudo porque o Composer restringe JWT a `^6.0`. Revisar todos os renderizadores PDF. O renderizador de documentos usa HTML escapado e desabilita recursos remotos/PHP/JS, o que reduz a superfície; há outros usos de Dompdf nas telas. O aviso de JWT envolve qualidade da chave HMAC: o seed forte é uma mitigação existente. Aviso de dependência não prova exploração no produto.

Aceite: nova auditoria sem avisos aplicáveis pendentes, ou decisão documentada com análise específica de exposição e mitigação; PDFs e autenticação/reset passam regressão. Fontes: [Dompdf — leitura de arquivo local](https://github.com/advisories/GHSA-cx96-42px-69fm), [JWT — chave criptográfica fraca](https://github.com/advisories/GHSA-2x45-7fc3-mxwq).

### S6 — Downloads genéricos precisam de revisão de autorização — prioridade P1; P0 se usados entre clínicas

`src/download.php` exige login, restringe o caminho a `files/` e bloqueia `files/system/`, mas não consulta propriedade do arquivo, tenant ou permissão do recurso. Os documentos clínicos possuem mecanismos próprios; isso não demonstra que arquivos de outra clínica estejam hoje disponíveis pelo endpoint genérico.

Necessário: mapear seus usos e desabilitar o endpoint se desnecessário; caso necessário, resolver arquivo por identificador e verificar tenant/permissão. Aceite: usuário da clínica A não consegue baixar arquivo da B, mesmo conhecendo seu identificador/caminho.

## Carrinho, pagamentos e baixa automática

### F1 — PDV vende produto pelo custo — prioridade P0 se PDV fizer parte da oferta

`SaleForm.php:396` e `SaleService.php:208` usam `unitCostCents()`. A entidade `Product` já possui `salePriceCents()`, mas o fluxo de venda não o usa.

Exemplo: produto com custo de R$ 20 e preço de venda de R$ 35 entra no carrinho por R$ 20. O erro está tanto na exibição quanto na gravação.

Necessário: usar o preço de venda e definir explicitamente o comportamento quando estiver ausente. Aceite: carrinho, venda gravada e recibo usam R$ 35 no exemplo; produto sem preço segue a regra aprovada, sem fallback silencioso para custo.

### F2 — Concorrência e duplicidade em pagamentos/estoque — prioridade P0 para operação financeira

`PaymentService::register()` lê o recebível, altera o saldo em memória e o salva. `ReceivableRepository` faz leitura sem bloqueio e UPDATE do saldo absoluto, sem condição de versão. `StockService::consume()` e `StockBatchRepository` seguem padrão equivalente para o estoque. Não encontrei chave de idempotência em venda/recebimento.

Impacto: dois processos podem ler o mesmo saldo e gravar resultados incompatíveis. Por exemplo, dois pagamentos simultâneos podem gerar dois registros e lançamentos, mas deixar o recebível com apenas um incremento. No estoque, duas vendas podem consumir a mesma disponibilidade lógica.

**Correção importante de interpretação:** `PaymentForm` e `SaleForm` abrem `TTransaction`, compartilham a conexão entre os repositórios e fazem rollback em falhas. Portanto, os comentários antigos nos serviços que dizem que o projeto não tem transações não descrevem corretamente esses fluxos de tela. O risco de gravação parcial continua para chamadas do Core sem essa fronteira; no fluxo de tela, o problema principal encontrado é concorrência/idempotência.

Necessário: transação obrigatória no caso de uso, bloqueio ou atualização condicional de saldo/estoque e deduplicação de pedidos. Aceite: duas vendas da última unidade não têm sucesso; repetição do mesmo pagamento não duplica recebimento; falha em qualquer escrita reverte toda a operação.

### F3 — Venda avulsa não fecha o ciclo financeiro — prioridade P0 se PDV fizer parte da oferta

O fluxo inspecionado cria `sale`, `sale_item`, movimentos de estoque e recibo. Não cria recebível nem registra pagamento/entrada de caixa. `PaymentService` trabalha com `Receivable`, vinculado a conta de atendimento; não foi encontrada conexão automática da venda avulsa a esse fluxo.

Necessário: definir venda à vista/a prazo e ligar venda a recebível/recebimento/caixa, com consulta e cancelamento/estorno rastreável. Aceite: venda de R$ 100 aparece coerentemente em vendas e financeiro, sem exigir lançamento manual sem vínculo. Um recibo de venda não deve indicar dinheiro recebido sem confirmação correspondente.

### F4 — A landing ainda não realiza compra — prioridade P0 para aquisição automática

Evidência: `src/landing/landing.js` faz POST em `lead.php`. `LeadSubmissionHandler` valida e grava o lead; não chama gateway nem provisiona clínica. `landing.html:230` informa ativação pela equipe; em `:276`, informa que nenhum pagamento é cobrado. O catálogo apresenta valores, mas registra intenção comercial.

Fluxo mínimo a implementar:

1. Seleção do plano → pedido com valor/ciclo resolvidos no servidor.
2. Identificação do responsável e aceite dos termos da assinatura, separado do consentimento de contato atual.
3. Checkout no gateway, com correlação entre pedido, cliente e cobrança.
4. Confirmação autenticada do provedor → atualização idempotente do pagamento/assinatura.
5. Provisionamento de tenant, unidade, administrador e permissões, com recuperação de falha/reexecução segura.
6. Convite seguro para definir senha → primeiro acesso → configuração mínima da clínica.

O retorno do navegador não deve sozinho liberar acesso. Cobrança aprovada com falha no provisionamento precisa aparecer como pendência recuperável; o cliente não pode ser cobrado novamente ao tentar retomar.

### F5 — Recorrência e baixa externa não estão implementadas — prioridade P0 para a oferta solicitada

Não foram encontrados checkout, webhook de pagamento ou domínio de assinatura SaaS no código próprio e migrations inspecionados. Marcar a forma de pagamento como `pix` ou `credit_card` registra uma informação manual; isso não emite cobrança nem confirma recebimento bancário.

Mínimo necessário: assinatura com período/estado e IDs externos; faturas/cobranças; evento recebido com ID único; confirmação autenticada; reconciliação de eventos perdidos; renovação, atraso, período de tolerância, recuperação, cancelamento e reembolso/estorno. Estados e transições precisam evitar que um evento antigo substitua uma confirmação mais recente.

Separar dois domínios: **assinatura da clínica para usar o Central Vet** e **pagamentos dos tutores à clínica**. O primeiro é indispensável para contratar o SaaS automaticamente. O segundo pode começar com recebimentos manuais no MVP se a oferta deixar isso claro; automatizá-lo também exige decidir credenciais/contas por clínica e a quem o dinheiro é liquidado.

Planos precisam controlar recursos, usuários e unidades no servidor. O `LandingCatalog` é um catálogo comercial; não foi encontrada ligação da escolha do plano ao provisionamento e às permissões de uso. A flag/quota de IA existente não representa um sistema de direitos por assinatura.

## Funcionalidades mínimas para uso

O recorte inicial pode aproveitar os módulos existentes: configuração da clínica/unidade, administração de equipe e perfis, tutor/paciente, agenda, atendimento/prontuário, prescrição/PDF, fechamento de conta, recebimento e consulta financeira. Exames e vacinação podem entrar se forem homologados. Estoque/PDV só devem ser anunciados quando F1–F3 estiverem resolvidos.

Homologar uma jornada com dados de teste em ambiente próprio: administrador configura equipe → recepção cadastra tutor/paciente e agenda → veterinário atende e emite prescrição → financeiro fecha conta, recebe parcial/total e confere saldo/caixa. Repetir com dois tenants e perfis restritos. Confirmar validação, mensagens de erro, navegação, permissões, PDFs e recuperação de uma falha.

O onboarding precisa permitir iniciar sem intervenção técnica: dados da clínica, unidade, usuários, serviços/preços e orientações do primeiro atendimento. Definir canal de suporte, política de cancelamento e acesso/exportação de dados. Os textos de privacidade e termos devem cobrir o uso efetivo do serviço; o consentimento atual cobre apenas contato comercial.

Internação, cirurgia, IA, relatórios avançados, Central Intelligence, Ei, Vet!, SSO e governança de redes podem ficar depois. Código de um módulo não é evidência de disponibilidade comercial. A oferta deve refletir o recorte homologado; começar com um plano reduz a complexidade de permissões e suporte.

## Operação e publicação

- **Backup:** `scripts/backup.sh` cobre apenas MySQL. Não cobre uploads, objetos/MinIO nem documentos armazenados. Completar cópia externa, proteção, retenção e recuperação coerente de banco + arquivos. Restaurar em ambiente isolado e medir tempo/perda possível antes do piloto com dados reais.
- **CI:** `.github/workflows/ci.yml` sobe MySQL, mas não provisiona o banco de teste antes de rodar a suíte. O runner recusa execução se MySQL estiver acessível e o banco de teste não existir. Incluir provisionamento isolado e verificação de schema no pipeline; não usar o banco da aplicação para testes.
- **Release:** gerar imagem a partir de commit identificado, garantir paridade do ambiente homologado, registrar migrations aplicadas e ensaiar rollback. Há alterações staged preexistentes de IA; não foram commitadas nesta avaliação.
- **Produção:** homologar domínio/TLS e cookies Secure atrás do proxy, debug desligado, confiança apenas nos proxies reais, credenciais e portas privadas. A configuração local HTTP não demonstra a configuração pública. Rever `set_real_ip_from` amplo antes de expor o Nginx em outra topologia.
- **Observabilidade:** alertar para erro de pagamento/provisionamento, fila parada, mensagens/documentos com falha, backup ausente e armazenamento indisponível. Serviço saudável não garante job de negócio processado.
- **Comunicação:** existe implementação SMTP e link de WhatsApp, mas envio real e entrega não foram testados. Validar SMTP para convites/reset. Não anunciar automação de WhatsApp só pela existência de link.

## Ordem recomendada e critérios de liberação

| Etapa | Entrega | Critério de conclusão |
| --- | --- | --- |
| 1 — Segurança | S1–S4, revisão S5/S6, configuração pública | Cadastro alternativo fechado; mutações protegidas; reset seguro; isolamento verificado |
| 2 — Dinheiro e estoque | F1–F3 | Preço correto; venda conciliada; concorrência, reenvio e rollback homologados |
| 3 — Contratação | F4–F5 e direitos por plano | Contratação, confirmação, renovação e ativação sem intervenção técnica |
| 4 — Operação | Backup completo, CI, release e alertas | Restauração comprovada; pipeline reproduzível; falhas recuperáveis e visíveis |
| 5 — Piloto e lançamento | Jornada por perfil com clínicas piloto | Nenhum bloqueio aberto no escopo anunciado; suporte/onboarding e oferta alinhados |

Para liberar aquisição automatizada, testar pelo menos: pagamento aprovado; recusado; pendente; webhook inválido; evento duplicado; eventos fora de ordem; falha do gateway; pagamento aprovado com provisionamento interrompido; renovação aprovada/recusada; cancelamento; estorno; usuário tentando acessar recurso de outro plano/tenant. Cada cenário precisa produzir estado coerente e permitir retomada sem cobrança ou conta duplicada.

Não há evidência suficiente para estimar uma data responsável de lançamento. Primeiro é necessário definir o recorte comercial, gateway, meios de pagamento/ciclo de cobrança e hospedagem alvo; a estimativa deve ser feita sobre essas entregas e sua homologação, sem incluir os módulos futuros como dependência do MVP.
