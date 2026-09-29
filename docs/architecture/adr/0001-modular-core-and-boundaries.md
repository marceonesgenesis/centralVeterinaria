# ADR 0001: Núcleo modular e limites de dependência

- Status: aceito
- Data: 2026-09-20

## Contexto

O produto nasce sobre o Adianti 8.6, mas Services e Repositories também serão
consumidos por REST, workers e MCP. Acoplá-los a `TPage` duplicaria regras e
impediria testes unitários isolados.

## Decisão

Código Central Vet usa `CentralVet\` via PSR-4 em `app/Core`. Casos de uso
implementam contratos de Application, persistência implementa contratos do
Domain e somente a camada Presentation conhece controllers Adianti. Novos
bounded contexts seguem `Application`, `Domain`, `Infrastructure` e
`Presentation`; o template legado continua funcionando sem renomeação.

## Consequências

Há uma camada de adaptação inicial entre TRecord/TTransaction e os contratos.
Em troca, regras ficam reutilizáveis e testáveis sem sessão HTTP ou UI.
