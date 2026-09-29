# Tasks

| ID | Camada | Descrição | Dependências | Paralelizável | Complexidade | Agente | Status |
|---|---|---|---|---|---|---|---|
| T-01 | infra | Extrair requisitos do PRD e auditar o host sem alterações | — | não | média | Athena | [x] |
| T-02 | infra | Criar estrutura base, `.env.example`, ignore e documentação inicial | T-01 | não | média | Aang | [x] |
| T-03 | infra | Criar imagem PHP 8.3 e Compose com Nginx, MySQL 8, Redis 7 e worker | T-02 | não | alta | Aang | [x] |
| T-04 | backend | Criar bootstrap/healthcheck mínimo e configuração segura por ambiente | T-03 | sim | média | Aang | [x] |
| T-05 | infra | Criar comandos de operação, backup/restauração e diagnóstico | T-03 | sim | média | Aang | [x] |
| T-06 | shared | Validar build, healthchecks, isolamento de portas, persistência e documentação | T-04, T-05 | não | média | Levi | [x] |
| T-07 | backend/infra | Importar e adaptar o Adianti Template 8.6 para `src/`, sem dados de exemplo ou inicialização do banco | T-06 | não | alta | Darwin | [x] |
| T-08 | backend/infra | Corrigir hardening, diretórios graváveis e build Composer reprodutível após revisão | T-07 | não | alta | Darwin | [x] |
| T-09 | infra | Gerar configuração local segura, inicializar a stack isolada e validar serviços | T-08 | não | média | Aang | [x] |
| T-10 | database | Aplicar bootstrap sanitizado do Adianti no MySQL isolado e validar objetos/seeds | T-09 | não | alta | Darwin | [x] |
| T-11 | database | Preparar migration auditável das 16 FKs ausentes, sem aplicar | T-10 | não | média | Darwin | [x] |
| T-12 | database | Fazer backup, aplicar as 16 FKs autorizadas e validar integridade | T-11 | não | alta | Darwin | [x] |

## Legenda
- [ ] pendente  · [~] em andamento  · [x] concluído  · [!] bloqueado
