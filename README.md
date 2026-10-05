# Central Vet Pro

## Planejamento do produto

O [plano de desenvolvimento atualizado](.docs/plan/centralvet-roadmap.md)
complementa o PRD v1.1 com a visão de ecossistema veterinário integrado, a
Central Intelligence (piloto em Imperatriz/MA) e o Ei, Vet!. Consulte a [especificação do módulo](.docs/prd/central-intelligence.md),
a [pesquisa de mercado e tecnologia](.docs/research/central-intelligence-mercado-tecnologia.md)
e a [proposta de arquitetura](docs/architecture/adr/0004-central-intelligence-analytical-boundary.md).
O [PRD Ei, Vet!](.docs/prd/ei-vet.md) descreve a conexão entre clínicas e
veterinários disponíveis para serviços avulsos; a [ADR do ecossistema](docs/architecture/adr/0005-veterinary-network-boundary.md)
separa a colaboração na rede dos dados privados de cada clínica.
Esses documentos registram trabalho futuro; os novos módulos ainda não foram implementados.

Base local de infraestrutura para o Central Vet Pro, conforme o PRD v1.1. Esta
etapa oferece o Adianti Framework/Template 8.6 sobre PHP 8.4-FPM, Nginx,
MySQL 8, Redis 7 e um processo de worker. As imagens usam versões patch fixas e
a extensão Redis do PHP também é fixada. O schema de negócio do Central Vet
Pro ainda será implementado nas próximas etapas.

## Pré-requisitos

- Docker Engine com o plugin Docker Compose
- `curl`, `openssl` e `make` no host (os comandos também podem ser executados sem Make)
- Porta local configurada em `HTTP_PORT` livre; o padrão é `8081`

## Configuração inicial

Crie o arquivo local, que é ignorado pelo Git:

```bash
cp .env.example .env
```

Substitua obrigatoriamente os valores `change-this-*` de `.env`. `APP_SEED` é
obrigatório, deve ter ao menos 32 caracteres e não aceita o placeholder do
exemplo; a aplicação falha de forma segura quando ele está ausente ou fraco. Para gerar
credenciais locais fortes:

```bash
openssl rand -hex 24
```

Após o bootstrap autorizado do Adianti, a senha inicial aleatória do administrador
fica somente no `.env` local, sob `CENTRALVET_ADMIN_PASSWORD`. O arquivo tem modo
`600` e é ignorado pelo Git. Não copie essa variável para imagens, logs ou commits.

Valide e construa a stack:

```bash
docker compose config --quiet
docker compose build
```

Inicie apenas quando houver autorização para inicializar os volumes de dados:

```bash
docker compose up -d
docker compose ps
curl --fail http://127.0.0.1:8081/live
curl --fail http://127.0.0.1:8081/health
```

`/live` confirma Nginx/PHP. `/health` executa verificações somente-leitura
(`SELECT 1` e `PING`) em MySQL e Redis. MySQL e Redis não publicam portas no
host; somente o Nginx é exposto em `127.0.0.1`.

O build resolve as dependências exclusivamente a partir de `composer.lock`, sem
pacotes de desenvolvimento, em um estágio isolado. Código e dependências são
copiados para a imagem imutável; o runtime não depende de um `vendor/` produzido
manualmente no host. Alterações em `src/` exigem reconstruir a imagem da aplicação.

## Operação

```bash
make ps
make logs
make diagnose
docker compose restart app worker nginx
docker compose down
```

`docker compose down` preserva os volumes. Não use `down -v` sem confirmar que
os dados podem ser descartados. Logs Docker têm rotação de 10 MB, com três
arquivos por serviço.

## Backup e restauração

Crie um dump consistente do banco em `var/backups/`:

```bash
make backup
```

Teste o arquivo sem restaurá-lo:

```bash
gzip -t var/backups/centralvet-AAAAmmddTHHMMSSZ.sql.gz
```

A restauração altera o banco e exige autorização explícita. Depois de obtê-la,
execute:

```bash
CONFIRM_RESTORE=RESTORE_CENTRALVET ./scripts/restore.sh var/backups/arquivo.sql.gz
```

