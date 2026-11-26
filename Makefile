install:
	composer install
	npm ci

build:
	rm -rf public/assets public/index.html
	cp -R node_modules/@hexlet/php-flight-booking-frontend/dist/. public/
migrate:
	php bin/migrate.php

PORT ?= 8080
#start: migrate
#	PHP_CLI_SERVER_WORKERS=5 php -S 0.0.0.0:$(PORT) -t public public/index.php

start:
	php -S 0.0.0.0:$${PORT:-8080} -t public public/index.php

validate:
	composer validate

lint:
	composer lint

test:
	vendor/bin/phpunit tests

lint-fix:
	composer exec -v phpcbf -- --standard=PSR12 --colors public src

docker-up-d:
	docker compose --env-file ./docker/.env up -d

docker-down:
	docker compose --env-file ./docker/.env down

docker-build:
	docker compose --env-file ./docker/.env build

docker-config:
	docker compose --env-file ./docker/.env config
