# ADR 0004: Fronteira analítica da Central Intelligence

- Status: proposta
- Data: 2026-10-02
- Piloto: Imperatriz/MA
- Referências: [plano](../../../.docs/plan/centralvet-roadmap.md), [PRD](../../../.docs/prd/central-intelligence.md), [pesquisa](../../../.docs/research/central-intelligence-mercado-tecnologia.md)

## Contexto

A Intelligence combina contexto público territorial com indicadores próprios e, futuramente, agregados de várias organizações. As ADRs 0001 e 0002 continuam vigentes: services independentes de Adianti e contexto tenant derivado no servidor. A hospedagem compartilhada usa MySQL 5.7; não se pode pressupor disponibilidade de PostgreSQL/PostGIS, Redis ou workers persistentes nesse ambiente.

## Decisão proposta

Manter MySQL como banco transacional. Criar um contexto analítico separado, com PostgreSQL/PostGIS em serviço externo quando o volume e as consultas justificarem. O primeiro protótipo pode consumir snapshots públicos GeoJSON calculados por pipeline externo; não requer migrar o ERP nem instalar um novo banco no cPanel.

Separar armazenamento e contratos por classe de informação:

1. **Pública/licenciada:** regiões, pontos de oferta e contexto demográfico, com licença e proveniência.
2. **Privada:** métricas da própria organização, sempre com tenant e autorização; nenhum arquivo público/CDN contém essa camada.
3. **Agregada publicável:** produto derivado, somente após política de publicação, adesão e avaliação de risco. Usuários não consultam fatos brutos de outros tenants.

A aplicação PHP autentica e autoriza as consultas antes de usar o adapter analítico. O serviço remoto valida credenciais de serviço e contexto de escopo emitido pelo servidor; não aceita tenant arbitrário do navegador. Exposição direta de PostGIS/credenciais ao cliente é proibida. Cache privado inclui tenant, unidade, permissão, filtros e versão; respostas públicas só usam cache compartilhado quando efetivamente públicas e redistribuíveis.

Ingestão pesada, geocodificação e recomputação são assíncronas. A extração operacional deve ser mínima, idempotente, reconciliável e incluir correções/exclusões. Avaliar outbox contra extração por watermark na CI-1; não assumir eventos ou fila distribuída já disponíveis no ambiente online.

## Alternativas e consequências

- **Snapshots externos:** menor custo para provar valor em uma cidade; atualização por lote e interação limitada. Publicar atomicamente a versão nova, mantendo a última versão íntegra.
- **PostGIS externo:** consultas por polígonos, índices espaciais e agregações com expansão mais flexível; acrescenta operação, backups, monitoramento e custo de serviço.
- **Somente MySQL operacional:** evita serviço inicial, mas mistura ETL e consultas analíticas à operação e aumenta dependência da versão da hospedagem. Não é a recomendação para a evolução da camada.

Leaflet é o candidato inicial para mapa 2D e poucos dados; MapLibre com tiles vetoriais deve ser avaliado em testes de volume. Biblioteca de mapa não fornece dados, licenças nem infraestrutura de tiles. A escolha final depende da CI-0 e da prova técnica.

## Condições para aceitar

Demonstrar cobertura em Imperatriz, orçamento, conectividade segura PHP→serviço, isolamento, recuperação e operação sem impacto no atendimento. Formalizar fontes/licenças, política de retenção, base legal para dados pessoais e controles de publicação. Aprovar contratos e testes de segurança antes da implementação. Nenhum schema, serviço ou migration foi criado por esta ADR.
