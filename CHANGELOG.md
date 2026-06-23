# Changelog

## 1.0.0-beta1

Initial public release targeting farmOS 4.x / Drupal 10.2+.

### Log types

- **Breeding** log type with fields for lifecycle status, species/breed group,
  sire, breeding method, semen/embryo lot ID, estrus detection method,
  turn-in/turn-out dates (pasture breeding window), expected due date,
  earliest/latest calving window, pregnancy check due date, and actual
  calving date.
- **Pregnancy Check** log type linked back to the originating breeding log.

### Lifecycle state machine

Six-state machine on breeding logs:
`bred → pending_check → pregnant → calved`
with side exits to `open` (negative preg check) and `aborted`
(pregnancy loss). Pregnancy checking is optional — a birth log advances
status directly from any open state to `calved`.

### Gestation calculation

On every breeding log save:
- Species inferred automatically from the dam asset's *Species/breed*
  taxonomy term via `SpeciesResolver` (substring alias matching against
  `farm_breeding.settings`).
- Typical gestation days, expected due date, earliest/latest calving window,
  and suggested pregnancy-check date calculated and stored.
- Manual due date is preserved if already set.
- 21 species supported out of the box; all values override-able via config.

### `animal_stage` field

Species-neutral life-stage field (`juvenile`, `immature_female`,
`mature_female`, `intact_male`, `castrated_male`) added to all Animal assets.
Set automatically on asset creation; advanced to `mature_female` when a birth
log is recorded against the dam (requires `farm_birth`).

### `farm_birth` integration

`hook_entity_insert/update` on Birth logs:
- Advances breeding log lifecycle to `calved` and records actual calving date.
- Promotes dam `animal_stage` from `immature_female`/`juvenile` to
  `mature_female` (configurable via `auto_advance_dam_stage`).
- Back-propagates twin flag and birth weight to the breeding log.

### Configuration

`farm_breeding.settings` controls all species data, gestation periods,
pregnancy check intervals, species alias mappings, and automation flags
(`auto_advance_dam_stage`, `auto_set_initial_stage`).

### Optional cattle flags

`config/optional` ships heifer, cow, bull, steer, and calf flags for sites
using the traditional farmOS flag UI. Not required for any module logic.

### Services

- `farm_breeding.gestation_calculator` — due date and window arithmetic
- `farm_breeding.species_resolver` — term → canonical species key mapping
- `farm_breeding.animal_stage_manager` — stage derivation and transition logic
- `farm_breeding.lifecycle_manager` — breeding log state transitions
