# Development commands (UZEROWPUP-3) and local CI (UZEROWPUP-2). GitHub CI is not used: `make ci` is the only quality gate.
COMPOSE ?= docker compose
APP     := $(COMPOSE) exec -T app
VERIFIER := $(COMPOSE) exec -T verifier
AGENT_PHP_VERSIONS ?= 7.4 8.0 8.1 8.2 8.3 8.4 8.5
export WPUP_UID ?= $(shell id -u)
export WPUP_GID ?= $(shell id -g)

.DEFAULT_GOAL := help
.PHONY: help init build up down ps logs shell artisan composer migrate test psql redis-cli lint stan verifier agent-matrix ci hooks fixtures-keys fixtures-up fixtures-down fixtures-reset fixtures-snapshot fixtures-ssh

help: ## List the available targets
	@grep -E '^[a-z_-]+:.*## ' $(MAKEFILE_LIST) | awk -F':.*## ' '{printf "  %-13s %s\n", $$1, $$2}'

init: ## Prepare .env, vendor/, APP_KEY and the dev KEK if missing (idempotent)
	@test -f .env || cp .env.example .env
	@test -f vendor/autoload.php || $(COMPOSE) run --rm --no-deps -T app composer install --no-interaction
	@grep -qE '^APP_KEY=.+' .env || $(COMPOSE) run --rm --no-deps -T app php artisan key:generate
	@grep -qE '^WPUP_DEV_KEK=.+' .env || { grep -q '^WPUP_DEV_KEK=' .env || echo 'WPUP_DEV_KEK=' >> .env; sed -i "s|^WPUP_DEV_KEK=.*|WPUP_DEV_KEK=$$(head -c 32 /dev/urandom | base64)|" .env; }
	@grep -q '^WPUP_KEK_PROVIDER=' .env || echo 'WPUP_KEK_PROVIDER=env' >> .env

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

lint: ## Pint --test + ESLint
	$(APP) ./vendor/bin/pint --test
	$(VERIFIER) npm run lint

stan: ## Larastan (level max) + tsc --noEmit
	$(APP) ./vendor/bin/phpstan analyse --no-progress --memory-limit=1G
	$(VERIFIER) npm run typecheck

verifier: ## Vitest tests of the verifier
	$(VERIFIER) npm test

agent-matrix: ## Agent tests against PHP $(AGENT_PHP_VERSIONS)
	@if [ -z "$$(find agent -type f ! -name 'README*' 2>/dev/null)" ]; then \
		echo "agent/ has no code yet: matrix skipped (ADR-0007: PHP 7.4–8.5)"; \
	else \
		for v in $(AGENT_PHP_VERSIONS); do \
			echo "TODO: run agent tests on PHP $$v"; \
		done; \
	fi

ci: ## Full local quality gate: lint, stan, test, verifier, agent-matrix
	@./scripts/ci.sh

hooks: ## Install the pre-push git hook (.githooks/)
	git config core.hooksPath .githooks

# Test WordPress/WooCommerce sites (UZEROWPUP-4): separate compose project, throwaway test-only data.
FIXTURES_DIR   := docker/fixtures
FIXTURES       := docker compose -f $(FIXTURES_DIR)/compose.yaml
FIXTURE_SITES  := wp74 wp81 wp83
FIXTURE_PORTS  := wp74=2221 wp81=2222 wp83=2223

fixtures-keys: ## Generate the fixture SSH keypair in docker/fixtures/.keys/ (if missing)
	@mkdir -p $(FIXTURES_DIR)/.keys && chmod 700 $(FIXTURES_DIR)/.keys
	@test -f $(FIXTURES_DIR)/.keys/id_ed25519 || ssh-keygen -q -t ed25519 -N '' -C 'wpup-fixtures-test-only' -f $(FIXTURES_DIR)/.keys/id_ed25519
	@touch $(FIXTURES_DIR)/.keys/known_hosts

fixtures-up: fixtures-keys ## Build and start the test sites, seed those not seeded yet, snapshot if none
	$(FIXTURES) build
	$(FIXTURES) up -d --wait
	@for s in $(FIXTURE_SITES); do $(FIXTURES_DIR)/seed.sh $$s || exit 1; done
	@for s in $(FIXTURE_SITES); do $(FIXTURES_DIR)/snapshot.sh has $$s || { $(FIXTURES_DIR)/snapshot.sh snapshot $$s; }; done

fixtures-down: ## Stop the test sites (keeps volumes and snapshots)
	$(FIXTURES) down

fixtures-snapshot: ## Store the current state of the test sites as the known state
	$(FIXTURES_DIR)/snapshot.sh snapshot

fixtures-reset: ## Restore every test site to the stored snapshot
	$(FIXTURES_DIR)/snapshot.sh restore

fixtures-ssh: ## SSH into a test site as its subscription user (SITE=wp81)
	@test -n "$(SITE)" || { echo "usage: make fixtures-ssh SITE=wp81" >&2; exit 2; }
	@ssh -i $(FIXTURES_DIR)/.keys/id_ed25519 -o IdentitiesOnly=yes -o UserKnownHostsFile=$(FIXTURES_DIR)/.keys/known_hosts -o StrictHostKeyChecking=accept-new \
		-p $(patsubst $(SITE)=%,%,$(filter $(SITE)=%,$(FIXTURE_PORTS))) sub_$(SITE)@127.0.0.1
