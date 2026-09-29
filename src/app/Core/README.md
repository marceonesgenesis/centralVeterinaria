# Central Vet Core

Código novo do produto usa o namespace `CentralVet\` e autoload PSR-4. O
template Adianti permanece no layout legado para evitar uma migração invasiva.

Módulos de negócio devem seguir a separação:

- `Application`: casos de uso, comandos e DTOs;
- `Domain`: entidades, regras e contratos de repositório;
- `Infrastructure`: persistência e integrações;
- `Presentation`: adaptadores HTTP, CLI e Adianti.

Controllers/TPage podem chamar serviços de aplicação. Services, domínio e
repositórios nunca dependem de `TPage` nem devem derivar tenant de parâmetros da
requisição. O contexto autenticado é sempre injetado.
