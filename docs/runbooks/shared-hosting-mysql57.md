# Hospedagem compartilhada com MySQL 5.7

As migrations originais continuam destinadas a MySQL 8. Para um banco vazio
MySQL 5.7, prepare uma variante privada a partir dos arquivos de bootstrap
sanitizados e das migrations numeradas, ordenados em um diretório privado:

```sh
python3 scripts/prepare-mysql57.py var/sql-bootstrap/online var/sql-bootstrap/mysql57
python3 scripts/test-prepare-mysql57.py
```

O preparador não conecta ao banco. Gera SQL para o cliente `mysql`, arrays de
comandos JSON para PDO e um manifesto. Substitui `utf8mb4_0900_ai_ci` por
`utf8mb4_unicode_ci`, fixa InnoDB/utf8mb4 nas tabelas de bootstrap e substitui
cada CHECK por dois triggers BEFORE INSERT/UPDATE que usam SIGNAL SQLSTATE
45000. A condição `IS FALSE` mantém a semântica de CHECK: NULL não é uma
violação. Índices, chaves estrangeiras e seeds são preservados. A comparação
de textos usa as regras Unicode disponíveis no MySQL 5.7, que podem diferir
da collation Unicode 9 usada no MySQL 8.

O checksum de cada etapa identifica seu array JSON canônico antes do
preenchimento do próprio checksum no INSERT de `schema_migrations`. Guarde
o manifesto e os artefatos junto ao relatório de implantação.

Em 2026-10-02, foram instalados no banco online `marceo22_centralvet` os três
schemas iniciais do Adianti, a correção de FKs e as migrations 0001–0009:
80 tabelas, 31 validações e 62 triggers. O banco foi confirmado vazio antes
da instalação; o snapshot e os relatórios privados ficam em
`var/sql-bootstrap/mysql57/`. As verificações SELECT são adaptadas para
consultar TRIGGERS quando o MySQL 8 usa `information_schema.check_constraints`.

## Configuração da aplicação

`src/init.php` carrega opcionalmente `app/config/hosting-environment.php`.
Esse arquivo privado deve definir APP_SEED, DB_HOST, DB_PORT, DB_DATABASE,
DB_USERNAME, DB_PASSWORD, APP_ENV, APP_DEBUG e APP_TIMEZONE usando `putenv()`.
Não versionar esse arquivo. Bloquear acesso HTTP aos diretórios `app/config`
e `app/database` com `.htaccess` (`Require all denied`).

Defina `DB_STRICT_MODE=true` para que cada conexão da aplicação use o modo
SQL estrito, independentemente da configuração global da hospedagem. Isso
evita truncamentos silenciosos e conversões inválidas de valores/datas.

Para login em um único servidor sem Redis, defina `SESSION_DRIVER=files` e
`RATE_LIMIT_DRIVER=files`. O limitador grava contadores com TTL e `flock()` em
um diretório privado do sistema temporário; falhas de leitura ou gravação
interrompem a operação. Opcionalmente fixe `RATE_LIMIT_DIRECTORY` fora da
raiz pública. O backend padrão continua Redis; nenhum fallback automático
ocorre. Filas, registro distribuído de sessões e tokens da landing continuam
dependendo de Redis e exigem infraestrutura/configuração própria.

O arquivo `src/.htaccess` define `landing.php` como DirectoryIndex do Apache.
Em `/centralvet/`, visitantes anônimos abrem a landing, e o botão Entrar
abre `index.php?class=LoginForm`. Assets, login, endpoint de leads e
redirecionamentos da landing usam caminhos relativos, compatíveis com
instalação na raiz do domínio ou em um subdiretório. Sessões autenticadas e
pedidos com query string seguem para `index.php` no mesmo diretório.

A landing oferece Português, English e Español nos seletores do cabeçalho
e do carrinho. `landing/translations.js` contém as traduções de textos,
planos, comparativo, FAQ, formulário e mensagens; `landing/i18n.js` aplica
o idioma e guarda a preferência em `localStorage` (`cvp-language`). O padrão
é português. A troca mantém o plano selecionado e os campos preenchidos.
Os preços continuam em BRL e recebem a formatação numérica do idioma.

A ação Criar conta do login abre `landing.php#cart`. A landing reconhece
esse fragmento e abre o carrinho automaticamente: um plano salvo mostra
o formulário de ativação; um carrinho vazio oferece a seleção de planos.

Validação no navegador, com Playwright disponível:

```sh
PLAYWRIGHT_MODULE=/caminho/para/playwright node scripts/test-landing-i18n.cjs https://zionsystem.com.br/centralvet/
```

O teste intercepta o envio de leads no navegador; nenhuma solicitação de
teste é gravada no banco online.

## Execução pela hospedagem

Quando não há acesso SSH nem MySQL remoto, `scripts/mysql57-runner.php` é um
template de executor temporário. Substitua os marcadores de token aleatório,
expiração e diretório privado localmente; publique com nome aleatório e
remova após conferir a instalação. Aceita somente POST com token em header,
confere o banco-alvo, executa somente etapas do manifesto em ordem, rejeita
reaplicações e bloqueia continuidade após falha ou interrupção. Não deixe
o executor acessível após a implantação. Não publique senhas ou SQL por
HTTP; o bundle deve estar sob o diretório protegido `app/database`.

Executar somente no escopo SQL autorizado. Em falha parcial, não tentar
novamente nem fazer rollback destrutivo automaticamente: o DDL MySQL não
é transacional. Para bancos com dados, este procedimento inicial recusa a
instalação; uma atualização exige revisão específica e backup completo.

Para novas migrations, adicionar os artefatos à preparação e revisar a
compatibilidade com MySQL 5.7 antes de executar. Este bootstrap inicial não
é um mecanismo de atualização automática de bancos já existentes.

Os schemas iniciais do Adianti não cadastram automaticamente os controladores
de negócio do Central Vet. O seed de programas/permissões separado está em
`src/app/database/seeds/initial-application-programs.sql`. Ele cadastra 45
controladores, libera-os somente para o grupo administrador e configura a
permissão de desconto por método. Não contém dados clínicos de demonstração.

Esse seed foi aplicado no banco online com autorização específica em
2026-10-02, dentro de uma transação, após guardar e validar o snapshot das
seis tabelas afetadas. A verificação pelo modelo SystemUser confirmou os 45
programas liberados e `EncounterAccountForm.onApplyDiscount` autorizado.
As contagens dos registros de negócio foram preservadas. Após novo login,
dez telas abriram sem erros: tutores, pacientes, agenda, fila, produtos,
visão financeira, serviços, leads, sessões de caixa e recebíveis pendentes.
O executor temporário e o bundle remoto foram removidos; os relatórios
privados ficam em `var/sql-bootstrap/mysql57/`.

Em novas instalações, aplicar esse seed depois das migrations 0001–0009,
com autorização específica para as permissões. A aplicação não o executa
automaticamente.
