## Cruzada onda 10 (BASE 87a40c6..HEAD, T-41, T-50, T-51, T-52)
- ok: LINT: 26 arquivos PHP tocados (src/app/Core, control/clinic, tests) -> "No syntax errors detected" em 26/26
- ok: SUITE: php tests/run.php -> "Total: 365, Passed: 365, Failed: 0, Skipped: 0" (>= 205 + novos)
- ok: translations.json: 0 duplicatas, 0 duplicatas por caixa, 0 pt vazio; 4 chaves novas da onda presentes; 3 referenciadas no código, "Attachment not found" sem referência em src/app (chave órfã, não reprova); varredura de chaves _t/UserMessage das linhas adicionadas sem faltantes (8 capturas falsas: dados de teste como r2-object-a, encounter/10/, e a mensagem de exceção de teste de conflito)
- ok: grep -rn SABOTAGEM src/ (sem vendor/tmp) -> vazio
- ok: grep -rln "function toCents" src/app/control -> vazio
- ok: information_schema.statistics -> centralvet.queue_entry queue_entry_appointment_uq non_unique=0 (appointment_id)
- nota: varredura Playwright fora do escopo desta validação (sem navegador)
- [ruling] validador: chave "Attachment not found" (8a4e93a) sem uso em src/app; sugestão, não bloqueia
