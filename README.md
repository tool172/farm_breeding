# Farm Breeding

A farmOS 4.x contrib module that adds breeding and pregnancy check log types
with automatic lifecycle tracking, species-inferred gestation calculation,
reproductive protocol scheduling, and integration with the farmOS birth quick
form.

---

## Features

- **Breeding log** — records a breeding event (natural service, AI, or ET)
  against one or more dam assets. Automatically sets lifecycle status to
  *Bred* and calculates due dates on save.
- **Pregnancy Check log** — records a preg check result and advances the
  breeding log lifecycle accordingly.
- **Reproductive Protocol quick form** — schedules a full synchronization
  protocol in one step: select a protocol, pick a start date, enroll animals
  (individually or by location), and the module generates all activity,
  breeding, and pregnancy check logs on the correct dates. All logs for a run
  are linked to a shared Group asset for easy tracking.
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

## Operational workflows

### Using a synchronization protocol (AI or ET)

This is the recommended path when synchronizing a group of animals.

1. **Configure** — go to `/farm/settings/breeding`, enable the protocol(s) you use, and verify the method hour and pregnancy check day defaults.
2. **Schedule** — open `/quick/reproductive_protocol`, select the protocol and Day 0 date/time, enroll animals (pick individuals or select a location to enroll all females there), and enter the sire/donor and lot ID if applicable. Submit.
3. **Check the plan** — go to `/farm/breeding/protocols`. The new run appears as a group with one row per generated log. Future events are *Pending*; any events already past are *Done*. Click **View plan** to see the full run on its own page.
4. **Work the protocol day by day** — on each event date, carry out the physical task (CIDR insertion, injection, semen thaw, etc.), then open the log via its **Edit** link and mark it *Done*. Add notes or observations at this point.
5. **Record the AI or ET outcome** — the breeding log generated for the AI/ET step is already pre-filled with method, sire, and lot ID. After insemination or transfer, edit the log to confirm it is *Done*. The lifecycle status is automatically set to *Bred*.
6. **Run the pregnancy check** — on the scheduled check date, perform the check (ultrasound, rectal palpation, blood test), open the pregnancy check log, record the result (Pregnant / Open), and mark it *Done*. The linked breeding log's lifecycle advances automatically:
   - **Pregnant** → *Confirmed pregnant*
   - **Open** → *Open (not pregnant)*
7. **Record birth** — when the animal calves/lambs/etc., use the farmOS birth quick form. The breeding log advances to *Calved* and the dam's `animal_stage` advances to `mature_female`.

---

### Logging a standalone breeding event

Use this path for pasture bulls, clean-up bulls after AI, or any individual breeding where a synchronization protocol is not used.

1. **Log the breeding** — open `/quick/breeding` (or add a Breeding log directly). Select the dam(s), breeding date, method (natural service, AI, or ET), and sire. The module sets lifecycle to *Bred* and calculates the expected due date, earliest/latest calving window, and suggested pregnancy check date automatically.
2. **Optional: pregnancy check** — add a Pregnancy Check log linked to the breeding log. On save, the breeding log lifecycle advances to *Confirmed pregnant* or *Open (not pregnant)* based on the result.
3. **Record birth** — use the birth quick form as above.

Pregnancy checking is not required. If you skip it and record a birth, the lifecycle jumps from *Bred* directly to *Calved*.

---

## Reproductive Protocols

### How protocols work

A *protocol* is a named sequence of timed events defined in
`farm_breeding.protocols` config. When you submit the *Reproductive Protocol*
quick form, the module:

1. Creates a **Group asset** named `{Protocol} — {Location or first animal} — {Start date}` to tie all generated logs together.
2. Iterates over the protocol's event list, computing each event's timestamp as `Day 0 + (event_day × 24h) + event_hour`.
3. Creates one log per event — `activity`, `breeding`, or `pregnancy_check` — referencing all enrolled animals and the Group asset.
4. Sets each log's status to `pending` if the event is in the future, or `done` if it is already past.
5. Links the pregnancy check log back to the breeding log created in the same run so preg-check results advance the correct breeding lifecycle.

### AI (Artificial Insemination) protocols

AI protocols synchronize a group of females so they can be inseminated at a
predictable time without heat detection. The general sequence uses a CIDR
(progesterone insert), GnRH, and PGF2α injections to control the estrous
cycle:

**5-Day CIDR + AI** (`5day_cidr_ai`) — tighter synchrony, two PGF2α doses:

