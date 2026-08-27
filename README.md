# depman/client

> **This repository is a read-only mirror.**
> The client is developed in [`cool-studio/depman`](https://github.com/cool-studio/depman) under
> `packages/composer-client`, and split out here so that Composer can install it. **Open issues and
> pull requests there** — this repository is force-pushed from the monorepo and anything committed
> directly is overwritten without warning.

Reports the Composer packages **actually installed** in a project to [DepMan](https://depman.io),
which matches them against published security advisories and raises a Finding when one of your
environments is running a vulnerable version.

It runs from your `post-install-cmd` hook, so the inventory stays true without anyone remembering
to update it.

## It will not break your build

That is the rule the rest of the design follows from. The hook catches every throwable and returns
normally; a missing config file, a missing token, an unreachable server, a rate limit and a timeout
are all ordinary states rather than failures; and the process exits `0` unless you explicitly ask
otherwise with `--fail-on-error`.

A dependency-inventory tool that fails installs gets removed, and then it protects nobody.

It also has **zero runtime dependencies**. This package installs into your production tree, so
anything it required would become part of your supply chain.

## Install

<!-- snippet:install -->
```bash
composer require depman/client
vendor/bin/depman install-hook     # patches composer.json for you
```
<!-- /snippet -->

## Configure

`depman.json` lives in your project root and **is committed**, so it holds no secrets — the client
refuses a `token` key loudly and carries on without it.

<!-- snippet:depman-json -->
```jsonc
{
  "$schema": "https://schema.depman.io/depman.schema.v1.json",
  "configVersion": 1,
  "endpoint": "https://depman.acme-internal.com",
  "project": "acme/storefront",

  "environment": {
    "from": ["DEPMAN_ENV", "APP_ENV"],
    "fallback": "local",                                   // a laptop is not production
    "map": { "prod": "production", "stage": "staging" },
    "allowed": ["local", "ci", "staging", "production"]
  },

  "ecosystems": "auto",
  "include": { "dev": true, "optional": true },

  "failOnError": false,                                    // never break composer install
  "timeoutMs": 10000,
  "offline": { "mode": "spool", "spoolDir": ".depman/spool" },
  "logLevel": "warn"
}
```
<!-- /snippet -->

Your DepMan instance's onboarding wizard renders this file with your project's real values already
filled in. Every setting can be overridden per-run: the project `.env` is read first, then the
process environment, then `depman.json`, then the built-in default.

**A missing `depman.json` is a silent skip.** It means this project does not use DepMan.

## The token

`DEPMAN_TOKEN`, found in the first of these that has it:

1. `DEPMAN_TOKEN` in the project's `.env` — where a developer puts their secrets, and where the
   hook can still read it, because your application framework has not booted during an install.
2. `DEPMAN_TOKEN` in the process environment — CI.
3. `DEPMAN_TOKEN_FILE` pointing at a file — Docker and Kubernetes secret mounts.
4. `~/.depman/credentials`, JSON keyed by endpoint host.

The value is never printed and never written to a payload or a log line. **No token at all is a
skip, not an error** — a contributor who has just cloned your repository gets nothing but silence.

## Commands

| Command | Does |
|---|---|
| `vendor/bin/depman report` | Report the installed tree now |
| `vendor/bin/depman doctor` | Print the resolved config, the environment **and where it came from**, and which mechanism supplied the token — never the value |
| `vendor/bin/depman install-hook` | Wire the post-install hook into `composer.json` |

`doctor` is the first thing to run when a project is not reporting:

```
Project:      acme/storefront
Endpoint:     https://depman.acme-internal.com
Environment:  production (from APP_ENV)
Token:        found via .env
Include dev:  yes
```

## Requirements

PHP 8.1 or newer. Composer 2.

## Development

This package is self-contained: clone it on its own, and its whole toolchain is here.

```bash
composer install
composer check     # pint --test, phpstan, phpunit
```

Changes belong in the [monorepo](https://github.com/cool-studio/depman), not here.

## License

MIT.
