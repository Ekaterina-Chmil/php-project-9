PORT ?= 8000

start:
	PHP_CLI_SERVER_WORKERS=5 php -S 0.0.0.0:$(PORT) -t public

install:
	composer install

lint:
	./vendor/bin/phpcs --standard=PSR12 --extensions=php public/ templates/

lint-fix:
	./vendor/bin/phpcbf --standard=PSR12 --extensions=php public/ templates/
		