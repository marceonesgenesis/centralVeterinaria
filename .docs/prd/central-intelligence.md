# PRD — Central Intelligence

> Camada de inteligência territorial do Central Vet Pro para gestores de clínicas,
> pet shops e banho e tosa. Compara oferta, contexto demográfico e indicadores
> próprios para orientar decisões de mercado. Piloto definido: Imperatriz/MA.

Versão 0.1 · 02/10/2026 · Status: especificação proposta, não implementada.

## 1. Visão geral

A nova área “Central Intelligence” oferece um mapa com estabelecimentos pet/veterinários e camadas de contexto territorial. Ao selecionar uma região, o gestor consulta indicadores, cobertura das fontes, período e explicação do potencial estimado. Pode comparar regiões e, em etapa posterior, sobrepor indicadores da própria operação.

O produto terá três escopos explícitos: `public_market`, `own_business` e `network_benchmark`. Os dados públicos são compartilháveis conforme sua licença. Dados próprios exigem contexto tenant e unidade autorizados. Benchmark será um produto agregado separado, sujeito a elegibilidade; não será uma consulta operacional cross-tenant.

No MVP, regiões serão setores censitários ou agrupamentos documentados deles. Bairros só serão oferecidos como limites administrativos quando houver geometria adequada e fonte validada. Um nome de bairro no endereço não constitui um polígono oficial. A população e a renda são contexto; não medem diretamente número de pets, compra de serviços ou movimento por hora.

Um cartão inicial pode dizer: “Potencial demográfico estimado elevado; oferta cadastrada intermediária; demanda observada indisponível”. A frase original “maior procura por banho e tosa” exige dados observados representativos e fica para etapas posteriores. Esses exemplos são textos de interface, não diagnósticos atuais de bairros de Imperatriz.

## 2. Objetivos

- Permitir localizar oferta por categoria e comparar regiões de Imperatriz.
- Explicar indicadores e incertezas para orientar expansão, campanhas e mix de serviços.
- Incorporar sinais próprios sem comprometer a operação nem o isolamento.
- Preparar contratos, taxonomia, proveniência e governança para expansão futura.
- Validar utilidade, cobertura e disposição de pagar antes de escalar o módulo.

## 3. Não objetivos

- Consultar Pix/cartão ou dados financeiros de concorrentes no MVP.
- Compartilhar prontuários, listas de tutores, preços ou faturamento individual entre empresas.
- Inferir fluxo horário de pessoas a partir de população, renda ou horário de abertura.
- Prometer previsão de receita, demanda comprovada ou sucesso de um ponto comercial.
- Desenvolver marketplace, rede de encaminhamento clínico ou colaboração clínica nesta entrega; são ideias distintas que exigem especificação própria.
- Substituir MySQL, implementar todo o módulo agora ou mudar preços da landing.

## 4. Personas e permissões

| Persona funcional proposta | Uso | Restrição |
|---|---|---|
| Proprietário/gestor autorizado | Mapa, comparações e visão do próprio negócio | Limitado ao tenant/unidades permitidos e ao entitlement |
| Gestor de unidade | Diagnóstico da unidade e mercado local | Não obtém visão financeira de unidades sem permissão |
| Operador de dados da plataforma | Ingestão, revisão de qualidade e publicação pública | Papel separado, auditado; não ganha acesso implícito aos dados privados |
| Responsável por governança | Políticas, adesão e avaliação de publicação | Acesso mínimo específico; sem bypass genérico de tenant |

Esses são papéis funcionais, não nomes de grupos já existentes. Mapear ao RBAC real na CI-1. Permissões propostas: `intelligence.market.read`, `intelligence.own.read`, `intelligence.export`, `intelligence.contribution.manage` e `intelligence.datasets.manage`. Entitlements são independentes das permissões: assinatura habilitada não substitui autorização.

## 5. Fluxo principal