Backups locais são ignorados pelo Git. Em produção, eles devem ser criptografados,
copiados para armazenamento externo e submetidos regularmente a teste de
restauração, de acordo com o RPO/RTO definido no PRD.

Limitações desta rotina de desenvolvimento:

- o backup é um dump lógico apenas do MySQL; volumes Redis não são incluídos;
- não há criptografia, envio para storage externo, retenção automática ou PITR;
- backup e restore exigem que o serviço MySQL esteja em execução e usam a
  credencial root disponível somente dentro do container;
- o restore não cria snapshot anterior nem oferece rollback automático;
- `gzip -t` valida o arquivo, mas um teste real de recuperação exige restaurá-lo
  em um banco descartável e conferir a aplicação, operação ainda não automatizada.

## CI/CD e runbooks

O pipeline em `.github/workflows/ci.yml` builda `app`/`worker`, sobe
`mysql`/`redis`/`app`/`worker` e roda `php tests/run.php` dentro do
container — sem publicar imagem nem se conectar a nenhum registry externo.
Ele está pronto para ativar sozinho quando o repositório remoto existir.

Procedimentos práticos (subir o ambiente, rodar testes, aplicar/reverter
migration, restaurar backup, estratégia de build versionado e diferenças por
ambiente) estão em [`docs/runbooks/`](docs/runbooks/README.md).

## Estrutura

- `src/app/`: aplicação, controles, modelos, serviços, templates e configurações Adianti
- `src/lib/`: runtime do Adianti Framework 8.6 e bibliotecas web
- `src/vendor/`: dependências Composer fornecidas pelo template de origem
- `src/index.php`, `src/engine.php`: entrypoints web do template
- `src/app/database/*.sql`: schemas de referência do template; não são executados automaticamente
- `src/`: demais bootstrap, configuração e worker provisório
- `docker/`: imagem PHP e configuração Nginx/PHP-FPM
- `scripts/`: diagnóstico, backup e restauração
- `var/`: artefatos locais não versionados

O worker atual fornece ciclo de vida e heartbeat para validar a infraestrutura.
A integração real com a fila será implementada junto ao domínio da aplicação.

O template foi importado de `/var/www/html/templete8.6`. Os arquivos SQLite de
exemplo não foram copiados. As conexões `permission`, `communication`, `log`,
`sample` e unidades usam as variáveis `DB_*` do Compose e apontam para o banco
MySQL isolado do projeto. Antes do primeiro login, os schemas SQL necessários
deverão ser revisados e aplicados somente com autorização explícita.

A migration
`src/app/database/migrations/20260919_add_missing_adianti_foreign_keys.sql`
foi aplicada com autorização em 2026-09-20. Ela corrigiu 15 referências inline
de `communication.sql` e uma de `permission.sql`, adicionando 16 FKs `RESTRICT`
e o índice `sys_users_unit_idx`. Os prechecks e pós-checks confirmaram zero
órfãos; o schema resultante possui 30 FKs e 88 índices não primários.

### Diretórios graváveis

O root filesystem e o código em `src/` permanecem somente leitura. Apenas estes
caminhos são graváveis pelo usuário `www-data`:

- `src/tmp`: `tmpfs` efêmero, `noexec`, `nosuid` e `nodev`, limitado a 32 MB;
- `src/app/output`: `tmpfs` efêmero com as mesmas proteções, limitado a 64 MB;
- `src/files`: volume persistente `app_files`, destinado a uploads. O Nginx
  bloqueia integralmente acesso HTTP direto a esse caminho; downloads autenticados
  passam somente por `download.php`, como anexo binário e com `nosniff`.

Os dois `tmpfs` são descartados ao recriar o container. `app_files` sobrevive a
`docker compose down`; sua remoção exige apagar explicitamente o volume.

Volumes nomeados do Docker não oferecem uma forma portável no Compose de aplicar
`noexec`, `nosuid` e `nodev` sem depender de opções específicas do driver/host.
Por isso `app_files` continua como named volume persistente. A mitigação combina:
nenhum mount desse volume no Nginx, downloads somente pelo controlador autenticado,
root filesystem somente leitura, processo PHP como `www-data`, todas as capabilities
removidas e `no-new-privileges`. Não se deve carregar/incluir código PHP a partir de
`files/`.
