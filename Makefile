# =============================================================================
# Jamdio — developer Makefile
#
#   make            # show this help
#   make install    # first-time setup (build + boot + migrate + assets)
#   make up         # start the stack
#   make sh         # open a shell inside the app container
#   make artisan a="migrate --seed"
# =============================================================================

DC          := docker compose
APP         := app
EXEC        := $(DC) exec
EXEC_T      := $(DC) exec -T
RUN         := $(DC) run --rm

# Match container file ownership to the host user (used by the php build args).
export UID  := $(shell id -u)
export GID  := $(shell id -g)

# Allow `make artisan a="route:list"` / `make composer a="require x/y"` etc.
a ?=
s ?= $(APP)

.DEFAULT_GOAL := help

# ---- Help -------------------------------------------------------------------
.PHONY: help
help: ## Show this help
	@grep -hE '^[a-zA-Z0-9_.%-]+:.*?## .*$$' $(MAKEFILE_LIST) \
		| sort \
		| awk 'BEGIN {FS = ":.*?## "}; {printf "  \033[36m%-20s\033[0m %s\n", $$1, $$2}'

# ---- Lifecycle --------------------------------------------------------------
.PHONY: install
install: ## First-time setup: build images, boot, migrate, build assets
	@test -f .env || cp .env.example .env
	$(DC) build
	$(DC) up -d
	@echo "Waiting for the app to finish bootstrapping (migrations, storage link)…"
	@sleep 8
	$(MAKE) assets-build
	@echo "\n✅ Jamdio is up:  http://localhost:8000  (admin: /admin/login)"

.PHONY: build
build: ## Build (or rebuild) all images
	$(DC) build

.PHONY: up
up: ## Start the whole stack in the background
	$(DC) up -d

.PHONY: up-build
up-build: ## Rebuild images and start the stack
	$(DC) up -d --build

.PHONY: up-fg
up-fg: ## Start the stack in the foreground (stream logs)
	$(DC) up

.PHONY: down
down: ## Stop and remove containers
	$(DC) down

.PHONY: down-v
down-v: ## Stop containers AND drop volumes (wipes the database)
	$(DC) down -v

.PHONY: restart
restart: ## Restart all services
	$(DC) restart

.PHONY: restart-%
restart-%: ## Restart one service, e.g. make restart-liquidsoap
	$(DC) restart $*

.PHONY: stop
stop: ## Stop services without removing them
	$(DC) stop

.PHONY: fresh
fresh: ## Nuke everything (incl. volumes) and rebuild from scratch
	$(DC) down -v
	$(DC) up -d --build

# ---- Status & logs ----------------------------------------------------------
.PHONY: ps
ps: ## Show container status
	$(DC) ps

.PHONY: logs
logs: ## Tail logs for all services
	$(DC) logs -f --tail=100

.PHONY: logs-%
logs-%: ## Tail logs for one service, e.g. make logs-liquidsoap
	$(DC) logs -f --tail=100 $*

# ---- Shells -----------------------------------------------------------------
.PHONY: sh shell
sh shell: ## Open a bash shell in the app (php) container
	$(EXEC) $(APP) bash

.PHONY: sh-%
sh-%: ## Shell into any service: make sh-mysql / sh-node / sh-liquidsoap
	@$(DC) exec $* bash 2>/dev/null || $(DC) exec $* sh

# ---- Laravel ----------------------------------------------------------------
.PHONY: artisan
artisan: ## Run an artisan command: make artisan a="route:list"
	$(EXEC) $(APP) php artisan $(a)

.PHONY: tinker
tinker: ## Open a Tinker REPL
	$(EXEC) $(APP) php artisan tinker

.PHONY: migrate
migrate: ## Run database migrations
	$(EXEC) $(APP) php artisan migrate

.PHONY: migrate-fresh
migrate-fresh: ## Drop all tables and re-migrate
	$(EXEC) $(APP) php artisan migrate:fresh

.PHONY: fresh-seed
fresh-seed: ## migrate:fresh --seed
	$(EXEC) $(APP) php artisan migrate:fresh --seed

.PHONY: seed
seed: ## Run database seeders
	$(EXEC) $(APP) php artisan db:seed

.PHONY: key
key: ## Generate the app key
	$(EXEC) $(APP) php artisan key:generate

.PHONY: storage-link
storage-link: ## Create the public storage symlink
	$(EXEC) $(APP) php artisan storage:link

.PHONY: optimize-clear
optimize-clear: ## Clear cached config/routes/views
	$(EXEC) $(APP) php artisan optimize:clear

.PHONY: routes
routes: ## List application routes
	$(EXEC) $(APP) php artisan route:list --except-vendor

# ---- Dependencies & assets --------------------------------------------------
.PHONY: composer
composer: ## Run composer: make composer a="require x/y"
	$(EXEC) $(APP) composer $(a)

.PHONY: composer-install
composer-install: ## composer install
	$(EXEC) $(APP) composer install

.PHONY: npm
npm: ## Run npm in the node container: make npm a="install axios"
	$(RUN) node npm $(a)

.PHONY: assets-build
assets-build: ## Build production frontend assets
	$(RUN) node npm run build

.PHONY: dev
dev: ## Run the Vite dev server in the foreground
	$(EXEC) node npm run dev -- --host 0.0.0.0

# ---- Database ---------------------------------------------------------------
.PHONY: db
db: ## Open a MySQL shell
	$(EXEC) mysql sh -c 'mysql -u$$MYSQL_USER -p$$MYSQL_PASSWORD $$MYSQL_DATABASE'

.PHONY: db-root
db-root: ## Open a MySQL shell as root
	$(EXEC) mysql sh -c 'mysql -uroot -p$$MYSQL_ROOT_PASSWORD'

# ---- Realtime & audio -------------------------------------------------------
.PHONY: reverb
reverb: ## Restart the Reverb websocket server
	$(DC) restart reverb

.PHONY: queue
queue: ## Restart the queue worker
	$(DC) restart worker

.PHONY: liq-skip
liq-skip: ## Force-skip the current track via the Liquidsoap client
	$(EXEC) $(APP) php artisan tinker --execute="app(App\\Services\\LiquidsoapClient::class)->skip();"

.PHONY: liq-reload
liq-reload: ## Reload the Liquidsoap script (restart the container)
	$(DC) restart liquidsoap

.PHONY: stream
stream: ## Print the public stream URL
	@echo "Stream: http://localhost:8001/stream"

# ---- Quality ----------------------------------------------------------------
.PHONY: test
test: ## Run the PHPUnit/Pest test suite
	$(EXEC) $(APP) php artisan test

.PHONY: pint
pint: ## Run Laravel Pint (code style)
	$(EXEC) $(APP) ./vendor/bin/pint

.PHONY: lint
lint: ## Lint all PHP files for syntax errors
	$(EXEC_T) $(APP) sh -c 'find app routes config database -name "*.php" -exec php -l {} \; | grep -v "No syntax errors" || true'