1. Usuário autenticado abre a área; servidor resolve tenant, permissões, unidades e entitlement.
2. Mapa carrega Imperatriz com fontes, datas e camadas disponíveis. POIs e indicadores podem ter datas distintas.
3. Usuário escolhe categoria: veterinária, pet shop, banho e tosa ou medicamentos veterinários. Um estabelecimento pode ter várias categorias; total de estabelecimentos usa identificadores únicos.
4. Seleciona região e vê oferta cadastrada, população, renda disponível, qualidade e potencial estimado, com metodologia acessível.
5. Compara até três regiões, mantendo categoria, unidade de comparação e versão consistentes.
6. Na CI-3, ativa “Meu negócio”, escolhe unidade/período autorizado e consulta origem regional e serviços realizados da própria empresa. Localização da unidade e origem do cliente são dimensões distintas.
7. Na CI-4, solicita benchmark; a API retorna somente resultados aprovados ou “amostra insuficiente”. A adesão da empresa é gerida em fluxo separado com termos específicos.

Se faltar camada, o mapa permanece utilizável com aviso de cobertura. A indisponibilidade analítica não interrompe agenda, prontuário ou financeiro.

## 6. Requisitos funcionais

### 6.1 Mapa e fontes — CI-2

- Filtros por categoria e região; agrupamento visual de pontos sem alterar contagens.
- POIs com nome comercial quando publicável, categorias, fonte, data e precisão aproximada. Excluir contatos pessoais e informações de sócios sem necessidade.
- Deduplicação entre fontes com registro da origem; não somar registros duplicados como concorrentes distintos.
- Fonte cadastral ativa deve ser descrita como situação cadastral, não confirmação de atendimento presencial.
- Cada camada possui data de referência, importação, licença e status `available`, `partial`, `stale` ou `unavailable`.
- Indicadores faltantes ficam nulos com motivo, jamais zero por ausência de informação.
- Comparação de população, oferta por categoria e razão de estabelecimentos por população/domicílios, com denominador e unidade explícitos.

### 6.2 Potencial e recomendações

- Primeira versão baseada em regras estatísticas transparentes; fórmula e limites versionados antes da publicação.
- Não gerar um score se faltar variável essencial ou houver cobertura baixa de concorrentes.
- Classificações relativas ao recorte de Imperatriz; não transferir cortes automaticamente para outra cidade.
- Mostrar fatores favoráveis, limitações e evidências; evitar “abrir aqui” como decisão definitiva.
- IA opcional apenas para explicar resultados autorizados; cálculo, autorização e supressão são determinísticos. Sem SQL arbitrário ou geração de fatos ausentes.

### 6.3 Meu negócio — CI-3

- Indicadores agregados por origem territorial de clientes, unidade de atendimento, categoria e período, separados de dados públicos.
- Definir contagem de atendimentos/vendas, ticket, receita e ocupação sem dupla contagem entre atendimento, PDV e recebíveis.
- Exibir representatividade dos registros geocodificados e excluir agendamentos cancelados quando o indicador for “realizados”.
- Horários próprios representam atendimentos/vendas registrados. Não representam fluxo da rua nem procura não atendida.
- Endereços e pontos residenciais individuais não aparecem no mapa de inteligência; agregações próprias também limitam detalhes conforme necessidade e permissão.

### 6.4 Rede — CI-4

- Contribuição desativada por padrão; adesão específica da organização, finalidade, termos versionados e trilha de auditoria.
- Publicação condicionada a número mínimo de organizações independentes, observações mínimas e limite de dominância, além de avaliação de reidentificação.
- Aplicar supressão complementar e impedir que filtros, períodos sobrepostos, zoom ou comparação com dados próprios revelem células suprimidas.
- Preferir recortes fixos e publicação mensal; não permitir combinações arbitrárias de filtros sobre fatos brutos.
- Revogação encerra contribuições futuras; política de reprocessamento, retenção e exclusão deve ser definida antes do lançamento, inclusive direitos aplicáveis.
- Não publicar número exato de contribuidores se isso permitir identificar empresas pequenas. Interface pode mostrar apenas faixa de cobertura aprovada.

## 7. Modelo de dados proposto

