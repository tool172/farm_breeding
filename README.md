# Farm Breeding

A farmOS 4.x contrib module that adds breeding and pregnancy check log types
with automatic lifecycle tracking, species-inferred gestation calculation, and
integration with the farmOS birth quick form.

---

## Features

- **Breeding log** — records a breeding event (natural service, AI, or ET)
  against one or more dam assets. Automatically sets lifecycle status to
  *Bred* and calculates due dates on save.
- **Pregnancy Check log** — records a preg check result and advances the
  breeding log lifecycle accordingly.
- **Lifecycle state machine** — six states tracking a breeding event from
  start to finish:

  | State | Meaning |
  |---|---|
  | Bred | Breeding recorded; outcome unknown |
  | Pending pregnancy check | Check is due |
  | Confirmed pregnant | Preg check returned positive |
  | Calved | Birth log recorded |
  | Open (not pregnant) | Preg check returned negative |
  | Aborted / pregnancy loss | Pregnancy lost after confirmation |

  Pregnancy checking is **optional** — a birth log advances the status
  directly from *Bred* (or any open state) to *Calved* without requiring a
  preg check.

- **Gestation calculation** — expected due date, earliest/latest calving
  window, and suggested pregnancy-check date are auto-calculated on save
  from the dam's species and the breeding date.
- **Species inference** — the dam asset's *Species/breed* taxonomy term is
  read automatically; you do not need to select species on the breeding log
  form unless you want to override.
- **`animal_stage` field** — species-neutral life-stage field added to all
  Animal assets:
  `juvenile → immature_female → mature_female` (female path)
  `juvenile → intact_male` or `castrated_male` (male path).
  Stage is set automatically on asset creation and advances to
  `mature_female` when a birth log is recorded against the dam.
- **Cattle flags (optional)** — `config/optional` ships heifer, cow, bull,
  steer, and calf flags for sites that want traditional farmOS flag-based
  UI. These are installed automatically if `farm_flag` is enabled but are
  not required for any module logic.

---

## Supported species and gestation periods

| Species | Typical (days) | Min | Max |
|---|---|---|---|
| Cattle (generic) | 283 | 279 | 287 |
| Beef cattle | 283 | 279 | 287 |
| Dairy cattle | 279 | 274 | 290 |
| Angus | 281 | 275 | 287 |
| Hereford | 285 | 278 | 292 |
| Simmental | 287 | 280 | 295 |
| Charolais | 289 | 282 | 296 |
| Holstein | 279 | 272 | 290 |
| Jersey | 278 | 270 | 285 |
| Sheep | 147 | 144 | 152 |
| Goat | 150 | 145 | 155 |
| Pig / Swine | 114 | 112 | 116 |
| Horse | 340 | 320 | 370 |
| Pony | 330 | 315 | 365 |
| Donkey | 365 | 350 | 380 |
| Alpaca | 335 | 315 | 360 |
| Llama | 350 | 330 | 360 |
| Bison | 285 | 275 | 295 |
| Deer | 201 | 195 | 210 |
| Elk | 249 | 240 | 262 |
| Rabbit | 31 | 28 | 35 |

All values are configurable via `farm_breeding.settings`. Override per-site
with `drush config:set` or a `config/install` override in a custom module.

---

## Requirements

- farmOS 4.x (Drupal 10.2+, PostgreSQL or MySQL)
- `farm_animal` — provides the Animal asset type and species taxonomy
- `farm_flag` — required for the cattle flag config (heifer, cow, etc.)
- `farm_ui_views` — provides the `/logs/breeding` list page
- `farm_birth` — **strongly recommended**; enables automatic lifecycle
  advancement and dam stage promotion when a birth is recorded. A status
  page warning appears if `farm_birth` is not installed.

---

## Installation

```bash
drush pm:enable farm_breeding
drush cr
```

No `drush updb` is needed for fresh installs. The `hook_install()` handler
calls `installBundles()` to create the log bundle fields and database tables.

### Upgrading from an earlier beta

```bash
drush updb
drush cr
```

---

## Configuration

All species settings live in `farm_breeding.settings`:

| Key | Description |
|---|---|
| `auto_advance_dam_stage` | Advance dam's `animal_stage` to `mature_female` when a birth log is recorded. Default: `true`. |
| `auto_set_initial_stage` | Set `animal_stage` on new Animal assets from native `sex` + `birthdate` fields. Default: `true`. |
| `gestation_days.*` | Typical gestation length per species. |
| `gestation_days_min.*` | Minimum gestation (earliest normal birth). |
| `gestation_days_max.*` | Maximum gestation (latest normal birth). |
| `pregnancy_check_days.*` | Days after breeding to suggest a preg check. Set to `0` to disable. |
| `species_aliases.*` | Substring → canonical species key mapping for species inference. |

---

## Uninstalling

```bash
drush pm:uninstall farm_breeding
drush cr
```

`farm_breeding_module_preuninstall()` (runs first due to `weight: -1`) ensures
all field tables are in a consistent state, then Drupal's standard cleanup
drops the log bundle field tables and the `animal_stage` field automatically.

---

## Running tests

```bash
cd /path/to/drupal
vendor/bin/phpunit web/modules/contrib/farm_breeding/tests/ --group farm_breeding
```
