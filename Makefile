COMPOSER_REPOSITORY_URL=https://repositr.thomann.de/package/composer

BUILD_TIMESTAMP = $(shell date +"%Y%m%d%H%M%S")
BUILD_DATE = $(shell date +"%Y-%m-%d %H:%M:%S")
BUILD_NUMBER ?= local

NAME = ${shell grep name composer.json | sed -r -e 's/^.*"thomann\/(.+)",$$/\1/'}
FULL_NAME ?= ${shell grep name composer.json | sed -r -e 's/^.*"(.+)",$$/\1/'}
MODULE_NAME ?= ${shell basename ${shell readlink -f .}}

VERSION = ${shell grep version composer.json | sed -r -e 's/^.*"([0-9.]+)".*$$/\1/'}

SOURCE_FILES := $(shell find src -name "*.php")

BROWSER = chromium-browser
PHP_UNIT := vendor/bin/phpunit

GRN = generate-release-notes

UNAME ?= $(shell uname)
OPEN ?= xdg-open

ifeq ($(UNAME), Linux)
	OS := Linux
else
	ifeq ($(UNAME), Darwin)
		OS := Darwin
		OPEN := open
	else
		ifneq (,$(findstring CYGWIN,$(UNAME)))
			OS := Windows
			OPEN := start
		else
			OS := Windows
			OPEN := start
		endif
	endif
endif

ifeq ($(OS), Windows)
    PHP_UNIT := vendor\\bin\\phpunit.bat
endif

vendor: composer.phar composer.json
	@rm -f composer.lock
	@composer install

check-syntax:
	$(foreach f, $(SOURCE_FILES), php -l $(f) &&) true

run-tests: vendor
	@$(PHP_UNIT) --log-junit ./out/xunit.xml test

coverage: prepare vendor
	@vendor/bin/phpunit --coverage-html ./out/report --coverage-clover ./out/clover.xml test

view-coverage: coverage
	@$(OPEN) out/report/index.html

package: prepare vendor
	@cp -r src composer.lock composer.json out/package
	@tar cfz $(NAME)-$(VERSION).tgz -C out/package src composer.json composer.lock
	@cd ../..
	@echo "Created $(NAME)-$(VERSION).tgz"

publish: package
	@curl --data-binary @$(shell ls *.tgz) $(COMPOSER_REPOSITORY_URL)

composer.phar:
	@php -r 'file_put_contents("composer.phar", file_get_contents("https://repositr.thomann.de/bundle/bundles/composer/latest/file"));'

prepare:
	@mkdir -p out
	@mkdir -p out/package

release-notes.html:
	@$(GRN) -o release-notes.html -m $(MODULE_NAME)


jenkins: clean check-syntax run-tests publish coverage release-notes.html

clean:
	@rm -rf vendor
	@rm -rf *.tgz
	@rm -rf composer.phar
	@rm -rf composer.lock
	@rm -rf out
	@rm -rf build-info.php
	@rm -rf release-notes.html