.PHONY: help build up down restart logs status clean bash composer-install composer-update deploy auth run rebuild stop-all test

# Default target
help: ## Show available commands
	@echo "Available commands:"
	@echo ""
	@echo "Main commands:"
	@echo "  make up                 - Start all services"
	@echo "  make up app             - Start only app"
	@echo "  make up tts             - Start only TTS"
	@echo "  make down               - Stop all containers"
	@echo "  make down app           - Stop only app"
	@echo "  make down tts           - Stop only tts"
	@echo "  make restart            - Restart all services"
	@echo "  make restart app        - Restart app"
	@echo "  make restart tts        - Restart tts"
	@echo "  make logs [app|tts]     - Show service logs (default: app)"
	@echo "  make bash [app|tts]     - Enter container (default: app)"
	@echo ""
	@echo "TTS commands:"
	@echo "  make test tts           - Test TTS"
	@echo ""
	@echo "Build:"
	@echo "  make rebuild            - Rebuild all containers"
	@echo "  make rebuild app        - Rebuild app container"
	@echo "  make rebuild tts        - Rebuild tts container"
	@echo ""
	@grep -E '^[a-zA-Z_-]+:.*?## .*$$' $(MAKEFILE_LIST) | grep -v "^help:" | sort | awk 'BEGIN {FS = ":.*?## "}; {printf "  %-20s %s\n", $$1, $$2}'

# Docker commands
build: ## Build all containers
	docker-compose build

up: ## Start services
	@if [ "$(filter app,$(MAKECMDGOALS))" = "app" ]; then \
		echo "Starting only app..."; \
		docker-compose up -d app; \
	elif [ "$(filter tts,$(MAKECMDGOALS))" = "tts" ]; then \
		echo "Starting only TTS..."; \
		docker-compose --profile tts up -d tts; \
	else \
		echo "Starting all services..."; \
		docker-compose --profile tts up -d; \
	fi

down: ## Stop containers
	@if [ "$(filter app,$(MAKECMDGOALS))" = "app" ]; then \
		echo "Stopping app..."; \
		docker-compose stop app; \
	elif [ "$(filter tts,$(MAKECMDGOALS))" = "tts" ]; then \
		echo "Stopping tts..."; \
		docker-compose stop tts; \
	else \
		echo "Stopping all containers..."; \
		docker-compose down; \
	fi

restart: ## Restart services
	@if [ "$(filter app,$(MAKECMDGOALS))" = "app" ]; then \
		echo "Restarting app..."; \
		docker-compose restart app; \
	elif [ "$(filter tts,$(MAKECMDGOALS))" = "tts" ]; then \
		echo "Restarting tts..."; \
		docker-compose --profile tts restart tts; \
	else \
		echo "Restarting all services..."; \
		docker-compose --profile tts restart; \
	fi

logs: ## Show service logs (make logs [app|tts])
	@SERVICE="app"; \
	if [ "$(filter tts,$(MAKECMDGOALS))" = "tts" ]; then SERVICE="tts"; fi; \
	echo "Showing logs for service: $$SERVICE"; \
	docker-compose logs -f $$SERVICE

bash: ## Enter container (make bash [app|tts])
	@SERVICE="app"; \
	if [ "$(filter tts,$(MAKECMDGOALS))" = "tts" ]; then SERVICE="tts"; fi; \
	echo "Entering container: $$SERVICE"; \
	docker-compose exec $$SERVICE /bin/bash

# Project management
deploy: ## Deploy project
	@if [ ! -f deploy.conf ]; then \
		echo "Error: deploy.conf file not found!"; \
		echo "Please create deploy.conf from deploy.conf.dist template"; \
		exit 1; \
	fi
	source deploy.conf && ./bin/deploy.sh deploy

auth: ## Telegram authentication
	docker-compose exec app ./bin/auth-telegram.php

run: ## Run main script
	docker-compose exec app ./bin/distributter.php

composer-install: ## Install PHP dependencies
	docker-compose exec app composer install

composer-update: ## Update PHP dependencies
	docker-compose exec app composer update

# Build and deploy
rebuild: ## Rebuild containers
	@if [ "$(filter app,$(MAKECMDGOALS))" = "app" ]; then \
		echo "Rebuilding app container..."; \
		docker-compose build app; \
		docker-compose up -d app; \
	elif [ "$(filter tts,$(MAKECMDGOALS))" = "tts" ]; then \
		echo "Rebuilding tts container..."; \
		docker-compose build tts; \
		docker-compose --profile tts up -d tts; \
	else \
		echo "Rebuilding all containers..."; \
		make down; \
		docker-compose build; \
		make up; \
	fi

stop-all: ## Stop all related containers
	docker stop $$(docker ps -q --filter "name=distributter") 2>/dev/null || true

status: ## Show container status
	@echo "Project containers:"
	docker-compose ps
	@echo ""
	@echo "Related Docker containers:"
	docker ps -a --filter "name=distributter" --format "table {{.Names}}\t{{.Status}}\t{{.Ports}}"

clean: ## Clean unused Docker resources
	docker system prune -f
	docker volume prune -f

test: ## Test service (make test tts)
	@if [ "$(filter tts,$(MAKECMDGOALS))" = "tts" ]; then \
		echo "Testing TTS..."; \
		./bin/tts-wrapper.sh "Hello world! This is a speech synthesis test." shared/test-output.wav; \
		echo "Result in shared/test-output.wav"; \
	else \
		echo "Usage: make test tts"; \
	fi

# Empty targets for arguments
app:
	@:
tts:
	@:
