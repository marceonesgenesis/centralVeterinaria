# S5 — Dependências com avisos de segurança

Origem: `.docs/audits/2026-10-09-prontidao-mvp.md` § S5. Task T-04 da Etapa 3, com autorização do usuário para editar `src/composer.json` e regenerar a linha `composer.json` de `src/app/config/framework_hashes.php`.

## Versões

| Pacote | Antes | Depois | Restrição no `composer.json` |
|---|---|---|---|
| `dompdf/dompdf` | v3.1.5 | v3.1.6 | `^3.0` → `^3.1.6` |
| `firebase/php-jwt` | v6.11.1 | v7.2.1 | `^6.0` → `^7.0` |
| `masterminds/html5` (dep. do dompdf) | 2.10.1 | 2.11.0 | — |
| `sabberworm/php-css-parser` (dep. do dompdf) | v9.4.0 | v9.5.0 | — |
| `thecodingmachine/safe` | v3.4.0 | removido (deixou de ser exigido pelo css-parser; nenhum uso no app) | — |

Comando: `composer update dompdf/dompdf firebase/php-jwt --with-dependencies --no-interaction --no-scripts` (imagem `composer:2.8.11`). Nenhum outro pacote mudou.

## Avisos resolvidos

- `dompdf/dompdf` < 3.1.6: seis avisos (quatro médios, dois baixos), entre eles a leitura de arquivo local (GHSA-cx96-42px-69fm).
- `firebase/php-jwt` < 7.0.0: um aviso baixo de chave criptográfica fraca (GHSA-2x45-7fc3-mxwq).

`composer audit --locked` depois do update: `No security vulnerability advisories found.`

## Usos revisados

- Dompdf: `CentralVet\Document\DompdfDocumentRenderer` (documentos clínicos, HTML escapado, recursos remotos/PHP/JS desabilitados), `SaleForm` (recibo), `PrescriptionForm`, `ProductList` e `AdiantiStandardListExportTrait` (exportação de listas). Nenhuma mudança de API do dompdf entre 3.1.5 e 3.1.6; os usos ficaram intactos.
- php-jwt: `CentralVet\Security\PasswordResetToken` (reset de senha), `ApplicationAuthenticationService::fromToken` (login por token) e `ApplicationAuthenticationRestService` (emissão do token REST). Todos usam `JWT::encode($payload, $key, 'HS256')` e `JWT::decode($jwt, new Key($key, 'HS256'))`.

## Mudanças da API 7 aplicadas

Pelo `src/vendor/firebase/php-jwt/CHANGELOG.md`, a 7.0.0 não muda a assinatura de `encode`/`decode` nem a classe `Key`. As mudanças com efeito aqui:

- Validação do tamanho da chave: chave HMAC menor que a saída do hash (32 bytes no HS256) passa a ser recusada. É a única quebra possível.
- Validação de `iat`/`nbf` no payload: os tokens do app não usam esses claims (usam `expires`, conferido pelo próprio app).
- `#[SensitiveParameter]` nos parâmetros de chave.

Por isso `ApplicationAuthenticationService.php` e `ApplicationAuthenticationRestService.php` ficaram intactos. `PasswordResetToken` ganhou a recusa explícita, antes da do php-jwt: `new PasswordResetToken($key)` com `strlen($key) < 32` lança `InvalidArgumentException('JWT HMAC key must have at least 32 bytes')`, e a chave é marcada `#[\SensitiveParameter]`.

## Comprimento mínimo da chave

A chave de todos os usos é `APPLICATION_NAME . seed`. `app/config/application.php` já exige `APP_SEED` com ao menos 32 caracteres. Conferido no container, imprimindo só o número: `strlen(application . seed)` = 106 (≥ 32).

## Hash protegido

`framework_hashes.php` guarda o `sha512` de `composer.json` (`AdiantiFileHashGeneratorService::generateHash` usa `hash_file('sha512', ...)`). A linha foi regenerada no mesmo commit do `composer.json`. Antes desta task, o hash gravado já não batia com o `composer.json` versionado; agora bate.

## Implantação

`src/vendor` não é versionado. Depois do merge, rodar `composer install` no build e reconstruir as imagens `app` e `worker` (`docker compose build app worker`), senão o :8081 e o worker continuam com o dompdf 3.1.5 e o php-jwt 6. Regressão manual: login, pedido de reset com o link no log e um PDF de prescrição.