Todas as entidades abaixo são futuras; não existem migrations desta feature. Nomes físicos e tipos serão revistos na CI-1.

| Entidade | Armazenamento e principais campos |
|---|---|
| `geo_region` | Analítico público: identificador, código IBGE, tipo, geometria, fonte, edição, vigência e CRS |
| `market_establishment` | Analítico público/licenciado: ID da fonte, CNPJ de estabelecimento quando necessário, nome, categorias, situação, localização/precisão, proveniência |
| `data_source` / `dataset_snapshot` | Fonte, direitos de uso, referência temporal, checksum, importação, qualidade, estado e versão publicável |
| `public_region_indicator` | Região/versão/indicador, valor/unidade, data de referência, cobertura e metodologia |
| `service_category` / `tenant_service_mapping` | Taxonomia comum e mapeamento autorizado dos serviços de cada tenant |
| Localização de unidade/tutor | Evolução operacional aditiva: endereço estruturado, município, geocódigo, precisão e origem; tutor continua privado |
| `own_region_metric` | Analítico privado: tenant obrigatório, unidade, região, período, categoria, métrica, valor e cobertura |
| `intelligence_contribution` | Operacional/governança: tenant, estado, finalidade, termos/versão, responsável e datas de adesão/revogação |
| `benchmark_release` / `benchmark_cell` | Produto publicável: recorte fixo, período, versão de política, valor permitido ou supressão; sem lista de participantes |
| `intelligence_job` | Operação analítica: fonte, checkpoint, idempotência, tentativas, versão, início/fim, falha sem dados pessoais no log |

Estados de jobs propostos: `pending`, `running`, `succeeded`, `failed`. Estados de contribuição: `disabled`, `enabled`, `revoked`. Nenhuma entidade pública contém `tenant_id` operacional ou identificadores de tutores/pacientes. Staging privado e produto publicável têm acessos e retenções distintos.

## 8. Contratos de API propostos

Rotas aditivas e futuras. O prefixo `/api/v1/intelligence` deve ser compatibilizado com o roteamento existente na implementação. Toda consulta exige sessão/token válido, RBAC e entitlement. `tenant_id` nunca é parâmetro público; unidade é validada contra o contexto autenticado.

| Método/rota | Entrada | Resposta e política |
|---|---|---|
| `GET /coverage?municipality_code=2105302` | Código municipal | Camadas, fontes, referências, versões e limitações; `market.read` |
| `GET /establishments?municipality_code=2105302&category=veterinary&bbox=...&cursor=...` | Categoria, bbox limitado, cursor | POIs permitidos, precisão, fontes e próximo cursor; limite máximo proposto 200 por página |
| `GET /regions/{id}/indicators?scope=public_market` | Região e escopo | Indicadores públicos e potencial estimado; região deve pertencer à cobertura contratada |
| `GET /regions/{id}/indicators?scope=own_business&unit_id=...&period=2026-09` | Unidade/período | Somente métricas do contexto tenant; `own.read` e unidade autorizada |
| `GET /regions/{id}/indicators?scope=network_benchmark&period=...` | Recorte de publicação permitido | Release aprovado ou supressão; sem fatos brutos nem contribuidores identificáveis |
| `POST /comparisons` | Até três IDs de região, categoria, período e escopo | Mesma metodologia e versão para todas as regiões; somente leitura lógica |
| `PUT /contribution` | Estado desejado e versão de termos | Alteração auditada, CSRF/idempotência e `contribution.manage`; somente CI-4 |

Exemplo de resposta **ilustrativa, sem cálculo local realizado**:

```json
{
  "municipality_code": "2105302",
  "region_id": "example-region",
  "scope": "public_market",
  "snapshot_version": "example-v1",
  "indicators": [],
  "opportunity": {
    "status": "insufficient_data",
    "score": null,
    "methodology_version": null
  },
  "observed_demand": {
    "status": "unavailable",
    "value": null
  },
  "limitations": ["regional_offer_coverage_not_validated"]
}
```