| Day | Hour | Event | Log type |
|-----|------|-------|----------|
| 0 | 0 | CIDR insertion + GnRH | Activity |
| 5 | 0 | CIDR removal + PGF2α | Activity |
| 5 | 24 | Second PGF2α injection | Activity |
| 6 | 24 | GnRH injection | Activity |
| 6 | 30 | AI | Breeding |
| 35 | 0 | Pregnancy check | Pregnancy check |

**7-Day CIDR + AI** (`7day_cidr_ai`) — traditional protocol, single PGF2α:

| Day | Hour | Event | Log type |
|-----|------|-------|----------|
| 0 | 0 | CIDR insertion | Activity |
| 7 | 0 | CIDR removal + PGF2α | Activity |
| 9 | 0 | GnRH injection | Activity |
| 9 | 16 | AI | Breeding |
| 37 | 0 | Pregnancy check | Pregnancy check |

**CO-Synch + CIDR** (`co_synch`) — combines GnRH and AI in the same visit
(disabled by default, enable in settings):

| Day | Hour | Event | Log type |
|-----|------|-------|----------|
| 0 | 0 | GnRH injection + CIDR insertion | Activity |
| 7 | 0 | PGF2α injection + CIDR removal | Activity |
| 9 | 0 | GnRH injection + AI | Breeding |
| 37 | 0 | Pregnancy check | Pregnancy check |

On the quick form, specify the **Sire** (bull or straw source) and an optional
**Semen lot ID** (straw number, tank location, etc.). These are recorded on the
breeding log.

### ET (Embryo Transfer) protocols

ET protocols synchronize *recipient* females to receive embryos flushed from a
donor cow. The quick form's *Sire/Donor cow* field becomes the **Donor cow**
when an ET protocol is selected, and enrolled females are the recipients.

**7-Day CIDR + ET** (`7day_cidr_et`):

| Day | Hour | Event | Log type |
|-----|------|-------|----------|
| 0 | 0 | CIDR insertion | Activity |
| 7 | 0 | CIDR removal + PGF2α | Activity |
| 9 | 0 | GnRH injection | Activity |
| 16 | 0 | Embryo transfer | Breeding |
| 42 | 0 | Pregnancy check | Pregnancy check |

The embryo transfer event is stored as a `breeding` log with method `et`.
The pregnancy check at day 42 (vs. day 37 for AI) allows more time for early
embryo development to be detectable via ultrasound or rectal palpation.

### Adding custom protocols

Add a new key under `protocols` in `farm_breeding.protocols`:

```yaml
protocols:
  my_protocol:
    label: 'My Custom Protocol'
    enabled: true
    method: ai          # 'ai' or 'et'
    species: [cattle]
    events:
      insert:
        label: 'CIDR insertion'
        log_type: activity
        day: 0
        hour: 0
      ai:
        label: 'AI'
        log_type: breeding
        day: 7
        hour: 16
      preg_check:
        label: 'Pregnancy check'
        log_type: pregnancy_check
        day: 35
        hour: 0
```

Import after adding: `drush php:eval "\Drupal::service('config.installer')->installDefaultConfig('module', 'farm_breeding');"` or provide the YAML in a custom module's `config/install/`.

---

## Pages and views

| URL | Description |
|-----|-------------|
| `/quick/reproductive_protocol` | Schedule a synchronization protocol run |
| `/quick/breeding` | Log a single breeding event |
| `/farm/breeding/protocols` | All protocol runs, grouped by run name, with event rows, Edit links, and View plan links |
| `/farm/breeding/protocols/{group_id}` | Detail view for one protocol run — all logs for that Group asset |
| `/logs/breeding` | All breeding logs |
| `/logs/pregnancy-check` | All pregnancy check logs |
| `/farm/settings/breeding` | Enable/disable protocols, configure method hours and pregnancy check days |

### Protocol run listing (`/farm/breeding/protocols`)

Groups rows by protocol run. Each run shows its constituent event logs with
columns: Date, Event (linked to the log), Status, Edit, and View plan. Logs
are sorted by group then by date within each group. Requires the
`create breeding log` permission.

### Protocol run detail (`/farm/breeding/protocols/{group_id}`)

Shows all logs for a single protocol run in date order. The `{group_id}` is
the numeric ID of the Group asset created when the protocol was scheduled.
Accessible by clicking **View plan** on the listing page.

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

- farmOS 4.x (Drupal 11, PostgreSQL or MySQL)
- `farm_animal` — provides the Animal asset type and species taxonomy
- `farm_group` — provides Group assets used to link protocol run logs
- `farm_flag` — required for the cattle flag config (heifer, cow, etc.)
- `farm_ui_views` — provides the `/logs/breeding` and `/logs/pregnancy-check` list pages
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

Protocol settings live in `farm_breeding.protocols` (see *Adding custom protocols* above).

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
