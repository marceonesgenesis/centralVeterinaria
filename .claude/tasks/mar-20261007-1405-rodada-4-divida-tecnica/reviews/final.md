# Revisão final
## Triagem
- [aberta] `DocumentRequestService::download` continua em `DOCUMENT_STORAGE_DRIVER` → fora do escopo (Excluído); a branch não toca o download de documentos
- [aberta] E2E de upload/download de anexo com `STORAGE_DRIVER=local` fora do plano → não executado; depende de SQL aprovado
- [aberta] T-01: parâmetro `$secondaryFactory` em vez de `$secondary` → src/app/Core/Storage/FallbackReadStorage.php:27 (sem impacto posicional)
- [aberta] T-01: com driver local e raiz ausente, `$primary->exists()` lança e a foto legada no S3 fica ilegível → src/app/Core/Storage/FallbackReadStorage.php:39; `PatientForm::onPhoto` captura e devolve 404 (PatientForm.php:586-590); a branch não agravou
- [aberta] T-01: `testFallbackDeleteGoesToSecondary...` usa FakeStorage cujo delete nunca lança; falta teste ponta a ponta de `forPatientPhotos()->get` → src/tests/Unit/StorageFactoryTest.php:189-205
- [aberta] T-02: `testRefusedDownloadNeverResolvesAReader` não cobre anexo de outro encontro (fora do prefixo) → src/tests/Unit/EncounterDocumentServiceTest.php:278-298
- [aberta] T-05: `findById` sem teste de template de outro tenant; `testDeniedFindByIdThrows` não verifica que nada foi lido nem o entityId pedido → src/tests/Unit/DocumentTemplateServiceTest.php:282-287
- [resolvida] Onda 1: SUITE 1180/1180 no gate → registro de gate, sem ação
- [resolvida] Onda 2: SUITE 1186/1186 no gate → registro de gate, sem ação
- [aberta] T-08: medida de 44 px no navegador pendente para o próximo deploy em dev → ordem de carga confirmada (layout.html:27-28, cv-components.css depois de custom.css) e TopbarTouchTargetTest PASS; medida real não feita
- [aberta] T-08: E2E de anexo com `STORAGE_DRIVER=local` não executado → mesma pendência do E2E acima
- [resolvida] Onda 3: SUITE 1186/1186 (relatório da T-08) → registro, sem ação; ver achado sobre a SUITE não reproduzida pelo revisor
## Rulings
- plano · onda 0 — Branch `feat/rodada-4-divida-tecnica` (nome confirmado pelo usuário), base `main`
- plano · onda 0 — `StorageFactory` lê `STORAGE_DRIVER` (`local` → LocalFilesystemStorage; outro valor → S3 com esse provider; vazio → `s3` só com `S3_ENDPOINT` e `S3_BUCKET`, senão `local`); raiz `STORAGE_LOCAL_ROOT` → `DOCUMENT_STORAGE_LOCAL_ROOT` → default
- plano · onda 0 — Leitura de objetos antigos: anexos indexados pelo `stored_object.storage_provider`; foto via `FallbackReadStorage`; sem migration
- plano · onda 0 — `forWrites` preguiçoso (`LazyStorage`)
- plano · onda 0 — Deadlock 1213 dentro de transação: não tratar nesta rodada
- plano · onda 0 — Pendências da 7B já resolvidas no código, sem task
- plano · onda 0 — Cabeçalho: só a busca global (T-07) faltava para 44 px
- plano · onda 0 — Baseline do php-lint com 0 linhas
- T-01 · onda 1 — `FallbackReadStorage` memoiza também a falha ao resolver o secundário
- orquestrador · onda 1 — Ocorrências de `fromEnvironment` nos controllers esperadas até a T-03; sem fix loop
- T-08 · onda 1 — E2E de anexo local pendente de resposta do usuário
- orquestrador · onda 2 — Validação cruzada do grep `fromEnvironment` fecha (exit=1)
- T-06 · onda 2 — `git commit --amend --only` só de mensagem (fe565fb→c761b2a) aceito
- T-03 · onda 2 — Commit RED d511f1f sem Co-Authored-By aceito
- T-08 · onda 3 — Medida de 44 px no navegador aceita como pendência; cobertura por TopbarTouchTargetTest (ruling do usuário)
- T-08 · onda 3 — E2E de anexo local não executado; driver local coberto por StorageFactoryTest/AttachmentStorageWiringTest
- orquestrador · onda 3 — Sem gate do validador (HEAD = BASE); evidência do relatório da T-08
## Achados
- [sugestão] Premissa do plano diz que a branch nasceu de `origin/main` @ 0d5ac47, mas 0d5ac47 não está em `main` (merge-base 5e4b238): `git diff main...feat/rodada-4-divida-tecnica` inclui CLAUDE.md (§ Branches), fora do Mapa de arquivos; a entrega deve tratá-lo como alteração da branch → CLAUDE.md:10-16
- [sugestão] `driverFromEnvironment` normaliza com trim/strtolower, mas `S3CompatibleStorage::fromEnvironment` grava o provider cru de `getenv('STORAGE_DRIVER')`; `STORAGE_DRIVER=S3` grava `storage_provider` `S3` (a leitura funciona, pois qualquer não-`local` vai ao S3) → src/app/Core/Storage/S3CompatibleStorage.php:44
- [sugestão] O fallback da foto só vale no sentido S3→local: fotos gravadas no driver local ficam ilegíveis se o driver voltar a `s3`; vale uma linha no runbook → src/app/Core/Storage/StorageFactory.php:66-75, docs/runbooks/shared-hosting-mysql57.md:282-292
- [sugestão] SUITE inteira (1186/1186) só consta no relatório da T-08; o revisor reproduziu LINT dos 17 PHP tocados (todos `No syntax errors detected`) e 109/109 PASS nas 8 classes unitárias afetadas (StorageFactory, LocalFilesystemStorage, EncounterDocumentService, DocumentTemplateService, DocumentGenerationService, AttachmentStorageWiring, TopbarTouchTarget, PatientService), mas não a SUITE nem `DocumentTemplateScreensIntegrationTest` (integração escreve em centralvet_test) → docker compose run ... php tests/run.php