Retornos: `401` sem autenticação, `403` sem acesso, `404` para região/recurso inexistente ou inacessível, `422` para filtros inválidos, `429` para limite, `503` para serviço indisponível. Supressão ou falta de dados é resposta válida `200` com status explícito e valores nulos; não pode vazar contagens por mensagens de erro.

## 9. Validações

- Município habilitado e código IBGE tratado como texto; o código municipal da Receita exige tabela de correspondência, não igualdade automática ao código IBGE.
- Bbox com coordenadas válidas, ordem definida longitude/latitude e extensão máxima; geometria validada e CRS conhecido.
- Categoria em allowlist, períodos fechados permitidos, cursores opacos, tamanho máximo e limites de exportação.
- Região/unidade reconhecidas e autorizadas; proteção contra injeção em filtros e consultas parametrizadas.
- Critérios de privacidade definidos no servidor e imutáveis pelo cliente; não aceitar `min_sample=1` ou parâmetros equivalentes.
- Precisão ruim ou geocódigo de centroide municipal não pode ser exibido como endereço exato nem atribuído a um bairro específico.

## 10. Regras de negócio

População não equivale a população pet. Rendimento do responsável não equivale a renda domiciliar per capita nem gasto pet. Ausência de POIs não demonstra ausência de concorrência. CNPJ ativo não garante estabelecimento em funcionamento. Essas distinções acompanham os indicadores.

A categoria define oferta relevante, não capacidade produtiva: duas clínicas podem ter portes diferentes. Um índice de oportunidade nunca interpreta automaticamente “poucos concorrentes” como demanda reprimida; baixa oferta também pode refletir mercado pequeno ou coleta incompleta.

