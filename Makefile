# Development commands (UZEROWPUP-3). CI targets (ci, lint, stan...) arrive with UZEROWPUP-2.
COMPOSE ?= docker compose
APP     := $(COMPOSE) exec -T app
export WPUP_UID ?= $(shell id -u)
export WPUP_GID ?= $(shell id -g)

.DEFAULT_GOAL := help
.PHONY: help init build up down ps logs shell artisan composer migrate test psql redis-cli

help: ## List the available targets
	@grep -E '^[a-z_-]+:.*## ' $(MAKEFILE_LIST) | awk -F':.*## ' '{printf "  %-10s %s\n", $$1, $$2}'

init: ## Prepare .env, vendor/ and APP_KEY if missing (idempotent)
	@test -f .env || cp .env.example .env
	@test -f vendor/autoload.php || $(COMPOSE) run --rm --no-deps -T app composer install --no-interaction
	@grep -qE '^APP_KEY=.+' .env || $(COMPOSE) run --rm --no-deps -T app php artisan key:generate

build: ## Build the Docker images
	$(COMPOSE) build

up: build init ## Build if needed, start the stack and wait until healthy
	$(COMPOSE) up -d --wait

down: ## Stop the stack (keeps volumes)
	$(COMPOSE) down

ps: ## Show the status of the services
	$(COMPOSE) ps

logs: ## Follow the logs (SERVICE=name to filter)
	$(COMPOSE) logs -f --tail=100 $(SERVICE)

shell: ## Open bash in the app container
	$(COMPOSE) exec app bash

artisan: ## Run artisan (ARGS="route:list")
	$(COMPOSE) exec app php artisan $(ARGS)

composer: ## Run composer (ARGS="require x/y")
	$(COMPOSE) exec app composer $(ARGS)

psql: ## Open psql on the wpup database as the app role (DB=wpup_test to switch)
	$(COMPOSE) exec postgres psql -U wpup_app -d $(or $(DB),wpup)

redis-cli: ## Open redis-cli
	$(COMPOSE) exec redis redis-cli

migrate: ## Run migrations as wpup_owner against wpup
	$(APP) php artisan migrate --database=pgsql_owner --force

test: ## Run Pest against wpup_test
	$(APP) env DB_DATABASE=wpup_test php artisan migrate --database=pgsql_owner --force
	$(APP) env DB_HOST=postgres DB_DATABASE=wpup_test ./vendor/bin/pest
