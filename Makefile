COMPOSE := docker compose

.PHONY: config build up down ps logs health diagnose backup restore restore-drill

config:
	$(COMPOSE) config --quiet

build:
	$(COMPOSE) build

up:
	$(COMPOSE) up -d --build

down:
	$(COMPOSE) down

ps:
	$(COMPOSE) ps

logs:
	$(COMPOSE) logs --tail=100 -f

health:
	curl --fail --silent --show-error http://127.0.0.1:$${HTTP_PORT:-8081}/health

diagnose:
	./scripts/diagnose.sh

backup:
	./scripts/backup.sh

restore:
	@test -n "$(FILE)" || (echo "Usage: make restore FILE=var/backups/<centralvet-*.tar.gpg|centralvet-*.tar.gz.cvbk|file.sql.gz>" >&2; exit 2)
	./scripts/restore.sh "$(FILE)"

restore-drill:
	@test -n "$(FILE)" || (echo "Usage: make restore-drill FILE=var/backups/<centralvet-*.tar.gpg|centralvet-*.tar.gz.cvbk|file.sql.gz>" >&2; exit 2)
	./scripts/restore-drill.sh "$(FILE)"