A adesão empresarial não substitui análise da base legal para dados pessoais de tutores. Remover identificadores ou aplicar hash não garante anonimização. Controles e responsabilidades precisam de avaliação jurídica antes da CI-3/4, conforme [LGPD](https://www.planalto.gov.br/ccivil_03/_ato2015-2018/2018/lei/l13709.htm) e [estudo técnico da ANPD](https://www.gov.br/anpd/pt-br/centrais-de-conteudo/documentos-tecnicos-orientativos/estudo_tecnico_sobre_anonimizacao_de_dados_na_lgpd___analise_juridica.pdf).

## 11. Estados de interface

| Estado | Comportamento |
|---|---|
| Carregando | Skeleton do painel; mapa base não sugere indicadores já disponíveis |
| Vazio | “Nenhum estabelecimento encontrado nas fontes consultadas”, com filtros e cobertura |
| Parcial/desatualizado | Camadas disponíveis utilizáveis; datas, lacunas e aviso destacados |
| Erro | Repetir consulta; mostrar última versão íntegra com sua data se permitido |
| Sem permissão | Explicar indisponibilidade de acesso; não carregar dados privados |
| Amostra insuficiente | Sem valor/score; sem detalhes que revelem contribuidores |
| Sucesso | Mapa e tabela sincronizados, unidade, fonte, período e metodologia visíveis |

## 12. UX e Design

Usar o design system existente. Desktop/tablet: filtros, mapa e painel lateral; mobile: mapa e painel alternáveis, com lista acessível equivalente. Nenhum indicador depende exclusivamente de cor. Legenda, fonte, atribuição cartográfica e recorte permanecem visíveis. A seleção de idioma seguirá os idiomas do produto quando a área for implementada; dados brasileiros preservam sua unidade e moeda, sem sugerir cobertura internacional.

## 13. Requisitos não funcionais

- Consultas pré-calculadas inicialmente; meta proposta p95 de API inferior a 1 s em carga piloto definida na CI-0 e painel útil em até 3 s na conexão de teste acordada. Metas não são resultados medidos.
- Paginação/tiles conforme volume medido; jobs e geocodificação fora do request PHP.
- TLS, segredos fora do repositório, assinatura/credenciais internas, timeout, retry limitado e circuit breaker do adapter.
- Falha analítica isolada da operação; backups e recuperação próprios, versões publicadas atomicamente e alertas de frescor/custo.
- Cache compartilhado restrito a dados publicáveis; cache privado segregado por tenant, unidade, autorização e versão.
- Registrar consultas/exportações relevantes sem endereços, prontuários ou cargas pessoais em logs. Exportações respeitam as mesmas políticas da tela.

## 14. Métricas

Cobertura e precisão da amostra de POIs, taxa de deduplicação, geocodificação por precisão, dados próprios representados, frescor, supressão de benchmark, latência e custo por usuário/cidade. Produto: tarefas de comparação concluídas, decisões relatadas com evidência, frequência de retorno e adesão paga. Não usar quantidade de pins como prova de qualidade ou visualizações como prova de valor.

## 15. Critérios de aceite

### MVP público — CI-2

- [ ] Imperatriz carregada com limites/fonte/edição e camadas documentadas.
- [ ] Categorias funcionam sem dupla contagem e POIs têm proveniência/precisão.
- [ ] Indicadores públicos têm período, unidade, cobertura e método; faltantes são nulos.
- [ ] Potencial está identificado como estimativa; nenhuma demanda ou movimentação não observada é declarada.
- [ ] Score reproduzível, dados essenciais validados e alerta/suspensão sob baixa cobertura.
- [ ] Comparação de regiões com recortes e versões consistentes; alternativa acessível em lista.
- [ ] Autorização e entitlement aplicados no servidor, com cache correto.
- [ ] Queda do serviço analítico não prejudica a operação clínica.
- [ ] Fontes/licenças, atribuição, custo e resultados da auditoria local revisados.

### Incrementos CI-3/4

- [ ] Dois tenants de teste não acessam métricas, cache ou exportações um do outro.
- [ ] Valores próprios reconciliados com origem, incluindo cancelamentos e estornos.
- [ ] Adesão, revogação e retenção obedecem à política aprovada.
- [ ] Células pequenas/dominadas e consultas por diferença não revelam dados.
- [ ] Base legal, responsabilidades e processo de atendimento de direitos definidos.

Esses testes pertencem à implementação futura; nenhum deles foi executado como se o módulo já existisse.

## 16. Riscos e mitigação

| Risco | Mitigação |
|---|---|
| Cadastro incompleto ou desatualizado | Auditoria local, precisão, datas e score suspenso |
| Viés de renda e região | Método documentado, cenários e validação qualitativa |
| Rede pequena/empresa dominante | Suprimir, ampliar recorte; lançar mapa público independentemente |
| Inferência de clientes/concorrentes | Separação, controles de publicação e testes por diferenças |
| Custos de dados e mapa | Limites, medição de uso, contratos e adapter substituível |
| ETL sobrecarregar hospedagem | Pipeline externo e extração incremental controlada |
| IA inventar diagnóstico | Explicação restrita a valores calculados e referências |

## 17. Dependências técnicas

Core modular e TenantContext existentes; cadastro geográfico ainda a evoluir; taxonomia, adapter, ETL e governança novos. PostgreSQL/PostGIS é candidato externo, não instalado. Leaflet é candidato inicial e MapLibre uma alternativa para volume. Fontes IBGE, Receita e OSM exigem verificação de cobertura, edição e direitos; serviços comerciais dependem de contrato.

Ver [pesquisa tecnológica](../research/central-intelligence-mercado-tecnologia.md) e [ADR proposta](../../docs/architecture/adr/0004-central-intelligence-analytical-boundary.md).

## 18. Roadmap e pendências

CI-0 descoberta → CI-1 preparação → CI-2 mapa público → CI-3 visão própria → CI-4 benchmark elegível → CI-5 expansão/licenciamento. Gates e esforço no [plano atualizado](../plan/centralvet-roadmap.md).

**TODO:** responsável, orçamento, fornecedor, polígonos de bairros, fórmula/cortes do score, limiares de publicação/dominância, retenção, bases legais, preço e metas comerciais. Limiares de privacidade serão definidos com avaliação de risco; não há número universal que garanta anonimização.
