# coolms/core-module

[![CI](https://github.com/coolms/core-module/actions/workflows/ci.yml/badge.svg)](https://github.com/coolms/core-module/actions/workflows/ci.yml)
[![Latest Version](https://img.shields.io/packagist/v/coolms/core-module)](https://packagist.org/packages/coolms/core-module)
[![PHP](https://img.shields.io/badge/php-%E2%89%A5%208.5-777bb4)](https://www.php.net/releases/8.5/en.php)
[![License](https://img.shields.io/badge/license-MIT-green)](LICENSE)

**The application layer over [`coolms/core`](https://github.com/coolms/core).**
The services that compose the kernel's contracts into behaviour — config
loading, backup and restore, the API manifest, outbox dispatch, retention
sweeps and label resolution.

A *module* in this platform is the part that wires domain to infrastructure. A
*bundle* is the Symfony integration around it. This is the former; see
[`coolms/core-bundle`](https://github.com/coolms/core-bundle) for the latter.

## Installation

```bash
composer require coolms/core-module coolms/core-doctrine
```

> **The adapter is part of the install, not a second step.** This package
> requires the virtual `coolms/core-persistence-implementation`, and only an
> adapter provides it, so `composer require coolms/core-module` on its own
> cannot resolve — Composer reports that the virtual package "could not be found
> in any version", which reads like a broken package rather than a missing
> argument.
>
> The hard failure is by design. The alternative is a platform that installs
> cleanly and then cannot persist anything. `coolms/core-doctrine` is the
> adapter that exists today; substitute another if you write one.

## What is in here

| Area | What it does |
|---|---|
| `Config/` | the chained loader/writer — files first, then the database overlay |
| `Backup/` | the backup engine: reader, writer, runner, table registry, restore ordering |
| `ApiManifest/` | builds the machine-readable description of the platform's API surface |
| `Outbox/`, `ChangeFeed/`, `Retention/` | outbox dispatch, sync change application, the retention sweep |
| `Translation/` | `LabelResolver` — definition labels through the translator, with source-value fallback |
| `Dashboard/`, `Option/`, `Space/`, `Template/` | the remaining platform composition services |

## Why the layers are separate packages

`coolms/core` depends on four Symfony components. This package depends on
eleven — the DI attributes, security, routing, validation, filesystem and the
rest that composition genuinely needs.

Keeping them apart means a consumer that only wants the vocabulary (an entity
model, a contract to implement) does not inherit the composition layer's
dependency surface.

## Related packages

| Package | Role |
|---|---|
| [`coolms/core`](https://github.com/coolms/core) | the contracts this composes |
| [`coolms/core-bundle`](https://github.com/coolms/core-bundle) | Symfony integration |
| [`coolms/core-doctrine`](https://github.com/coolms/core-doctrine) | Doctrine adapter — one implementation of the persistence seam |

## License

MIT. See [LICENSE](LICENSE).
