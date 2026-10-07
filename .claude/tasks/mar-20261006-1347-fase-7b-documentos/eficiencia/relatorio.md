# Eficiência — mar-20261006-1347-fase-7b-documentos

- Plano: `/var/www/html/centralvet/.claude/tasks/mar-20261006-1347-fase-7b-documentos` · projeto `/var/www/html/centralvet` · versão 2.5.1? · gerado em 2026-10-06T15:30:57-03:00 · última onda medida 6
- Sessões: f5fb58b5-22f0-470a-ab96-189c6d59a62c (encontrada)
- Base: sem base (nenhum plano da versão anterior no acervo)

## Indicadores

| Indicador | Este plano | Base |
|---|---|---|
| Tokens retidos por onda (mediana) | 28.106 | sem base |
| Contexto máximo | 909.468 | sem base |
| Compactações | 2 | sem base |
| Correções por implementador | 0,18 | sem base |
| Retornos acima do limite (%) | 11 | sem base |
| Validador acima da meta (%) | 29 | sem base |
| Aprovadas de primeira (%) | 95 | sem base |
| Precisão do mapa de arquivos (%) | 99 | sem base |
| Rodadas de fix loop | 4 | sem base |
| Tasks de correção pós-Fase 5 (%) | 0 | sem base |

## Tasks

| Task | Onda | Origem | Complexidade | Status | RED | Gate | Fix loop | Revisor (bloq./sug.) | Perguntas | Desvios | Fora do previsto |
|---|---|---|---|---|---|---|---|---|---|---|---|
| T-01 | 1 | plano | alta | [ ] | sem teste | 0 | 0 | 0/0 | 0 | 1 | — |
| T-02 | 1 | plano | média | [ ] | ok | 0 | 0 | 0/5 | 0 | 4 | — |
| T-03 | 1 | plano | média | [ ] | ok | 0 | 0 | 0/2 | 0 | 4 | — |
| T-04 | 1 | plano | simples | [ ] | sem teste | 0 | 0 | 0/0 | 0 | 0 | — |
| T-05 | 2 | plano | média | [x] | ok | 0 | 0 | 0/4 | 0 | 3 | — |
| T-06 | 2 | plano | alta | [x] | ok | 0 | 0 | 0/3 | 0 | 4 | — |
| T-07 | 2 | plano | média | [x] | ok | 0 | 0 | 0/1 | 0 | 0 | — |
| T-08 | 2 | plano | média | [x] | ok | 0 | 0 | 0/3 | 0 | 3 | — |
| T-09 | 2 | plano | média | [x] | ok | 0 | 1 | 0/4 | 0 | 2 | src/tests/Integration/CommunicationReadModelIntegrationTest.php |
| T-10 | 3 | plano | alta | [x] | ok | 0 | 0 | 0/4 | 0 | 4 | — |
| T-11 | 3 | plano | média | [x] | ok | 0 | 0 | 0/2 | 0 | 5 | — |
| T-12 | 3 | plano | alta | [x] | ok | 0 | 0 | 0/4 | 0 | 6 | — |
| T-13 | 3 | plano | simples | [x] | ok | 0 | 0 | 0/3 | 0 | 3 | — |
| T-14 | 4 | plano | alta | [x] | ok | 0 | 0 | 0/2 | 0 | 2 | — |
| T-15 | 4 | plano | média | [x] | ok | 0 | 0 | 0/2 | 0 | 5 | — |
| T-16 | 4 | plano | média | [x] | ok | 0 | 0 | 0/4 | 0 | 4 | — |
| T-17 | 4 | plano | simples | [x] | ok | 0 | 0 | 0/4 | 0 | 5 | — |
| T-18 | 4 | plano | simples | [x] | ok | 0 | 0 | 0/2 | 0 | 1 | — |
| T-19 | 5 | plano | média | [x] | ok | 0 | 0 | 0/0 | 0 | 5 | — |
| T-20 | 5 | plano | simples | [x] | sem teste | 0 | 0 | 0/1 | 0 | 0 | — |
| T-21 | 6 | plano | média | [x] | sem teste | 1 | 3 | 0/0 | 0 | 3 | — |

## Causas

- T-01: Índices nomeados de FK além do plano, seguindo o padrão da 0012; contrato inalterado.
- T-02: Extras públicos (assignId, requiresBodyText) e repositórios sem TenantRepositoryInterface por conflito de assinatura.
- T-03: Testes extras e mensagens de I/O adicionais; bucket local e checagem do tempnam no diretório de destino.
- T-05: Semântica dos fakes (failWith de uma chamada, claim x releaseClaim) divergiu do contrato; documentada no relatório
- T-06: Repositórios não estendem AbstractTenantRepository (findById(int) do contrato T-02); INSERT SELECT com retry em 1062
- T-08: Extras públicos aditivos options() e construtor com DocumentHtmlBuilder opcional para teste das opções dompdf
- T-09: Fixture da 7A (CommunicationReadModelIntegrationTest) quebrou com o 9º tipo; ruling autorizou ajuste fora do escopo
- T-10: Spy de storage do teste lançava RuntimeException em vez de StorageException; ajustado após o RED.
- T-11: Plano ambíguo sobre signatário do snapshot e formato de subjectLines; docblock da interface desatualizado.
- T-12: Docblock da T-02 cita DocumentSourceNotFoundException e o plano DocumentGenerationFailed; passo 3 aceita as duas.
- T-13: Contrato de template ausente/inativo no merge não especificado no plano; decidido InvalidArgumentException.
- T-14: Fake grava created_at null; teste de recência do varredor removido e forConnection público extra
- T-15: Teste ajustado após o RED para comparar com _t() (Generate PDF já traduzido)
- T-16: CLI não registra headers; download delegado a downloadResponse; retry recarrega sem patient_id
- T-17: Teste ajustado após o RED (nome curto da classe); edição filtra listAll() por falta de find
- T-19: Desvios de texto do catálogo i18n: padrão com aspas, código de falha omitido, termo da 7A
- T-21: Gate E2E achou Baixar sem download via index.php (fix engine.php na T-16) e SQL sem sala/ids; evidência RF1/RF4 complementada
