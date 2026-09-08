# tfcenv

Register Terraform Cloud workspace variables from the CLI, instead of clicking
through the web console.

Written in PHP and AOT-compiled to a native binary with
[TypePHP](https://github.com/swoole/typephp).

## Requirements

[Nix](https://nixos.org) with flakes enabled. Nothing else — no system PHP, no
Homebrew.

## Usage

```console
$ export TFC_TOKEN=...        # https://app.terraform.io/app/settings/tokens
$ export TFC_ORG=acme    # optional default
$ tfcenv add
```

`tfcenv add` walks you through picking a workspace and then registering one or
more variables. Keys that already exist are detected before anything is sent,
and offered as an update, a skip, or a different key. Nothing is written until
you confirm.

## Development shell

One shell, `nix develop`, with everything: PHP 8.5 built with the **embed
SAPI** and **ZTS**, Composer, and the AOT toolchain (CMake, Ninja, GMP, MPFR).

```console
$ nix develop
$ make run ARGS="--help"   # run src/ through the interpreter
$ make build               # AOT compile ./tfcenv
$ ./tfcenv --help
```

If you use [direnv](https://direnv.net), `direnv allow` enters it
automatically. `make doctor` prints what the toolchain resolved to.

The embed build of PHP is not in `cache.nixos.org`, so the **first** entry
compiles PHP from source (a few minutes on Apple Silicon). Everything after
that is cached in the Nix store.

`make build` chains `composer install` → `libphpx` → `tpc` as needed, so it is
the only command you normally run.

## Why not the upstream TypePHP release binary

`tpc_v0.7.0_macos_arm64.tar.gz` ships a `tpc` linked against Homebrew absolute
paths, and does not include the `libphpx.dylib` it needs:

```console
$ otool -L tpc
    @rpath/libphpx.dylib                          # vendor/swoole/phpx/lib/ is empty
    /opt/homebrew/opt/php-zts/lib/libphp.dylib
    /opt/homebrew/opt/gmp/lib/libgmp.10.dylib
    /opt/homebrew/opt/mpfr/lib/libmpfr.6.dylib
```

The compiler is self-hosted — `tpc` is itself compiled PHP — so we install it
from Packagist (`swoole/typephp`) and run `vendor/bin/tpc.php` on the Nix PHP
instead. `PHP_HOME` points at a `symlinkJoin` of the PHP `dev` and `out`
outputs, which is the single prefix (`bin/php-config`, headers,
`lib/libphp.dylib`) that TypePHP and PHPX expect.

## Where a task belongs

```
make <target>  ->  composer run-script <script>  ->  the actual command
```

**Every command lives in `composer.json`'s `scripts`.** make contributes only
the dependency graph, which is the one thing composer scripts cannot express:
`make build` twice does nothing the second time.

| make | composer | does |
|---|---|---|
| `make run ARGS=…` | `composer tfcenv -- …` | run `src/` through the interpreter |
| `make phpx` | `composer phpx` | build `libphpx` |
| `make build` | `composer compile` | AOT compile `./tfcenv` |
| `make doctor` | `composer doctor` | show what the toolchain resolved to |
| `make clean` | `composer clean` | remove `build/` and the binary |
| `make distclean` | `composer distclean` | also remove `vendor/` |

`composer run-script --list` prints the same table from the source of truth.

Two composer quirks the scripts are shaped around:

- **A script cannot be named `run`.** It shadows composer's own `run` command
  and is skipped with a warning, so the CLI script is named `tfcenv`.
- **Extra args are appended to _every_ command of an array script.** So
  `compile` is a single `;`-chained command — that way `make build` can pass
  `--job N` and have it land on `tpc` rather than on the guard clause. Scripts
  that take no arguments (`phpx`, `doctor`, `distclean`) stay arrays.

Composer echoes each command as `> …` on **stderr**, so piping stdout stays
clean; `2>/dev/null` silences it.

## Layout

```
flake.nix      the dev shell: PHP 8.5 (embed+ZTS) + AOT toolchain
Makefile       dependency graph only; delegates to composer scripts
composer.json  every actual command lives in "scripts"
project.yml    TypePHP compiler config
src/           PHP sources; binary mode needs a global main(): void
bin/tfcenv     interpreter-mode entry point (not part of the binary)
```

## Upstream friction the build works around

Three things needed handling to build TypePHP against a Nix PHP rather than a
Homebrew one. **JSON has no comments, so this section is the explanation for
what the `phpx` script in `composer.json` does.**

- **`pcre2.h` not found.** nixpkgs builds PHP against system libraries, so
  `ext/pcre/php_pcre.h` includes a header that is not under `$PHP_HOME`. The
  dev shell puts `pcre2`, `libxml2` and `oniguruma` on the include path
  (`flake.nix`).
- **PHPX's default CMake target does not compile.** `tests/ext/extension.cpp`
  calls `registerConstant("PHPX_CONST_LONG", 10002L)`, which clang rejects as
  ambiguous across the `Int`/`int`/`bool`/`double` overloads. Hence
  `cmake --build … --target phpx` rather than the default target.
- **Homebrew wins the link.** PHPX's CMakeLists runs `brew --prefix` and puts
  Homebrew's `lib/` first, so CMake is invoked with
  `-DHOMEBREW_EXECUTABLE=HOMEBREW_EXECUTABLE-NOTFOUND` to poison that lookup.
  Separately, `tpc` hardcodes `-L/opt/homebrew/lib` *ahead of* any
  `--link-path` you pass it — which is why the pinned `gmp`/`mpfr` are
  symlinked into `vendor/swoole/phpx/lib`, the first `-L` tpc emits. That
  staging step, not a flag, is what settles the link.

Verify the result is hermetic with `otool -L tfcenv` — every entry should be
`/nix/store/...`, `@rpath/...` or `/usr/lib/...`, never `/opt/homebrew/...`.

## Notes on TypePHP

Binary mode imposes constraints that shape the source:

- a global `function main(): void` or `main(int $argc, array $argv): void` is
  required, and must return `void`
- no top-level executable statements — only declarations, `use`, `declare`
  and constant definitions live at global scope
- `declare(strict_types=1)` is unnecessary; TypePHP is always strict and
  rejects `strict_types=0`

The produced binary is not dependency-free: it links PHPX, `libphp` and any
configured native libraries.
