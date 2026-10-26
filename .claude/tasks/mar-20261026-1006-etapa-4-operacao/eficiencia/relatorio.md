# Eficiência — mar-20261026-1006-etapa-4-operacao

- Plano: `/var/www/html/centralvet/.claude/tasks/mar-20261026-1006-etapa-4-operacao` · projeto `/var/www/html/centralvet` · versão 2.7.0? · gerado em 2026-10-26T14:46:21-03:00 · última onda medida 6
- Sessões: b5eee13a-a154-4bef-8500-0b8809d420de (encontrada)
- Base: sem base (nenhum plano da versão anterior no acervo)

## Indicadores

| Indicador | Este plano | Base |
|---|---|---|
| Tokens retidos por onda (mediana) | 19419,50 | sem base |
| Contexto máximo | 318.981 | sem base |
| Compactações | 0 | sem base |
| Correções por implementador | 0,29 | sem base |
| Retornos acima do limite (%) | 13 | sem base |
| Validador acima da meta (%) | 0 | sem base |
| Aprovadas de primeira (%) | 71 | sem base |
| Precisão do mapa de arquivos (%) | 95 | sem base |
| Rodadas de fix loop | 5 | sem base |
| Tasks de correção pós-Fase 5 (%) | 15 | sem base |

## Tasks

| Task | Onda | Origem | Complexidade | Status | RED | Gate | Fix loop | Revisor (bloq./sug.) | Perguntas | Desvios | Fora do previsto |
|---|---|---|---|---|---|---|---|---|---|---|---|
| T-01 | 1 | plano | simples | [x] | sem teste | 0 | 1 | 2/1 | 0 | 0 | — |
| T-02 | 1 | plano | média | [x] | ok | 0 | 0 | 0/2 | 0 | 1 | — |
| T-03 | 1 | plano | média | [x] | ok | 0 | 0 | 0/3 | 0 | 3 | — |
| T-04 | 1 | plano | média | [x] | inválido | 0 | 0 | 0/2 | 0 | 2 | — |
| T-05 | 1 | plano | média | [x] | ok | 0 | 1 | 1/3 | 0 | 4 | — |
| T-06 | 1 | plano | média | [x] | ok | 0 | 1 | 1/3 | 0 | 6 | src/tests/Integration/BillingAcquisitionFlowIntegrationTest.php |
| T-07 | 2 | plano | média | [x] | ok | 0 | 0 | 0/0 | 0 | 0 | — |
| T-08 | 2 | plano | alta | [x] | ok | 0 | 0 | 0/4 | 0 | 6 | — |
| T-09 | 2 | plano | média | [x] | ok | 0 | 0 | 0/4 | 0 | 2 | — |
| T-10 | 2 | plano | média | [x] | ok | 0 | 0 | 0/2 | 0 | 3 | — |
| T-11 | 3 | plano | alta | [x] | ok | 0 | 0 | 0/6 | 0 | 6 | — |
| T-12 | 3 | plano | alta | [x] | ok | 0 | 0 | 0/3 | 0 | 4 | — |
| T-13 | 3 | plano | média | [x] | ok | 0 | 0 | 0/0 | 0 | 2 | — |
| T-14 | 4 | plano | alta | [x] | ok | 0 | 0 | 0/6 | 0 | 4 | — |
| T-15 | 4 | plano | alta | [x] | sem teste | 0 | 0 | 0/4 | 0 | 5 | — |
| T-16 | 5 | plano | média | [x] | sem teste | 1 | 1 | 0/2 | 0 | 3 | docs/runbooks/cirurgia.md, docs/runbooks/internacao.md, docs/runbooks/landing-leads.md, docs/runbooks/migrations.md |
| T-17 | 2 | plano | alta | [x] | ok | 0 | 1 | 1/4 | 0 | 3 | — |
| T-18 | 6 | correcao | alta | [x] | ok | 0 | 0 | 0/4 | 0 | 5 | — |
| T-19 | 6 | correcao | simples | [x] | ok | 0 | 0 | 0/4 | 0 | 4 | — |
| T-20 | 6 | correcao | simples | [x] | ok | 0 | 0 | 0/2 | 0 | 1 | — |

## Causas

- T-01: timestamp NOT NULL sem DEFAULT ganharia ON UPDATE no MySQL 5.7; DEFAULT CURRENT_TIMESTAMP(6) explícito
- T-03: MigrationStatus::lines() público e shell com SELECT por -e: desvios aditivos do contrato
- T-04: chaves pt conferidas no PasswordResetMailerTest e FakeSmtpServer por proc_open: desvios previstos
- T-05: --image usava o Dockerfile relativo ao cwd, não o do export do commit; build movido para o export
- T-06: runbook citava calls[1] ainda usado no teste do cenário (c); escopo extra: filtro por path e limpeza de FKs
- T-08: backup.sh passou de sh para bash e parts= passou a contar o MANIFEST; sugestões de hermetismo e snapshot do inventário
- T-09: Extras aditivos (proxies ilegível, 5.7 sem strict, argumento inválido) e dublê de PDO no próprio teste
- T-10: Testes e fake extras aditivos; relatório cita hash errado da implementação (31343ab em vez de ce97252)
- T-11: Restore recria o banco e substitui files/minio; desvios aditivos declarados (timings, manifest copy, decrypt com src :ro)
- T-12: StorageException na montagem do storage tratada como storage_unavailable (código 2), desvio do comando do critério
- T-14: Suíte sem mount de src/ (vendor ignorado no checkout) e schema aplicado também ao centralvet efêmero da CI; aceitos pelo validador
- T-15: umask 077 vazou para build-release.sh e a imagem subiu unhealthy; corrigido com umask 022 antes do commit
- T-16: Gate reprovou: runbooks sem o --user 1000:33 do hosting-backup; corrigido em 8cf19e5 com escopo extra nos runbooks com gzip -t.
- T-17: Cópia externa com falha gravava heartbeat e imprimia sucesso, divergindo da T-08; corrigido para sair 4 antes do heartbeat
- T-18: Revisão final apontou restore com projeto vivo e crons concorrentes; desvios aditivos (CENTRALVET_MAINTENANCE_DIR, flag pré-existente)
- T-19: Revisão final apontou CI_WORKDIR sem guarda; mensagem de recusa e resolução lexical acrescentadas ao contrato
- T-20: Revisão final apontou umask herdada no build; build real com SHA fixo em vez de HEAD
