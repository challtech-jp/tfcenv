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

### Without prompting

Give `add` a variable and it registers that one variable and exits. No TTY is
needed, so this is the form to use from a script.

```console
$ tfcenv add -w alpha-core-stg GTM_ID=GTM-XXXXXXX
$ tfcenv add -w alpha-core-stg -c env -s -d 'production DB' DB_PASSWORD
$ tfcenv add -w alpha-core-stg -u GTM_ID=GTM-XXX
```

A key on its own — no `=` — takes its value from the environment variable of
the same name, which keeps secrets out of your shell history and out of `ps`.
Write `KEY=VALUE` when you would rather type the value.

| Option | |
|---|---|
| `-w, --workspace NAME` | required |
| `-o, --org NAME` | defaults to `$TFC_ORG` |
| `-c, --category NAME` | `terraform` or `env`. Defaults to `terraform` |
| `-s, --sensitive` | register the value as sensitive |
| `-d, --description TEXT` | |
| `-u, --update` | overwrite the variable if the key already exists |

Without `--update`, a key that already exists is an error and nothing is sent.
With it, the attributes you do not pass keep the values they have in Terraform
Cloud — so `-u KEY=new` replaces the value and leaves the category, the
description, and the sensitive flag alone.

There is no flag for turning a sensitive variable back into a plain one:
Terraform Cloud rejects that with `422 Sensitive cannot be changed from true to
false on saved records`. Delete the variable and create it again.

To register several at once, loop:

```bash
export PARTNER_TOKEN=... DB_PASSWORD=...
for k in PARTNER_TOKEN DB_PASSWORD; do
  tfcenv add -w alpha-core-stg -s "$k"
done
```

## Distributing it

### macOS

`make bundle` packages the binary with everything it needs, so it runs on a Mac
with no Nix and no PHP:

```console
$ make bundle
  no /nix/store references
  runs with no nix env: tfcenv 0.1.0
  extensions load cleanly (curl, mbstring)

bundled: dist/tfcenv-darwin-arm64
  tar:    12M dist/tfcenv-darwin-arm64.tar.gz
```

Two things make this necessary, and both are invisible until you hand the
binary to someone else:

- The AOT binary links `libphp` dynamically, and every path in it — the
  libraries and the rpath — points into the Nix store. Eleven libraries and
  their transitive closure get copied in and rewritten to `@rpath`, leaving
  only `/usr/lib` and `/System/Library` behind, which every Mac has.
- **The binary starts with no PHP extensions at all.** `curl_init` is
  undefined, so it cannot send a single request. `curl.so` and `mbstring.so`
  are bundled with their own dependencies, and a launcher points PHP at them.
- **The bundled libcurl has no CA bundle path compiled in.** nixpkgs leaves it
  to the environment, and on a machine with Nix `NIX_SSL_CERT_FILE` supplies
  it. A teammate has neither, so every TLS handshake would fail. The launcher
  finds the CA bundle the operating system already maintains
  (`/etc/ssl/cert.pem` on macOS) rather than shipping certificates that would
  go stale.

That launcher is why the deliverable is a directory rather than one file: the
extension paths can only be given to PHP through an ini file named by an
environment variable, and the install location is not known until the tarball
is unpacked. Move the whole directory, not just `tfcenv`.

```console
$ tar xzf tfcenv-darwin-arm64.tar.gz -C ~/.local/share/
$ ln -sf ~/.local/share/tfcenv-darwin-arm64/tfcenv ~/.local/bin/tfcenv
```

`make bundle` refuses to produce an archive that has not passed all four
checks above, so a broken bundle fails the build instead of reaching someone's
machine.

### Linux

`make bundle-linux` builds inside a container and packages the result the same
way. It is a native build in a Linux container, not a cross-compile: the
target triple TypePHP picks is the host's, and `ld64` cannot emit ELF, so a
Mac cannot produce a Linux binary directly.

```console
$ make bundle-linux PLATFORM=linux/amd64   # for WSL
$ make verify-linux ARCH=x86_64            # run it on a plain Ubuntu, no Nix
```

The Nix store lives in a named Docker volume. PHP with `embedSupport` and
`ztsSupport` is not in the binary cache, so the first build compiles it from
source; keeping the volume is what makes the second build minutes instead of
tens of minutes.

Linux needs one thing macOS does not: **the dynamic loader itself is in the
Nix store**, and `PT_INTERP` does not honour `$ORIGIN`, so the path cannot be
made relative. The loader is bundled and the launcher invokes it explicitly
with `--library-path`, which is what `nix bundle` does too. The executable's
`PT_INTERP` still names a Nix path; nothing reads it.

Do not run `patchelf` on the loader while doing this. It bootstraps itself
before anything else, so a rewritten program header segfaults the process
before `LD_DEBUG` prints its first line — which is exactly how it presents.

`make verify-linux` runs the artifact on `ubuntu:24.04` with no Nix in sight
and checks that it starts, that its extensions load, that a missing CA bundle
stops only the commands that actually talk to the network, and that every
library resolves inside the bundle.

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

`make test` excludes the `network` group by default, so the suite runs
offline. To also run the test that hits the real Terraform Cloud API:

```console
$ composer test -- --group network
```

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
