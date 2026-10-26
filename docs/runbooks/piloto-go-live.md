# Piloto: checklist de go-live

Marque cada item com data e responsável. Sem credencial real aqui; valores vêm do `.env` do ambiente. Todo comando de escrita em banco exige autorização explícita antes de rodar.

## 1. Código e CI

- [ ] `scripts/ci/run.sh` verde no commit do release ([`build-versioning.md`](./build-versioning.md)).
- [ ] Release construído por commit fixo e `RELEASE_MANIFEST` conferido ([`release.md`](./release.md)).
- [ ] Roteiro de [`piloto-homologacao.md`](./piloto-homologacao.md) concluído nos 2 tenants.

## 2. Preflight

- [ ] `php bin/preflight.php --with-db` sem FAIL (ver [`release.md`](./release.md) § Preflight), incluindo o achado `support_email` (`SUPPORT_EMAIL` válido, sem `.invalid`).
- [ ] `operator_email`, `smtp_driver`, `smtp_from`, `stripe_secret_key`, `stripe_webhook_secret` sem FAIL.

## 3. Banco

- [ ] Backup recente antes de qualquer escrita ([`restore-backup.md`](./restore-backup.md)).
- [ ] Migrations 0001–0020 aplicadas (com autorização): `scripts/db/migration-status.sh` (ou `php bin/migration-status.php`) imprime `pending=0 unknown=0`.
- [ ] Seeds aplicados **com autorização**, nesta ordem, cada um pode rodar mais de uma vez:
  1. `src/app/database/seeds/initial-application-programs.sql`
  2. `src/app/database/seeds/plan-groups.sql`
  3. `src/app/database/seeds/role-groups.sql`
- [ ] Depois de reaplicar o seed inicial, conferir que os perfis `Central Vet - Perfil %` não receberam os programas de IA.

## 4. Configuração do piloto

- [ ] `OFFER_PLANS` definido (padrão: os 4 planos).
- [ ] `OFFER_PREVIEW_FEATURES` definido (vazio no piloto: `communication`, `hospital` e `ai_advanced` ficam fora do recorte).
- [ ] `SUPPORT_EMAIL` (obrigatório), `SUPPORT_WHATSAPP` (opcional) e `SUPPORT_HOURS` (padrão: segunda a sexta, das 8h às 18h).
- [ ] `DATA_RETENTION_DAYS_AFTER_CANCEL` definido (padrão 30).
- [ ] Variáveis conferidas em [`environments.md`](./environments.md).

## 5. Operação

- [ ] Backup e restauração ensaiados com RTO e RPO medidos ([`ensaio-restauracao-rollback.md`](./ensaio-restauracao-rollback.md)).
- [ ] Crons instalados e todos chamados por `scripts/cron-run.sh` ([`cron.md`](./cron.md)): reconciliação de cobrança, `ops-monitor`, retenções, `document-sweep` e backup.
- [ ] Flag de manutenção ausente (um flag esquecido desliga backup e alertas).
- [ ] `ops-monitor` com e-mail do operador recebendo alerta de teste ([`observabilidade.md`](./observabilidade.md)).

## 6. Cobrança e e-mail

- [ ] Stripe em modo live, com webhook apontando para o ambiente e segredo configurado ([`smoke-billing.md`](./smoke-billing.md)).
- [ ] SMTP real configurado; e-mail de convite chega na caixa de teste.

## 7. Jurídico

- [ ] Textos de Termos de Uso e Política de Privacidade revisados pelo jurídico; a marca `RASCUNHO — revisar com jurídico` removida.
- [ ] Horário de suporte nos textos legais confere com `SUPPORT_HOURS`.

## 8. Fumaça pós-deploy

- [ ] `legal.php?doc=termos` e `legal.php?doc=privacidade` respondem 200 e `doc` inexistente responde 404.
- [ ] Login, "Primeiros passos", "Ajuda" e a página de planos abrem.

Go-live aprovado quando todos os itens estão marcados e sem bloqueio aberto.
