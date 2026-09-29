# Central Vet migrations

Arquivos numerados deste diretório são artefatos auditáveis e não são
executados automaticamente pela aplicação.

Procedimento obrigatório:

1. validar versão, banco-alvo e precondições;
2. gerar e testar um backup;
3. calcular o SHA-256 do SQL aprovado e substituir o placeholder do registro;
4. apresentar efeito e risco e obter autorização SQL específica;
5. aplicar uma única vez com o usuário de migration dedicado (`MIGRATION_DB_USER`/
   `MIGRATION_DB_PASSWORD` em `.env`, privilégio mínimo em `centralvet`.* apenas)
   — nunca com o usuário root nem com o usuário de runtime da aplicação;
6. executar o arquivo `.verify.sql`, que contém somente `SELECT`.

DDL MySQL não é transacional. Em falha parcial, interrompa e inspecione
`information_schema`; não faça rollback destrutivo ou nova tentativa automática.
