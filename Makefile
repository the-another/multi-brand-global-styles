.PHONY: docker-build install install-dev require update dump-autoload lint format test release check-plugin version-patch version-minor version-major all clean

# Docker image name
DOCKER_IMAGE = the-another-multi-domain-global-styles-runner:latest
DOCKER_RUN = docker run --rm -v $(PWD):/app -w /app $(DOCKER_IMAGE)

# Build Docker image
docker-build:
	docker build -t $(DOCKER_IMAGE) .

# Install composer dependencies without dev dependencies (runs in Docker)
install: docker-build
	$(DOCKER_RUN) composer install --no-dev

# Install composer dependencies including dev (needed for lint/test; runs in Docker)
install-dev: docker-build
	$(DOCKER_RUN) composer install

# Require new composer package (runs in Docker)
# Usage: make require PACKAGE="vendor/package"
require: docker-build
	$(DOCKER_RUN) composer require $(PACKAGE)

# Update composer dependencies (runs in Docker)
update: docker-build
	$(DOCKER_RUN) composer update

# Dump autoloader without dev dependencies (runs in Docker)
dump-autoload: docker-build
	$(DOCKER_RUN) composer dump-autoload --no-dev --optimize

# Run PHPCS linter (runs in Docker)
lint: docker-build
	$(DOCKER_RUN) composer phpcs

# Format code using PHPCBF (WARNING: This MODIFIES source code, runs in Docker)
format: docker-build
	$(DOCKER_RUN) composer phpcbf

# Run PHPUnit tests (runs in Docker; clears the result cache first so stale
# ordering can never mask a failure)
test: docker-build
	$(DOCKER_RUN) sh -c "rm -rf .phpunit.cache && php ./vendor/bin/phpunit"

# Package plugin for distribution: lint + test gates, then zip into build/
# (everything runs inside Docker). Note: the zip step reinstalls composer
# without dev dependencies, so run `make install-dev` before the next
# lint/test cycle.
release: install-dev lint test
	$(DOCKER_RUN) sh -c "npm install --no-audit --no-fund && npm run plugin-zip"

# Build the plugin zip (labeled -test, never the real version — see
# scripts/version-zip.js's --label flag) and run WordPress.org's official
# Plugin Check against it in a fresh WordPress instance installed FROM that
# zip. Deliberately not the dev-mounted source the regular e2e suite uses:
# the whole point is to catch packaging bugs (missing files, wrong
# autoloader) that a source mount would never surface. Zip build runs in
# Docker; the check itself runs on the host via Playwright +
# @wp-playground/cli, since that needs a real browser (not available in the
# Docker runner image).
check-plugin: docker-build
	rm -f build/the-another-multi-domain-global-styles-test.zip
	$(DOCKER_RUN) sh -c "npm install --no-audit --no-fund && npm run plugin-zip:check"
	npx playwright install chromium
	npm run check:plugin

# Bump version (package.json, composer.json, plugin header, VERSION constant,
# readme.txt stable tag + changelog stub, lock files) — runs in Docker, no
# git commit; review and commit the result yourself.
version-patch: docker-build
	$(DOCKER_RUN) sh -c "npm install --no-audit --no-fund && npm run version:patch"

version-minor: docker-build
	$(DOCKER_RUN) sh -c "npm install --no-audit --no-fund && npm run version:minor"

version-major: docker-build
	$(DOCKER_RUN) sh -c "npm install --no-audit --no-fund && npm run version:major"

# Run all: install-dev, lint, test (all in Docker)
all: install-dev lint test

# Clean vendor, node_modules, caches, and build output
clean:
	rm -rf vendor/ node_modules/ build/ .phpunit.cache/
