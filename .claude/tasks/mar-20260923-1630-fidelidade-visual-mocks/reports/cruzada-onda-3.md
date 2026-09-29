# cruzada-onda-3 — bugs do gate da onda 3 sem task dona

## 1. SystemLogDashboard: cal_days_in_month() indefinida
- Arquivo real: `src/app/control/log/SystemLogDashboard.php` (não em `admin/`); não foi editado.
- Causa: imagem sem a extensão `calendar`. Evidência na imagem atual:
  `docker compose run --rm --no-deps -T app php -r 'var_dump(function_exists("cal_days_in_month"));'` → `bool(false)`.
- Correção: `calendar` adicionado em ordem alfabética à lista de `docker-php-ext-install` em `docker/php/Dockerfile` (não exige lib -dev).
- Pendente: rebuild da imagem pelo orquestrador; depois conferir `php -m | grep calendar` e abrir Configurações → Logs → Dashboard.

## 2. SystemPHPErrorLogView: array_slice() com false
- `framework_hashes.php`: `grep SystemPHPErrorLogView` → sem ocorrência; edição permitida.
- Causa: `docker/php/conf.d/app.ini` define `error_log = /proc/self/fd/2` (stderr do container). `file_exists`/`is_readable` passam, mas não é arquivo regular e `file()` devolve false.
  Evidência: `php -r 'var_dump(ini_get("error_log"), is_file("/proc/self/fd/2"), @file("/proc/self/fd/2"));'` → `"/proc/self/fd/2"`, `bool(false)`, `bool(false)`.
- Correção (linha 50): lê só se `is_file()`, com `@file()`; se o resultado não for array, `$data = []` → a grid renderiza vazia com o alerta de localização do log.
- Lint: `php -l /var/www/html/src/app/control/admin/SystemPHPErrorLogView.php` → `No syntax errors detected`.

## Observações
- Com `error_log` em stderr, a tela sempre ficará vazia (os logs vão para `docker compose logs app`). Mostrar logs de verdade exigiria apontar `error_log` para um arquivo (ex.: `/var/www/html/src/tmp/php-error.log`) — decisão de infraestrutura, fora deste escopo.
- O `is_writable($error_log)` mais abaixo pode exibir o aviso "not writable" para o pipe; é só alerta, sem erro.
