# vivutio/property-module

**Properties for vivutio.** The camps, lodges and hotels an organization runs:
what each is and where, how it is reached and its house rules, and whether it
takes bookings. A property is a place to post people and a scope to grant
permissions at, the module's own, never an office.

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
- **A property in full**: its type (lodge, tented lodge, tented camp, mobile
  camp, camp, hotel, resort, guesthouse, villa), where it is in words and on
  a map, its star grading, a summary and a description, how it is reached,
  its check-in and check-out times, and who counts as an infant or a child.
- **One lifecycle**: a property is added as a draft, opened, closed for now
  and reopened, or archived. A closure for a season belongs to its calendar,
  not here. A property somebody is posted at is not archived.
- **Room types**, on a property's Rooms tab: what each sleeps and how many of
  them may be adults, how many the property has, and what is in them. A room
  type is withdrawn from sale rather than deleted; a property opens only with
  one on sale, and its size is what it has on sale.
- **Seasons**, on a property's Seasons tab: each named and of a kind (low,
  shoulder, high, peak), with the dated periods it runs, drawn as a year of
  months. No night is in two seasons; nights in none are counted and shown, as
  they have no rate. A year's periods are repeated a year later in one step.
- **Rates**, on a property's Rates tab: one currency, priced per person
  sharing or per room, on the board bases it sells; a year's sheet for each,
  room types down and season periods across; each period's terms, what a guest
  alone, a third adult, a child and an infant pay as a share of the sharing
  rate (a term left empty is not sold); and what a stay costs, priced night by
  night by the one service bookings will use. A period with rates is kept, and
  what the rates mean is not changed under them.
- **A calendar**, on a property's Calendar tab: a month of nights, each room
  type on sale with how many are free each night, and a page for each night
  saying what takes the rest. Only closures are kept, the whole property shut
  or some units of a room type out of service, each with its reason; what is
  free is worked out from them.
- **A place to post people**: through the core's place contract, every
  property but an archived one is offered on a person's Position card beside
  the offices, and departments may sit at it.
- **The `property` scope**, one or more named properties, which a grant may be
  limited to.

Cancellation terms arrive next.

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
