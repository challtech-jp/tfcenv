{
  description = "tfcenv - Terraform Cloud workspace variable CLI (PHP / TypePHP AOT)";

  inputs.nixpkgs.url = "github:NixOS/nixpkgs/nixpkgs-unstable";

  outputs =
    { self, nixpkgs }:
    let
      systems = [
        "aarch64-darwin"
        "x86_64-darwin"
        "aarch64-linux"
        "x86_64-linux"
      ];
      forAllSystems =
        f:
        nixpkgs.lib.genAttrs systems (
          system:
          f (
            import nixpkgs {
              inherit system;
              config.allowUnfree = false;
            }
          )
        );
    in
    {
      devShells = forAllSystems (
        pkgs:
        let
          # PHP 8.5 with the embed SAPI: `tpc` links the generated C++ against
          # libphp, so it needs a PHP built with --enable-embed. ZTS matches
          # upstream's tested and released configuration; PHPX picks ZTS up
          # from php-config either way.
          #
          # NOTE: this override is not in cache.nixos.org, so the first
          # `nix develop` builds PHP from source (a few minutes).
          php = pkgs.php85.override {
            embedSupport = true;
            ztsSupport = true;
          };

          # TypePHP's PHP_HOME must be a single prefix containing
          # bin/php-config, the PHP headers and lib/libphp.{so,dylib}.
          # nixpkgs splits those across the `dev` and `out` outputs, so
          # join them back into one tree.
          phpHome = pkgs.symlinkJoin {
            name = "php-embed-home-${php.unwrapped.version}";
            paths = [
              php.unwrapped.dev
              php.unwrapped.out
            ];
          };
        in
        {
          default = pkgs.mkShell {
            name = "tfcenv";

            packages = [
              php
              php.packages.composer
              pkgs.cmake
              pkgs.ninja
              pkgs.pkg-config
              pkgs.gmp
              pkgs.mpfr
              # nixpkgs builds PHP against system libraries, so PHP's own
              # ext/*.h headers pull in headers that do not live under
              # $PHP_HOME/include. PHPX compiles against those, so the
              # externals have to be on the include path too.
              pkgs.pcre2
              pkgs.libxml2
              pkgs.oniguruma
              pkgs.jq
              pkgs.curl
              pkgs.git
            ];

            # Consumed by tpc.php / PHPX's CMakeLists.
            PHP_HOME = phpHome;

            # tpc hardcodes -L/opt/homebrew/lib -L/usr/local/lib into its link
            # line, so on a machine with Homebrew installed it resolves -lgmp
            # there. The `phpx` target in the Makefile stages these into the
            # library dir tpc searches first, which is what settles the link.
            GMP_LIB_DIR = "${pkgs.gmp}/lib";
            MPFR_LIB_DIR = "${pkgs.mpfr}/lib";

            # ".dylib" or ".so". The composer scripts need it and JSON has no
            # conditionals, so the platform knowledge lives here.
            SHLIB_EXT = pkgs.stdenv.hostPlatform.extensions.sharedLibrary;

            shellHook = ''
              export COMPOSER_CACHE_DIR="''${COMPOSER_CACHE_DIR:-$PWD/.composer-cache}"
              # PHPX ships as a composer dependency of swoole/typephp and is
              # built in-tree; tpc looks for include/ and lib/libphpx.* here.
              export PHPX_HOME="$PWD/vendor/swoole/phpx"
              echo "tfcenv dev shell"
              echo "  php       $(php -r 'echo PHP_VERSION . (PHP_ZTS ? " ZTS" : " NTS");')"
              echo "  composer  $(composer --version --no-ansi 2>/dev/null | head -1)"
              echo "  PHP_HOME  $PHP_HOME"
              echo "  PHPX_HOME $PHPX_HOME"
              echo ""
              echo "  make run    # run src/ through the interpreter"
              echo "  make build  # AOT compile ./tfcenv"
            '';
          };
        }
      );

      # Scoped to our own .nix files - a tree-wide formatter would walk the
      # ~400k files composer drops into vendor/.
      formatter = forAllSystems (
        pkgs:
        pkgs.writeShellApplication {
          name = "fmt";
          runtimeInputs = [
            pkgs.nixfmt-rfc-style
            pkgs.findutils
          ];
          text = ''
            find . -name '*.nix' \
              -not -path './vendor/*' -not -path './build/*' -not -path './.direnv/*' \
              -print0 | xargs -0 -r nixfmt "$@"
          '';
        }
      );
    };
}
