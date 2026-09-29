# Tasks

| ID | Camada | Descrição | Dependências | Paralelizável | Complexidade | Agente | Status |
|---|---|---|---|---|---|---|---|
| T-01 | shared | Auditar gaps da Fase 0 contra PRD e baseline atual | — | não | média | Sherlock | [x] |
| T-02 | backend | Criar ADRs, módulos Core, PSR-4 e contratos de Service/Repository | T-01 | não | alta | Athena | [x] |
| T-03 | database | Preparar migrations de tenant, vínculos, auditoria, storage e compatibilidade | T-02 | não | alta | Athena | [x] |
| T-04 | backend | Implementar TenantContext fail-closed e repositories tenant-aware | T-03 | não | alta | Athena | [x] |
| T-05 | backend | Migrar sessões ao Redis e endurecer autenticação/rate limit/revogação | T-04 | sim | alta | Jaspion | [x] |
| T-06 | backend | Implementar autorização central RBAC por tenant/unidade e auditoria correlacionada | T-05 | não | alta | Jaspion | [x] |
| T-07 | backend | Implementar cache, lock e fila Redis com retry/dead-letter | T-03 | sim | alta | Tesla | [x] |
| T-08 | backend/infra | Implementar storage S3-compatible e profile MinIO local | T-07 | não | alta | Tesla | [x] |
| T-09 | frontend | Criar design system mínimo, estados e breakpoints acessíveis | T-01 | sim | alta | Aang | [x] |
| T-10 | infra | Implementar logs JSON, correlation ID, métricas e error tracking configurável | T-03 | sim | alta | Tesla | [x] |
| T-11 | shared | Criar testes unitários, integração e matriz de isolamento entre dois tenants | T-04,T-06,T-08,T-09,T-10 | não | alta | Levi | [x] |
| T-12 | infra | Preparar CI/CD, build versionado, configurações de ambientes e runbooks | T-11 | não | alta | Naruto | [x] |
| T-13 | shared | Revisar segurança, executar quality gate e comprovar critérios da Fase 0 | T-12 | não | alta | Levi | [x] |

## Legenda
- [ ] pendente  · [~] em andamento  · [x] concluído  · [!] bloqueado
