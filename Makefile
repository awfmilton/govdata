# Local-dev shortcuts. CI runs the same commands directly so anything you can
# verify locally with `make ci` should pass on GitHub.

.PHONY: install lint phpcs phpcbf phpstan test ci js-lint css-lint format clean

install:
	composer install --no-progress
	npm install --no-audit --no-fund

lint:
	@find publictech-core publictech-terminal publictech-mcp tests \
		-name '*.php' -print0 \
		| xargs -0 -n1 -P4 php -l > /tmp/pt-lint.log 2>&1 \
		|| (grep -v 'No syntax errors detected' /tmp/pt-lint.log; exit 1)
	@echo "All PHP files parse."

phpcs:
	vendor/bin/phpcs

phpcbf:
	vendor/bin/phpcbf

phpstan:
	vendor/bin/phpstan analyse --memory-limit=512M

test:
	vendor/bin/phpunit --testdox

js-lint:
	npx eslint "publictech-core/assets/js/**/*.js" "publictech-terminal/assets/js/**/*.js"

css-lint:
	npx stylelint "publictech-core/assets/css/**/*.css" "publictech-terminal/assets/css/**/*.css"

format:
	npx prettier --write "**/*.{js,css,md,json,yml,yaml}" --ignore-path .gitignore

ci: lint phpcs phpstan test js-lint css-lint
	@echo "All checks passed."

clean:
	rm -rf vendor node_modules coverage dist .phpunit.cache .phpcs.cache
