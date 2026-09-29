# Subir o ambiente local

1. Criar o `.env` local (ignorado pelo Git) e substituir todos os valores
   `change-this-*`:

   ```bash
   cp .env.example .env
   openssl rand -hex 24   # gerar MYSQL_PASSWORD / MYSQL_ROOT_PASSWORD
   openssl rand -hex 32   # gerar APP_SEED (mínimo 32 caracteres)
   chmod 600 .env
   ```

2. Validar a configuração do Compose antes de construir:

   ```bash
   docker compose config --quiet
   ```

3. Construir as imagens (dependências resolvidas só a partir de
   `composer.lock`, num estágio isolado; ver `docker/php/Dockerfile`):

   ```bash
   docker compose build app worker
   # ou: make build
   ```

4. Subir a stack apenas com autorização para inicializar os volumes de dados
   (`mysql_data`, `redis_data`, `app_files`):

   ```bash
   docker compose up -d
   docker compose ps
   # ou: make up / make ps
   ```

5. Confirmar saúde dos serviços:

   ```bash
   curl --fail http://127.0.0.1:${HTTP_PORT:-8081}/live
   curl --fail http://127.0.0.1:${HTTP_PORT:-8081}/health
   # ou: make health / make diagnose
   ```

   `/live` confirma Nginx/PHP. `/health` executa somente leitura
   (`SELECT 1` em MySQL, `PING` em Redis).

6. Storage S3-compatible (MinIO) é opcional e não sobe por padrão:

   ```bash
   docker compose --profile minio up -d minio
   ```

7. Para parar sem perder dados:

   ```bash
   docker compose down       # preserva volumes nomeados
   # NUNCA `down -v` sem confirmar que os dados podem ser descartados
   ```

Alterações em `src/` exigem reconstruir a imagem (`docker compose build app
worker`) antes de reiniciar, pois o runtime não depende de um `vendor/`
gerado manualmente no host.
