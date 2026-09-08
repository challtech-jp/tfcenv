# tfcenv build tasks.
#
#   nix develop   -> everything: PHP, composer and the AOT toolchain
#
# make owns only the dependency graph. Every recipe delegates to a composer
# script, so each task also runs without make:
#
#   composer run-script --list
#
# The commands themselves - and the upstream workarounds they carry - live in
# composer.json's "scripts". README.md explains why they look like that.
#
SHELL := /usr/bin/env bash

# Exported by the dev shell. The fallback keeps make usable outside it.
SHLIB_EXT ?= $(if $(filter Darwin,$(shell uname -s)),.dylib,.so)

TARGET   := tfcenv
PHPX_DIR := vendor/swoole/phpx
PHPX_LIB := $(PHPX_DIR)/lib/libphpx$(SHLIB_EXT)
SOURCES  := $(shell find src -name '*.php' 2>/dev/null)
JOBS     := $(shell getconf _NPROCESSORS_ONLN 2>/dev/null || echo 4)

RUN := composer run-script

.PHONY: all vendor phpx build run test smoke doctor clean distclean

all: build

## vendor - install composer dependencies (incl. the TypePHP compiler)
vendor: vendor/autoload.php
vendor/autoload.php: composer.json
	composer install
	@touch $@

## phpx - build libphpx, the runtime the generated C++ links against
phpx: $(PHPX_LIB)
$(PHPX_LIB): vendor/autoload.php
	@$(RUN) phpx

## build - AOT compile src/ into a native ./tfcenv binary
build: $(TARGET)
$(TARGET): $(PHPX_LIB) project.yml $(SOURCES)
	@$(RUN) compile -- --job $(JOBS)

## run - run src/ through the PHP interpreter (no AOT build needed)
run: vendor/autoload.php
	@$(RUN) tfcenv -- $(ARGS)

## test - run the PHPUnit suite through the interpreter
test: vendor/autoload.php
	@$(RUN) test

## smoke - check the compiled binary starts and answers --version / --help
smoke: $(TARGET)
	@./$(TARGET) --version | grep -q "^tfcenv " || { echo "smoke: --version failed"; exit 1; }
	@./$(TARGET) --help | grep -q "tfcenv add" || { echo "smoke: --help failed"; exit 1; }
	@./$(TARGET) nonsense >/dev/null 2>&1 && { echo "smoke: unknown command should fail"; exit 1; } || true
	@echo "smoke: ok"

## doctor - show what the toolchain resolved to
doctor:
	@$(RUN) doctor

clean:
	@$(RUN) clean

distclean:
	@$(RUN) distclean
