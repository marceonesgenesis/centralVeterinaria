# Plano: Conclusão da Fase 0 — Fundação e desenho

## Objetivo
Completar a fundação segura e deployável do Central Vet Pro sobre Adianti 8.6,
sem antecipar cadastros, agenda ou fluxos clínicos da Fase 1. O encerramento exige
isolamento multi-tenant comprovado, segurança, auditoria, serviços de plataforma,
testes automatizados e pipeline reprodutível.

## Escopo
### Incluso
- Arquitetura modular, ADRs e autoload PSR-4 para código Central Vet.
- Schema de fundação: tenants, unidades, vínculos, migrations, auditoria e objetos armazenados.
- `TenantContext` fail-closed e isolamento por tenant/unidade.
- Sessões Redis, hardening de autenticação e rate limiting.
- Serviço central de RBAC reutilizável por UI, Services e futura API.
- Auditoria correlacionada, logs JSON e tratamento seguro de erros.
- Cache, locks e fila Redis com worker, retry e dead-letter.
- Storage S3-compatible, com MinIO opcional para desenvolvimento.
- Design system mínimo e responsivo sobre o tema Adianti existente.
- Testes unitários, integração, segurança multi-tenant e E2E essencial.
- Pipeline GitHub Actions, imagens versionáveis e runbooks.

### Excluído
- Tutores, pacientes, agenda e demais funcionalidades da Fase 1.
- Domínio, DNS, certificado TLS e publicação em produção.
- Contratação/configuração real de S3/R2, Sentry ou provedor externo.
- MCP, AI Gateway e integrações comerciais.

## Decisões de arquitetura
| Decisão | Alternativas consideradas | Motivo da escolha |
|---|---|---|
| Banco compartilhado com `tenant_id` obrigatório | Banco/schema por tenant | Segue o PRD e simplifica operação inicial sem abrir mão do isolamento lógico |
| `system_unit` representa filial, não tenant | Reutilizar unidade como tenant | Evita misturar clínica/organização com unidade operacional |
| Contexto derivado da sessão no servidor e fail-closed | Aceitar tenant por parâmetro do cliente | Impede troca arbitrária de tenant e IDOR horizontal |
| Services/Repositories independentes de `TPage` | Regras dentro das telas | Prepara REST e MCP sem duplicar regra de negócio |
| Redis para sessão, rate limit, cache, locks e fila | Sessão em arquivos e worker fictício | Centraliza estado efêmero e permite escala horizontal |
| Adapter S3-compatible + MinIO em profile local | Volume local como storage definitivo | Mantém portabilidade para S3/R2 e testes locais reproduzíveis |
| GitHub Actions preparado, sem publicação remota | CI dependente de outro provedor | É auditável agora e ativável quando o repositório remoto existir |
| Tokens visuais neutros e acessíveis | Definir branding definitivo agora | Fecha a fundação sem inventar identidade de marca não fornecida |

## Checkpoints de autorização
- Migrations e seeds serão preparados e revisados sem execução.
- Antes de qualquer DDL/DML no MySQL será solicitada autorização SQL específica,
  com objetos, impacto, backup e risco.
- Testes de login/auditoria que gravem dados também exigirão autorização específica.

## Diagrama de dependências
T-01 → T-02 → T-03 → T-04 → T-05 → T-06
                 T-03 → T-07 → T-08
T-01 → T-09
T-03 → T-10
T-04 → T-11
T-05 → T-11
T-06 → T-11
T-08 → T-11
T-09 → T-11
T-10 → T-11
T-11 → T-12 → T-13

## Agentes
| Agente | subagent_type | Tasks | Pode paralelizar com |
|---|---|---|---|
| Sun Tzu | — | orquestração e checkpoints | — |
| Sherlock | explorer | T-01 | — |
| Athena | general-purpose | T-02, T-03, T-04 | Tesla após T-03 |
| Jaspion | general-purpose | T-05, T-06 | Tesla e Aang |
| Tesla | general-purpose | T-07, T-08, T-10 | Jaspion e Aang |
| Aang | general-purpose | T-09 | Jaspion e Tesla |
| Levi | reviewer | T-11, T-13 | — |
| Naruto | general-purpose | T-12 | — |

