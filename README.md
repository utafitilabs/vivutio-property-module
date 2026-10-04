# vivutio/property-module

**Properties for vivutio.** The camps, lodges and hotels an organization runs:
where each is and what its guests sleep in. A property is a place to post
people and a scope to grant permissions at, the module's own, never an office.

## Contents

- [Install](#install)
- [What it adds](#what-it-adds)
- [Who may do what](#who-may-do-what)
- [Development](#development)
- [Licence](#licence)

## Install

In a vivutio installation:

```bash
composer require vivutio/property-module
php bin/console doctrine:migrations:migrate
```

Its recipe registers the bundle and mounts its pages with
`config/routes/property.yaml`, a file the installation owns: remove the import
and the pages are gone. The migration builds its one table.

## What it adds

- **Properties** in the menu, for whoever may read them: a register, each
  property's page and its configure page, under `/properties`.
- **The `property` scope**, one or more named properties, which a grant may be
  limited to.

Posting people at a property, and "permissions apply at" (the whole
organization, where they are posted, or chosen properties) arrive next.

## Who may do what

| Pair | Who |
|---|---|
| `properties.read` | A position may grant it; as a module's pair, it holds only where the person's department, or one they support, allows it too |
| `properties.configure` | Super Admins and Admins alone |

The module's suite extends the core's authority test base, so every route is
held to the five proofs of the security blueprint; `tests/authority-table.md`
is the reviewed table.

## Development

```bash
composer update
composer check
```

`composer check` runs the code style check, static analysis at the highest
level and the suite, against a PostgreSQL database named in
`VIVUTIO_TEST_DATABASE_URL` (see `phpunit.dist.xml`).

## Licence

AGPL-3.0-or-later. See [LICENSE](LICENSE).
