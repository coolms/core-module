# Changelog

All notable changes to `coolms/core-module` are recorded here.

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).
Versioning is described in `CONTRIBUTING.md` -- read it before assuming what a
major number means here.

⚠️ Entries dated before 2026-09-01 were **reconstructed** from tags and commit
history when this file was created. Every entry after that is written in the
same commit as the change it describes.

## 2.0.0-alpha1 - 2026-09-01

**A pre-release. It carries no compatibility promise**, which is the honest
statement of where the platform is: the shape is still moving, and a stable tag
would be a promise that cannot be kept yet.

Composer will not install it under default stability. Set

```json
"minimum-stability": "alpha",
"prefer-stable": true
```

in your root `composer.json`, then `composer require coolms/core-module:^2.0`.
`prefer-stable` keeps every other dependency of yours on its newest stable
release, so this loosening applies to what actually needs it and nothing else.

⚠️ **A per-package flag is not enough here.** `composer require
coolms/core-module:^2.0@alpha` admits the alpha of the package it names and
**nothing behind it**, so the siblings this one pulls in still fail to resolve.
Composer reports it against the sibling, not against what you asked for.

A bare `composer require coolms/core-module` takes the newest **stable** release
instead -- which is the previous generation -- and reports success while doing
it.

Releases are suspended while development is moving fast and there are no
external consumers of these packages. This tag establishes the baseline the
documentation describes; nothing follows it until somebody outside the project
installs one, at which point the release policy resumes.

### Added: the config loader reads packages' `config/` too

`FileConfigLoader` scans every registered bundle's `config/modules` in addition
to the application's, so a package ships a definition instead of asking an
integrator to install one into the application's own config.

The application is scanned FIRST. This loader returns the first match, so first
is highest priority and an installation can always override what a package
ships -- never the other way round.

### Fixed: the installation command in the readme names the adapter

`composer require coolms/core-module` on its own cannot resolve. This package
requires a virtual persistence-implementation package, and only an adapter
provides one, so Composer reports that the virtual package "could not be found
in any version" -- which reads like a broken package rather than a missing
argument.

The readme now leads with the command that works:
`composer require coolms/core-module coolms/core-doctrine`.

### The v2 generation -- a version number, and nothing else

This release moves `coolms/core-module` to `2.0.0` **without a single change to its
code**. Nothing was added, removed, renamed or fixed.

Every CoolMS platform package -- everything that requires `coolms/core` --
shares a major number, so that a set of packages carrying the same major is
known to work together. The whole set crosses to v2 at once, and this package
has nothing else in the crossing.

Before the shared major existed, `composer require coolms/entity-bundle`
resolved the entire set backwards onto its first generation -- including a
template engine from before output encoding existed -- and Composer reported
success. A shared major makes that resolution unreachable by accident.

**Upgrading: widen your constraint from `^1.0` to `^2.0`. There is nothing
else to do.** No class, signature, or behaviour changed. Breaks are announced as
deprecations in a minor and removed at a generation boundary; this boundary
removes none, because there were none to remove.

The standalone libraries published alongside the platform -- `coolms/rql`,
`coolms/rql-doctrine`, `coolms/dtmpl`, `coolms/dtmpl-bundle` -- do **not** take
this major. They have users who never touch CoolMS, and their numbers answer to
their own APIs.

### Changed: sibling constraints move to the v2 generation

- `coolms/core`: `^1.0` to `^2.0`
- `coolms/core-persistence-implementation`: `^1.0` to `^2.0` -- the virtual
  package `coolms/core-doctrine` provides
- `coolms/core-doctrine` (development): `^1.0` to `^2.0`


The constraints on `coolms/rql` and `coolms/rql-doctrine` are unchanged. Those
are standalone libraries and do not take the platform generation.

## 1.0.2 - 2026-08-17

### Fixed

Require an implementation of the persistence seam for development. The package
depends on the virtual `coolms/core-persistence-implementation`, which nothing
satisfied in a bare checkout, so `composer install` in this repository alone
could not resolve.

## 1.0.1 - 2026-08-17

### Fixed

Raise the `symfony/translation-contracts` floor to 3.4.2. The declared floor
admitted a version without the interface this package calls.

## 1.0.0 - 2026-08-17

First release. The platform composition layer over `coolms/core`: the
application services that wire the kernel contracts to whatever implements
them -- config loading, backup and restore, the API manifest, outbox dispatch,
retention, and label resolution.
