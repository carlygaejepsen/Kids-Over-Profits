# Facility Data Model Migration

Handoff spec for normalizing the facility data in the production database.
Written 2026-09-16 from a read-only survey of production. Everything in this
file was measured or read from code on that date; re-run the survey queries
in Appendix A before relying on the counts.

Goal: one facility, one row, one owner for each fact. Fix the causes of the
nesting mess (identity, membership, copies, schema), not the symptoms.

Hard requirement from the site owner: every facility that appears on a state
or country page today must still appear there after migration. Phase 0 and
the zero-loss gate exist for this. Do not skip them.

## Status (2026-09-16)

| Phase | State |
|---|---|
| 0.1 Baseline | Done. `scripts/snapshot-location-pages.js` captured `tmp/baseline-2026-09-16.json`: 67 pages, 6,034 tiles (4,587 from facility data, 1,447 inspection-only), 0 errors. |
| 0.2 Dumps | Done (read-only, over SSH). Not kept: re-dump with the queries in section 1 and section 10.6 (under a minute). |
| 0.3 Backup tables | Code ready, **not run**. Deploy `api/backup-data-tables.php`, then the owner opens it with `?stamp=20260916`. |
| 1 Schema, normalizer, validator | Done. `docs/FACILITY-SCHEMA.md`, `inc/facility-store.php` (not loaded by `functions.php` until phase 3), `scripts/normalize-dump.php`, and the v2 port in `js/data-form-modules/data-normalizer.js`, which matches PHP on all 10,199 copies (`scripts/check-facility-normalizer-parity.js`). |
| 2 Rehearsal | Done. `scripts/rehearse-migration.php` passes the zero-loss gate. **Waiting for the owner to sign off** on `tmp/rehearsal/identity_splits.tsv`, `review.tsv`, and the new decisions in section 9. |
| Live page fix | Code ready, not deployed. State and country pages now parse the free-text location instead of substring matching it (section 10.1). Offline against the production data this removes 63 wrong placements and adds 1 correct one. |
| 3 Apply | Code ready, not deployed or run (section 7, phase 3 "As built"). `api/migrate-facility-model.php` (dry run, batched apply), `inc/facility-migration.php` (shared with the rehearsal), `inc/facility-v2-sync.php` (10-minute re-sync), `inc/facility-v2-readers.php` (`?model=v2`). End-to-end test against MySQL 8.0 loaded with the production copy: all 20 checks pass. |
| 4-5 | Not started. |

**Data changed after the baseline (2026-09-16, init step version 16):** the
Google Alerts review shipped three seeds that change live facility data. Re-run
the phase 0.1 snapshot and the section 1 dumps before trusting the zero-loss
gate again. Snapshot re-taken 2026-09-16 20:34 UTC as
`tmp/baseline-2026-09-16-post-seeds.json`: 67 pages, 6,036 page/facility pairs,
4,554 distinct ids, 0 errors (the original baseline had 6,034 and 4,552; the
+2 are the two new facilities). The dumps and the rehearsal have NOT been re-run
against it yet.
- `seeds/facility-records.json`: ref row 12739 and its MONTANA (locations_master
  25) copy renamed "Yellowstone Girls and Boys Ranch" to "Yellowstone Boys and
  Girls Ranch"; the old name is in `otherNames`. The row's `unique_name`
  column still holds the old name.
- `seeds/new-facilities.json`: two new facilities appended to their state
  profiles with new `__facility_ref` rows: Mountain Valley Treatment Center
  (NEW HAMPSHIRE, 6) and River Point Behavioral Health (FLORIDA, 7). New tiles,
  not losses. River Point is not nested in the UHS operator project.
- `seeds/operator-aliases.json`: `operator.otherNames` added on Devereux (8)
  and Evolve (11).

---

## 1. Environment and constraints

- Production is the default target: https://kidsoverprofits.org on NixiHost
  shared hosting, PHP 8.2, MySQL 8.0.37 (JSON_TABLE, generated columns and
  multi-valued indexes are available).
- Read access over SSH: `ssh -i ~/.ssh/kop_nixihost -p 1157 kidsover@dfw-s07.nixihost.com`.
  No wp-cli on the server. Use `/usr/bin/mysql` with the credentials in
  `~/public_html/wp-config.php` (database `kidsover_production`, user
  `kidsover_dani`, host localhost). Pass `--default-character-set=utf8mb4`.
- WordPress table prefix is `wpdl_`. The data tables `facilities_master`,
  `locations_master`, `referrers_master` have NO prefix. Newer `kop_*`
  tables (`kop_addresses`, `kop_facility_addresses`, `kop_media_folder_tags`,
  `kop_folder_links`) DO use the prefix.
- Writes to production over SSH are blocked for agents running in auto
  mode. All data changes must ship as code: an admin-only endpoint under
  `api/` that the site owner opens in a browser, or an init step in
  `inc/admin.php` (see `kop_apply_facility_record_seeds` for the pattern).
  Deploy is git push to main, then the cPanel workflow (`.cpanel.yml`).
  The deploy never deletes files; add an explicit rm task per deleted file.
- The host firewall returns 403 for POST fields containing HTML. Send
  large payloads as multipart file parts, not form fields.
- Offline dump for rehearsal (base64 avoids the mysql batch-mode escaping
  of tabs and newlines):

```sql
SELECT CONCAT(id, '\t', REPLACE(TO_BASE64(unique_name), '\n', ''), '\t',
              REPLACE(TO_BASE64(json_data), '\n', ''), '\t', updated_at)
FROM facilities_master;
```

  Run the same for `locations_master` and `referrers_master`. Total JSON is
  about 7.2 MB for facilities_master and about 8 MB for locations_master.
- No build tooling. Plain PHP (procedural in `api/`), vanilla JS, no
  bundlers. No emojis anywhere (chat, UI, code, commits).
- Existing schema notes: `DATABASE-COLUMNS.md` at the repo root.

---

## 2. Current state of the data (measured 2026-09-16)

### 2.1 Row inventory

| Table | Rows | Notes |
|---|---|---|
| facilities_master | 4,589 | 4,544 per-facility `__facility_ref` rows + 45 operator/project rows |
| locations_master | 66 | 50 US states + 16 countries, each holding a nested facility array |
| referrers_master | 65 | out of scope for this migration, but shares the wrapper problem |

### 2.2 Wrapper layouts in facilities_master (top-level JSON keys)

| Top-level keys | Rows |
|---|---|
| city, data, name, state, timestamp, displayName, __facility_ref | 4,544 |
| data, operator, facilities, documentFolderId | 19 |
| data | 10 |
| data, name, category, timestamp, documentFolderId, currentFacilityIndex | 5 |
| operator, facilities | 4 |
| data, name, category, timestamp, facilities, currentFacilityIndex | 3 |
| data, operator, timestamp, facilities, documentFolderId | 2 |
| data, operator, facilities | 1 |
| data, name, category, timestamp, currentFacilityIndex | 1 |

Operator rows keep their facility array either at the root (`facilities`,
old shape, 509 nested entries, only 70 carry a `facility_id`) or under
`data.facilities` (new shape, 189 entries, 186 carry a `facility_id`).
Some rows are double wrapped (`data.data`); `kop_unwrap_project_payload`
in `inc/database.php` exists to peel that.

Every `locations_master` row uses the same wrapper:
`{name, category, timestamp, currentFacilityIndex, data: {facilities: [...]}}`.

### 2.3 The per-facility row (`__facility_ref`)

Shape: `{__facility_ref: true, name, displayName, city, state, timestamp, data: {facility: {...}}}`.
`name` is the `unique_name`; `displayName` is the human name. `state` on
the wrapper is a 2-letter code for US rows, a country name for
international rows, or JSON null (97 rows).

`data.facility` has 12+ distinct key sets. Field-level variance across the
4,544 rows:

| Path (under data.facility) | Types seen |
|---|---|
| address | string 4,492, object {street, city, state, zip} 52 |
| locationDetails | missing 446, present without city/state 99 |
| locationDetails.state | string 3,999 (2-letter), missing 545 |
| identification | 7 different key sets; always has name and currentName |
| identification.pastNames | array 4,239, missing 305 |
| operatingPeriod.startYear | null 4,143, string 259, integer 142 |
| operatingPeriod.status | string in all rows, see vocabulary below |
| facilityDetails.capacity | null 4,399, missing 106, integer 35, string 4 |
| staff, licensing, accreditations, notes | present 4,438, missing 106 |
| fieldNotes | missing 4,516, array 25, object 3 |
| sourceProject | string 411, missing elsewhere |

Status vocabulary in use: Open 3,551, Closed 737, Unknown 218, Transferred
28, blank 4, "Adults Only" 3, Suspended 2, "closed" 1.

The canonical form shape the admin form already uses is in
`js/data-form-modules/project.js` (function `createNewProjectData`,
the `facilities[0]` template). Use it as the base of the v2 schema.

### 2.4 Cross-copy facts

| Measurement | Result |
|---|---|
| Nested entries across all locations_master rows | 4,960 |
| Distinct facility_id among them | 4,549 (about 411 duplicate entries) |
| Entries without facility_id | 1 |
| Entries whose facility_id has no facilities_master row | 0 |
| Facilities appearing in 2 or more different location rows | 60 |
| Of those, explained by a formerLocations record | 0 |
| Location entries whose facility's own state disagrees with the row it sits in | 176 |
| Of those, Kansas facilities filed in the WISCONSIN row (Kansas addresses, nested copy even says KS) | 23 |
| Location entries whose nested address differs from the master row's address | 392 |
| Facilities in no location row at all | 5 (ids 9618, 9641, 14155, 14156, 14157) |
| Ref rows with null wrapper state | 97: 88 have an address string, 3 have only a free-text location, 6 have nothing |
| Same-name collisions inside one state (distinct ids, same normalized name) | 0 |
| Ref rows sharing name + state | 0 |

Worst duplicate pressure inside a single location row: FLORIDA 226 entries
for 174 facilities, UTAH 314 for 271, CALIFORNIA 640 for 602,
MASSACHUSETTS 81 for 60.

The 60 multi-state facilities are mostly different facilities that share a
name and were given one id by the name-only matcher (examples: "Hope House"
id 9709 in IDAHO, NORTH CAROLINA and TEXAS; "Harmony House" id 9968 in
CALIFORNIA, NORTH CAROLINA, NORTH DAKOTA; "Embark Behavioral Health"
id 10433 in six states; "Juvenile Detention Center" id 9999 in CALIFORNIA
and SOUTH CAROLINA).

---

## 3. Root causes

1. **Identity is a name match.** `kop_promote_single_nested_facility` in
   `api/facility-promotion.php` resolves a nested entry to a facility_id by
   `LOWER(unique_name) = LOWER(name)` with no state or city. Different
   facilities with the same name become one record. The reverse-link
   routine `kop_link_refs_to_locations` in the same file matches by name
   key too, and appends clones (`linkedFromRef: true`) into location rows.
2. **Location membership is array position.** A facility is on the Utah
   page because a copy of it sits in the UTAH row's array. There is no
   membership record to validate, so misfiles are invisible.
3. **Full copies instead of references.** Location rows and operator rows
   hold complete clones of the facility object. Edits must hit up to three
   copies (see `seeds/facility-records.json` and
   `kop_apply_facility_record_seeds` in `inc/admin.php`, which exist only
   to work around this). Copies drift (392 address mismatches).
4. **No schema.** Nine wrapper layouts, 12+ facility key sets,
   string-or-object fields, and four write paths (`api/save-master.php`,
   `api/facility-promotion.php`, `api/approve-edits.php`, seeds in
   `inc/admin.php`) each with their own shape logic. Readers compensate:
   `kop_unwrap_project_payload` (`inc/database.php`),
   `kop_normalize_project_payload` (`inc/rest-api.php`, handles facilities
   as a JSON string, as `facilities.facilities`, and as a single `facility`
   object), and per-template guards.

---

## 4. How the location index renders today

`kop_state_collect_programs($state_name)` in `inc/rest-api.php`
(called by the `kop/v1/state/{slug}` REST route, used by
`templates/page-state.php` and `js/state-page.js`):

1. Source 1: the `locations_master` row whose `unique_name` equals the
   uppercase state name. Every nested entry is taken verbatim, no filter.
2. Source 2: every `facilities_master` row (operator rows AND ref rows, since
   `kop_normalize_project_payload` turns `data.facility` into a one-item
   list). A nested facility is included when `address.state` or
   `locationDetails.state` equals the state name or 2-letter code, or the
   free-text `location` contains the state name, or a
   `locationDetails.formerLocations[].state` matches (flagged relocated).
3. Records are deduplicated by normalized facility NAME only
   (`kop_state_build_program_record`, `dedup_key`), merged first-wins per
   scalar by `kop_state_merge_program_fields`, with Unknown status
   overridable.

`inc/country-rest-api.php` mirrors this for countries. The public
directory (`page-tti-program-index.php`) uses
`kop_get_facilities_projects_from_database()` in `inc/database.php`,
which SKIPS ref rows and reads the 45 project rows plus locations_master.

Consequences to preserve or improve, not break:

- The 97 null-state rows and the 23 misfiled Kansas rows display today only
  because of Source 1.
- The 60 shared-id facilities display on several state pages. After the
  identity split each page must still show its own facility.
- Because same-name collisions inside a state are 0, switching dedup from
  name to facility_id will neither split nor merge any program on any page.

---

## 5. Target model

### 5.1 facilities_master

Only per-facility rows remain. Each row's `json_data` is a single canonical
facility document (v2 schema, section 6) stamped `schema_version: 2`. The
wrapper keys (`__facility_ref`, `displayName`, wrapper `city`/`state`)
go away; `unique_name` stays as the stable slug.

Add stored generated columns computed from the JSON so they can never
drift and need no writer changes:

```sql
ALTER TABLE facilities_master
  ADD COLUMN schema_version TINYINT GENERATED ALWAYS AS
    (JSON_EXTRACT(json_data, '$.schema_version')) STORED,
  ADD COLUMN name VARCHAR(255) GENERATED ALWAYS AS
    (JSON_UNQUOTE(JSON_EXTRACT(json_data, '$.identification.name'))) STORED,
  ADD COLUMN name_key VARCHAR(255) GENERATED ALWAYS AS
    (JSON_UNQUOTE(JSON_EXTRACT(json_data, '$.identification.nameKey'))) STORED,
  ADD COLUMN state CHAR(2) GENERATED ALWAYS AS
    (JSON_UNQUOTE(JSON_EXTRACT(json_data, '$.location.state'))) STORED,
  ADD COLUMN city VARCHAR(120) GENERATED ALWAYS AS
    (JSON_UNQUOTE(JSON_EXTRACT(json_data, '$.location.city'))) STORED,
  ADD COLUMN country VARCHAR(80) GENERATED ALWAYS AS
    (JSON_UNQUOTE(JSON_EXTRACT(json_data, '$.location.country'))) STORED,
  ADD COLUMN status VARCHAR(20) GENERATED ALWAYS AS
    (JSON_UNQUOTE(JSON_EXTRACT(json_data, '$.operatingPeriod.status'))) STORED,
  ADD COLUMN facility_type VARCHAR(120) GENERATED ALWAYS AS
    (JSON_UNQUOTE(JSON_EXTRACT(json_data, '$.facilityDetails.type'))) STORED,
  ADD COLUMN start_year SMALLINT GENERATED ALWAYS AS
    (JSON_EXTRACT(json_data, '$.operatingPeriod.startYear')) STORED,
  ADD COLUMN end_year SMALLINT GENERATED ALWAYS AS
    (JSON_EXTRACT(json_data, '$.operatingPeriod.endYear')) STORED,
  ADD INDEX idx_state_status (state, status),
  ADD INDEX idx_name_key (name_key),
  ADD UNIQUE INDEX uq_identity (name_key, state, city);
```

Correction (2026-09-16): the statement above fails on real data. Use
`JSON_VALUE(json_data, '$.path' RETURNING SIGNED)` for numbers and
`JSON_VALUE(json_data, '$.path')` for strings, as `kop_migration_create_tables`
does. See phase 3.

`json_data` is currently LONGTEXT; generated columns over JSON functions
work on LONGTEXT but converting the column to the JSON type first
(`ALTER TABLE ... MODIFY json_data JSON`) validates every row and is
recommended. That ALTER fails if any row holds invalid JSON, which is a
useful check by itself.

`nameKey` is written by the normalizer (lowercase, punctuation stripped,
"the" and "inc" dropped, whitespace collapsed). Keep the same rule as
`kop_normalize_facility_name` in `inc/rest-api.php` so search behaves the
same. The unique index is the enforcement of rule R1; it must be added
only after the identity split has run, or the ALTER fails.

If ALTER on the live table is a concern on shared hosting, create
`facilities_v2` with these columns, migrate into it, then rename tables in
phase 5. Either is acceptable; the rename approach is safer to roll back.

### 5.2 {prefix}kop_operators and {prefix}kop_operator_facilities

```sql
CREATE TABLE wpdl_kop_operators (
  id INT AUTO_INCREMENT PRIMARY KEY,
  unique_name VARCHAR(255) NOT NULL UNIQUE,
  name VARCHAR(255) NOT NULL,
  json_data JSON NOT NULL,          -- operator block only, no facilities array
  document_folder_id INT NULL,
  legacy_master_id INT NULL,        -- the facilities_master row it came from
  created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);
CREATE TABLE wpdl_kop_operator_facilities (
  operator_id INT NOT NULL,
  facility_id INT NOT NULL,
  relationship ENUM('current','past','other') NOT NULL DEFAULT 'current',
  sort_order INT NOT NULL DEFAULT 0,
  PRIMARY KEY (operator_id, facility_id),
  KEY by_facility (facility_id)
);
CREATE TABLE wpdl_kop_operator_links (
  operator_id INT NOT NULL,
  link_kind ENUM('news','lawsuit') NOT NULL,
  link_id INT NOT NULL,             -- news_submissions.id or lawsuits.id
  link_type ENUM('mentioned','primary','related') NOT NULL DEFAULT 'mentioned',
  created_by VARCHAR(255) NULL,
  created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (operator_id, link_kind, link_id),
  KEY by_link (link_kind, link_id)
);
```

Articles and lawsuits can be about an operator rather than one facility
(CEDU, Universal Health Services). Those links live in `kop_operator_links`.
Today they sit in `news_facility_links` with the operator row's id as
`facility_id` (15 rows); the apply step moves them (see
`tmp/rehearsal/operator_links.tsv`). The news and lawsuit linkers must write
operator links here from phase 4 on.

The 45 operator rows in facilities_master move here. Their nested arrays
become join rows. Referrer and transporter blocks that live on a few
operator rows (see the `data.*` key sets with referrerAgency, transporters
etc.) are out of scope for this migration; do not lose them. Copy the
block verbatim into the operator's json_data under `legacy_blocks` if no
better home exists.

### 5.3 {prefix}kop_facility_locations

```sql
CREATE TABLE wpdl_kop_facility_locations (
  facility_id INT NOT NULL,
  location_key VARCHAR(80) NOT NULL,   -- 'UTAH', 'MEXICO', 'UNKNOWN' (matches locations_master.unique_name)
  role ENUM('current','former','additional','unknown') NOT NULL DEFAULT 'current',
  source ENUM('address','location_details','wrapper_state','former_location','additional_location','legacy_membership','manual') NOT NULL,
  needs_review TINYINT(1) NOT NULL DEFAULT 0,
  review_reason VARCHAR(255) NULL,
  created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (facility_id, location_key, role),
  KEY by_location (location_key, role)
);
```

A state or country page becomes a join of facilities_master to
kop_facility_locations filtered by location_key. The `locations_master`
table is dropped in phase 5.

### 5.4 Addresses

`{prefix}kop_addresses` and `{prefix}kop_facility_addresses` already exist
(`api/manage-addresses.php`) and are rebuilt from facility data on each
seed. Keep them as the street-level home. The v2 facility document stores
the parsed address once; the join table is derived from it.

### 5.5 One write path

Create `inc/facility-store.php` with:

- `kop_facility_normalize(array $raw): array` : any legacy shape to v2.
- `kop_facility_validate(array $doc): array` : list of violations, empty when valid.
- `kop_facility_save(array $doc, array $opts): int` : validates, writes the
  row, rebuilds that facility's rows in `kop_facility_locations`,
  `kop_operator_facilities` and `kop_facility_addresses`. Returns the id.
- `kop_facility_resolve_identity(string $name, ?string $state, ?string $city): ?int`
  : the ONLY way to look up an existing facility. Matches on
  (name_key, state, city); falls back to (name_key, state) only when city is
  empty on both sides. Never name alone.

`api/save-master.php`, `api/approve-edits.php`, `api/facility-promotion.php`
and the seed appliers in `inc/admin.php` all call these. Delete
`kop_promote_single_nested_facility`'s name-only lookup and
`kop_link_refs_to_locations` once phase 4 is done.

---

## 6. Canonical facility document (v2)

Start from the `facilities[0]` template in
`js/data-form-modules/project.js` and apply these rules. Write the
finished schema to `docs/FACILITY-SCHEMA.md` with an example document.

```
{
  schema_version: 2,
  identification: {
    name, nameKey, currentName, otherNames[], pastNames[],
    currentOperator, currentOwners[], knownReferrers[], otherOperators[]
  },
  location: {                      // replaces address (string) + locationDetails + location (string)
    raw,                           // the original address string, never edited by the migration
    street, city, state, zip, country,
    additionalLocations[{raw, street, city, state, zip}],
    formerLocations[{state, city, raw, fromYear, toYear}]
  },
  operatingPeriod: { startYear:int|null, endYear:int|null, status, yearsOfOperation, notes[] },
  facilityDetails: { type, capacity:int|null, currentCensus:int|null, ageRange:{min:int|null,max:int|null}, gender },
  staff: { administrator[], notableStaff[], pastTTIJobs[] },
  accreditations: { current[], past[] },
  memberships[], certifications[], licensing[], profileLinks[],
  resources: {...booleans + notes[]},
  treatmentTypes: {}, philosophy: {}, criticalIncidents: {},
  notes[], fieldNotes: {},
  provenance: {                    // replaces sourceProject / sourceCategory / sourceOperator / linkedFromRef
    sourceProject, sourceCategory, sourceOperator, legacyIds[], migratedAt
  }
}
```

Normalization rules:

- `state`: 2-letter US code, uppercase. Full names and lowercase are mapped
  (`kop_state_abbrev_to_name` in `inc/rest-api.php` has the table). Non-US
  rows leave `state` null and set `country`.
- `country`: full English name from a fixed list; "England" and "Jersey"
  map to "United Kingdom", "Jerusalem" to "Israel", "The Netherlands" to
  "Netherlands", "US" to "United States". Keep the original in
  `location.raw` if it only appeared there.
- Address string to parts: reuse `kop_promote_split_address` in
  `api/facility-promotion.php` as the starting point; a US address ends in
  `<City>, <ST> <ZIP>`. Object addresses (52 rows) map field for field.
- `status`: exact set Open, Closed, Suspended, Transferred, Unknown.
  "closed" becomes Closed. Blank becomes Unknown. "Adults Only" becomes
  Unknown and the original string is appended to `operatingPeriod.notes`.
- Years, capacity, census, ages: integers or null. Strings like "1994" cast;
  anything non-numeric goes to null and the original to notes.
- Every list field is always an array. `fieldNotes` is always an object
  (array input becomes `{ "_legacy": [...] }`).
- Sections are always present, even when empty, so readers stop guarding.
- `nameKey` computed as in section 5.1.

---

## 7. Migration phases

### Phase 0: Baseline and backups (gate for everything)

1. Write `scripts/snapshot-location-pages.js` (Node, no deps): for every
   state slug and country slug, GET the live REST routes
   `/wp-json/kop/v1/state/{slug}` and the country equivalent, and store
   `{page: [{facility_id, name, city, status}]}` to
   `tmp/baseline-2026-09-16.json`. Use a browser User-Agent; plain curl is
   bot-walled. Also record the count from the public program index.
2. Dump the three tables with the base64 query in section 1 to the Tools
   repo or scratchpad (never commit dumps).
3. Ship and run an admin endpoint that creates
   `facilities_master_bak_20260916`, `locations_master_bak_20260916`,
   `referrers_master_bak_20260916` with `CREATE TABLE ... SELECT`.

### Phase 1: Schema, normalizer, validator

1. `docs/FACILITY-SCHEMA.md` (section 6, with an example).
2. `inc/facility-store.php` with normalize, validate, resolve_identity
   (section 5.5). Pure functions; no WordPress dependency inside
   normalize/validate so they run from the CLI.
3. `scripts/normalize-dump.php`: reads the base64 dump, runs normalize on
   every facility from every copy, reports: rows per resulting key set
   (should be exactly one), validation failures by rule, and a sample of
   before/after for each rule. Iterate until validation failures are zero
   or explicitly accepted.
4. Update `js/data-form-modules/data-normalizer.js` so the form emits v2
   and can load v2 (it currently expects `address` as a string and
   `locationDetails`).

### Phase 2: Offline rehearsal of the whole migration

`scripts/rehearse-migration.php` reads the dump and produces, in
`tmp/rehearsal/`:

- `facilities.jsonl` : the v2 rows.
- `operators.jsonl`, `operator_facilities.tsv`, `facility_locations.tsv`.
- `conflicts.tsv` : facility_id, field, value_kept, value_dropped, sources.
- `identity_splits.tsv` : old id, new ids, names, states, links repointed.
- `review.tsv` : every row needing a human decision, with a reason code.
- `diff-vs-baseline.txt` : per page, facilities missing, facilities added.

Resolution rules, applied in this order:

- **R1 Identity.** Group every nested copy (location rows, operator rows,
  ref rows) by `facility_id`. Within a group, parse each copy's address
  state. If all copies agree (or lack a state), they are one facility:
  merge under R2. If they disagree, split: the copy whose address matches
  the ref row's own address keeps the id; each other distinct
  (state, city) becomes a new facility row with a new id, and the old id is
  recorded in `provenance.legacyIds`. Re-point links that referenced the
  old id (`news_facility_links`, `lawsuit_facility_links`,
  `{prefix}kop_facility_addresses`, wiki links via
  `api/link-wiki-facility.php`, media folder assignments in
  `{prefix}kop_media_folder_tags` if they carry facility ids) by matching
  the linking record's own facility name and state text; anything that
  cannot be matched stays on the original id and goes to review.
- **R2 Field truth.** Within a group, per scalar field the copy with the
  newest `updated_at` (row-level, for nested copies the parent row's) wins.
  Exception: a non-Unknown status beats Unknown regardless of age. Lists
  are unioned with de-duplication by normalized string. Two different
  non-empty scalars produce a `conflicts.tsv` line; the migration still
  completes with the winner written.
- **R3 Primary location.** First non-empty of: state parsed from the
  address string; `locationDetails.state`; wrapper `state`; the location
  row array the copy sits in. Emits one `current` membership with the
  matching `source`. This re-files the 23 Kansas facilities and settles
  88 of the 97 null-state rows.
- **R4 Membership preservation.** For every location row array a copy sits
  in that differs from the R3 primary, emit a `current` membership with
  `source = legacy_membership`, `needs_review = 1`,
  `review_reason = 'array membership disagrees with address'`. The
  facility therefore still appears on every page it appears on today.
  `formerLocations` entries emit `former` memberships;
  `additionalLocations` with a parseable state emit `additional`.
- **R5 No location.** A facility with no membership after R3 and R4 gets
  `location_key = 'UNKNOWN', role = 'unknown', needs_review = 1`. The
  index page renders an "Unknown location" group so these become visible.
  As of today this is 6 facilities: ids 9618, 9641, 14155, 14156, 14157
  and one more null-state row with no address or location text.
- **R6 Vocabulary.** Section 6 rules.

Gate: `diff-vs-baseline.txt` shows zero facilities missing from any page.
Additions (from the identity split and R5) are listed and expected. Do not
proceed to phase 3 until this is true and the site owner has seen
`identity_splits.tsv` and `review.tsv`.

### Phase 3: Apply on production

As built (2026-09-16). This differs from the original plan in one respect: the
four legacy write paths are **not** rewired to `kop_facility_save` yet.
Instead, v2 is always re-derived from the legacy tables with the same code the
rehearsal runs. Legacy stays the source of truth until the phase 4 cutover,
the live save paths are not touched during the bake period, and v2 can always
be rebuilt from scratch. `kop_facility_save` becomes the write path at cutover.

1. `inc/facility-migration.php` holds the whole plan (rules R1-R6), the
   baseline diff and the apply code. `scripts/rehearse-migration.php` and the
   endpoint both call it, so a dry run on production reports exactly what the
   rehearsal reports.
2. `api/migrate-facility-model.php` (admin only):
   - `?action=status`: what exists, the last sync, whether legacy data changed since.
   - `?action=dry_run`: plan from live data as JSON; writes nothing.
     `&report=review|splits|conflicts|memberships|operator_links|link_repoints`
     returns one report as TSV.
   - `?action=run`: a page with an Apply button that POSTs batches of 500
     (nonce-protected) and shows progress. Each batch rebuilds the plan
     (about 5 s), so a PHP timeout just means pressing Apply again.
3. Additive only. It creates and fills `facilities_v2` (no prefix, renamed to
   `facilities_master` in phase 5), `{prefix}kop_facility_locations`,
   `kop_operators` (id = the legacy row id), `kop_operator_facilities`,
   `kop_operator_links`, `kop_facility_identity` and `kop_migration_state`.
   `facilities_master`, `locations_master` and every link table are only read.
   Link repoints (section 10.5) are stored in `kop_migration_state` and applied
   at cutover, because the old readers still use the old ids.
4. Ids: existing facilities keep their id. Split-off and new facilities get
   ids from 100000 up, recorded in `kop_facility_identity` under a stable key
   (`split:<old id>:<place>:<city>` or the group key), so every re-run gives
   them the same id and unique_name.
5. Keeping v2 current: `inc/facility-v2-sync.php` runs every 10 minutes via
   WP-cron. It fingerprints the legacy tables (row count, max `updated_at`,
   `SUM(CRC32(json_data))`) and re-applies the plan only when that changes.
   Unchanged documents are not rewritten, facilities gone from legacy are
   removed, and a MySQL named lock prevents overlapping runs. It does nothing
   until the first apply.
6. `?model=v2` on `kop/v1/state/{slug}` and `kop/v1/country/{slug}` reads
   `facilities_v2` joined to `kop_facility_locations`. Records are built with
   the existing `kop_state_build_program_record`, so tiles keep their shape.
   Diff with `node scripts/snapshot-location-pages.js --model v2 --out tmp/after-phase3.json`.
   The same readers serve the site once the `kop_data_model` option is `v2`
   (phase 4).

Owner steps: deploy; open `api/backup-data-tables.php?stamp=<date>`; open
`api/migrate-facility-model.php?action=dry_run` and check it matches the
rehearsal; open `?action=run` and press Apply; take the v2 snapshot and
compare.

End-to-end test (MySQL 8.0.35 loaded with the 2026-09-16 production copy):

- The dry run writes nothing and plans 4,677 facilities.
- Batched apply takes 11 requests, about 60 s.
- Row counts, `schema_version`, link integrity and the id range all check out,
  and the legacy tables are unchanged.
- Stored documents equal the offline rehearsal.
- A forced re-sync rewrites 0 rows, and an unforced sync is skipped.
- Every facility on each of the 67 legacy pages is also on the v2 page, and
  each v2 tile carries its own facility id. The v2 readers take 0.6 s against
  11.5 s for the legacy collectors.
- One legacy edit rewrites 1 row, split ids stay stable across syncs, and a
  deleted legacy facility leaves v2.

Bug found by the test: MySQL cannot cast JSON `null` to an integer in a
generated column, so the `ALTER` in section 5.1 fails as written, and
`JSON_UNQUOTE(JSON_EXTRACT(...))` stores the string `'null'`. The tables use
`JSON_VALUE(json_data, '$.path' [RETURNING SIGNED])`, which returns SQL NULL.

### Phase 4: Reader cutover

Behind a site option `kop_data_model` (`v1` default, `v2`), switch readers
one at a time, re-running the snapshot diff after each:

1. `kop_state_collect_programs` and the country equivalent: query
   `kop_facility_locations` joined to facilities_master; dedup by
   facility_id; drop the name-only dedup and the free-text state matching.
2. Public program index (`kop_get_facilities_projects_from_database`):
   operators from `kop_operators`, facilities via the join table.
3. `templates/single-facility-profile.php`.
4. `inc/global-search.php`, `inc/ajax-search-lite.php`,
   `api/facility-search.php`, `api/facility-picker.php`, `search.php`.
5. Data form loaders (`api/get-master-data.php`, `api/data-manager.php`,
   `kop/v1/search`) and the JS in `js/data-form-modules/`.
6. Everything else in the list of files that read `json_data` (about 60
   PHP files; `grep -rl json_data --include=*.php`). Most of the
   `api/diagnose-*.php`, `api/debug-*.php`, `api/fix-*.php` and one-off
   backfills can be deleted rather than ported; confirm with the owner.

Flip the option to `v2` when every page passes the diff.

### Phase 5: Remove the old model

After a bake period agreed with the owner:

1. Drop `locations_master`. Delete the operator rows from facilities_master
   (they live in `kop_operators` now). Add the unique index from 5.1.
2. Delete `kop_unwrap_project_payload`, the legacy branches of
   `kop_normalize_project_payload`, `kop_link_refs_to_locations`,
   `api/promote-facilities-to-rows.php`, the facility-record seed applier's
   `apply_to` fan-out (a seed now targets one row), and the dual-write
   branch in `kop_facility_save`.
3. Update `DATABASE-COLUMNS.md`, `CLAUDE.md` (Dual-Workflow and Database
   Tables sections) and the memory note about three data copies.
4. Drop the `_bak_20260916` tables.

---

## 8. Verification gates (run after every phase)

- Zero-loss: every (page, facility) pair in the phase 0 baseline is present
  after the change, matching by facility_id, or by normalized name when the
  id was split.
- Count floor: distinct facility rows >= 4,544 + number of identity splits.
- Link integrity: every `facility_id` in `news_facility_links`,
  `lawsuit_facility_links`, `kop_facility_addresses`,
  `kop_operator_facilities`, `kop_facility_locations` resolves to a
  facilities_master row.
- Schema: `SELECT COUNT(*) FROM facilities_master WHERE schema_version <> 2`
  is 0 after phase 3; `kop_facility_validate` returns no violations for any row.
- Page smoke: the jsdom harness described in project memory (render
  state/country hub pages offline against live JSON) still runs clean for
  UT, CA, TX, FL and two countries.

---

## 9. Decisions the site owner must make (collect before phase 3)

| Decision | Default if no answer |
|---|---|
| Confirm each identity split in `identity_splits.tsv` (about 60) | Split as computed by R1 |
| State disagreements not settled by the address rule (up to 176, minus the 23 Kansas misfiles) | Keep both memberships, flagged |
| Where the 6 no-location facilities belong | Unknown location group |
| Status strays: "Adults Only" (3), blank (4) | Unknown, original kept in notes |
| ALTER facilities_master in place vs build facilities_v2 and rename | Build and rename |
| Which diagnose/debug/fix endpoints to delete instead of port | Port none, delete all listed in phase 4 step 6 |
| ~~Free-text page placements~~ | Decided 2026-09-16: fix them. Wrong placements are removed (section 10.1) |
| ~~Operator news links~~ | Decided 2026-09-16: intended. They move to `kop_operator_links` (section 5.2) |
| 39 operator-only facilities that become their own tiles (section 10.3) | Add them as computed |

---

## 10. Findings from the rehearsal (2026-09-16)

These come from running the offline rehearsal against the production dump.
Where a finding contradicts an earlier section, the finding wins.

Rehearsal result: 10,197 named copies became 4,677 facility rows (4,509
unchanged, 35 split keepers, 45 new rows from splits, 88 rows built from
operator copies that had no id). There are 4,716 memberships, 364 field
conflicts, 45 operators, 642 operator-facility links, 15 operator links, and 0
validation errors. Gate: PASS. 4,524 of the 4,587 facility tiles stay on their
page (all matched by id). The other 63 are wrong placements that are removed
on purpose (section 10.1).

### 10.1 Wrong page placements from substring matching (fixed)

The "Source 2" matcher in `kop_state_collect_programs` and
`kop_country_collect_programs` matched the free-text `location` field by
substring, and by the state's two-letter code as a whole word. It put:

- "La Verne, CA" and "La Verkin, UT" on Louisiana (the word `LA`).
- "Mt. Pleasant", "Mt. Kisco" and "Mt Dora" on Montana (`MT`).
- "De Witt, AR" and "Delaware, OH" on Delaware; "Howey-in-the-Hills, FL" on Indiana (`IN`).
- "Kansas City, MO" on Kansas; "Nevada City, CA" and "Nevada, MO" on Nevada;
  "Ohiopyle, PA" on Ohio; "Oregon, WI" on Oregon; "Washington, CT" on Washington.
- Baja California (Mexico) facilities on California, and "Mexico, MO" on the
  Mexico country page.

Decided 2026-09-16: fix it, don't preserve it. The fix:

- `kop_facility_location_text_places()` in `inc/facility-store.php` reads the
  text as places. It splits on `/`, `;` and `|`, parses each part as an
  address, accepts a capitalized state code at the end ("Provo UT") or a bare
  country, and only then looks for a whole state or country name. It skips
  names inside longer place names ("Kansas City", "Baja California", "New
  Mexico", "West Virginia"), and never matches two-letter codes on their own.
- The live collectors use it, via
  `kop_facility_location_text_names_state()` and `_names_country()`.
  `functions.php` now loads `inc/facility-store.php`. Offline against the
  production data (old vs new collector, all 67 pages): 63 wrong placements
  removed, 1 added (a Jersey facility now also on United Kingdom), nothing else
  changed.
- The v2 normalizer uses the same parser. The first place becomes the primary
  location when there is no address; every other place becomes an
  `additionalLocations` entry. "Viera, FL / Rutland, MA" stays on both pages.
- The rehearsal gate reports the 63 as "removed on purpose" in
  `diff-vs-baseline.txt`, not as losses.

Placements where `locationDetails.state` contradicts the address are a
different problem. They stay flagged for review (32 `membership_disagrees`).

### 10.2 Project row ids were stamped on nested copies

11 of the 45 non-ref rows are old single-facility project rows ("Four
Directions" id 5418, "New Hope Of Arizona, Inc" id 5454, ...). Their nested
entries, and 10 location copies, carry the project row's own id as
`facility_id` (79 copies in total). Those ids also appear on today's tiles,
which is why the baseline has 4,552 distinct ids against 4,544 ref rows. The
rehearsal resolves these copies by (name, state, city), records the old id in
`provenance.legacyIds`, and maps it for the gate
(`project_row_id_on_copies` in `review.tsv`).

### 10.3 Operator copies without an id

131 operator copies match no ref row by (name, state, city), so they become 88
new facility rows. 39 of these show up as additions on a state page. Today
they are merged by name into another tile or never displayed. 10 have no
location and go to the Unknown group.

### 10.4 Identity splits: 35, not about 60

Only 35 ids have copies that disagree on their own address state. The rest of
the 60 multi-state ids in section 2.4 come from copies that have no state of
their own and are only filed under a location row. R4 keeps those on their
pages (50 `place_from_row_only`, 36 `membership_disagrees`). When states do
disagree, cities within one state are compared loosely (typos, a street glued
onto the city), so one campus is never split in two. Hyde School is the test
case: Bath, ME and Woodstock, CT, not three rows.

### 10.5 Link tables are not all keyed by facility id

- `wpdl_kop_facility_addresses` is keyed by facility **name**
  (`facility varchar(191)`), not id. The link-integrity gate in section 8
  cannot check it as written. The 35 split facilities share address rows and
  need a re-seed after the split (`address_rows_name_keyed`).
- `wpdl_kop_media_folder_tags` is `(folder_id, attachment_id)` and holds no
  facility ids. Nothing to re-point.
- `wiki_submissions` links by `facility_unique_name`. A split keeper keeps its
  unique_name.
- `news_facility_links` (245 rows) and `lawsuit_facility_links` (21 rows) are
  keyed by id. Of the 130 links on split ids, 27 stay, 4 are re-pointed by the
  place named in the article location, and 3 cannot be decided
  (`link_unresolved`).
- 15 news links use an **operator** row id as `facility_id` (CEDU, Aspen
  Education Group, Devereux, Family Help & Wellness, Sequel, Teen Challenge,
  Universal Health Services). These are intended: the articles are about the
  operator. They move to `kop_operator_links` (section 5.2), listed in
  `operator_links.tsv`.

### 10.6 Running the rehearsal

Dump the tables with the query in section 1 into one directory as
`facilities_master.tsv`, `locations_master.tsv` and `referrers_master.tsv`.
Then dump the link tables as `links.b64`, one base64 JSON object per line:

```sql
SELECT REPLACE(TO_BASE64(JSON_OBJECT('kind','news','link_id',l.news_id,'facility_id',l.facility_id,'link_type',l.link_type,'title',n.article_title,'location',n.article_location,'mentioned',n.facilities_mentioned)),'\n','')
  FROM news_facility_links l LEFT JOIN news_submissions n ON n.id = l.news_id;
SELECT REPLACE(TO_BASE64(JSON_OBJECT('kind','lawsuit','link_id',l.lawsuit_id,'facility_id',l.facility_id,'link_type',l.link_type,'title',s.case_name,'location',CONCAT_WS(' | ',s.jurisdiction,s.court),'mentioned',s.facilities_mentioned)),'\n','')
  FROM lawsuit_facility_links l LEFT JOIN lawsuits s ON s.id = l.lawsuit_id;
SELECT REPLACE(TO_BASE64(JSON_OBJECT('kind','wiki','link_id',w.id,'facility_unique_name',w.facility_unique_name,'link_type',w.facility_link_status,'title',w.program_name,'location',w.city_state)),'\n','')
  FROM wiki_submissions w WHERE w.facility_unique_name IS NOT NULL AND w.facility_unique_name <> '';
SELECT REPLACE(TO_BASE64(JSON_OBJECT('kind','address','link_id',fa.address_id,'facility_name',fa.facility,'link_type',fa.role)),'\n','')
  FROM wpdl_kop_facility_addresses fa;
```

Then run the steps below. The PHP needs mbstring; with Local's bundled PHP,
pass `-n -d extension_dir=<php>/ext -d extension=php_mbstring.dll`.

```bash
node scripts/snapshot-location-pages.js                       # baseline, if not taken today
php -d memory_limit=2G scripts/normalize-dump.php --dump <dir> --out tmp/rehearsal/normalize-report.txt
php -d memory_limit=3G scripts/rehearse-migration.php --dump <dir> --baseline tmp/baseline-<date>.json
php scripts/normalize-dump.php --dump <dir> --emit copies.jsonl && node scripts/check-facility-normalizer-parity.js copies.jsonl
```

`rehearse-migration.php` exits 0 only when the gate passes. Facility ids for
new rows in the output are provisional (max id + 1). The phase 3 apply step
gets real ids from AUTO_INCREMENT and must map them.

---

## Appendix A: Survey queries

Run as `mysql -N --default-character-set=utf8mb4 ... kidsover_production`.

```sql
-- Wrapper layouts
SELECT JSON_KEYS(json_data) k, COUNT(*) c FROM facilities_master GROUP BY k ORDER BY c DESC;

-- Field type variance for one path
SELECT IFNULL(JSON_TYPE(JSON_EXTRACT(json_data, '$.data.facility.address')), 'MISSING') t, COUNT(*)
FROM facilities_master WHERE JSON_EXTRACT(json_data, '$.__facility_ref') = true GROUP BY t;

-- Status vocabulary
SELECT JSON_UNQUOTE(JSON_EXTRACT(json_data, '$.data.facility.operatingPeriod.status')) s, COUNT(*)
FROM facilities_master WHERE JSON_EXTRACT(json_data, '$.__facility_ref') = true GROUP BY s ORDER BY 2 DESC;

-- Flatten location memberships (JSON_TABLE, MySQL 8)
CREATE TEMPORARY TABLE tmp_loc AS
SELECT l.id loc_id, l.unique_name loc_name, jt.fid, jt.nm, jt.addr, jt.ldst
FROM locations_master l,
     JSON_TABLE(l.json_data, '$.data.facilities[*]' COLUMNS (
       fid INT PATH '$.facility_id',
       nm VARCHAR(255) PATH '$.identification.name',
       addr VARCHAR(255) PATH '$.address',
       ldst VARCHAR(40) PATH '$.locationDetails.state')) jt;

-- Flatten ref rows
CREATE TEMPORARY TABLE tmp_ref AS
SELECT id,
       JSON_UNQUOTE(JSON_EXTRACT(json_data, '$.name')) nm,
       JSON_UNQUOTE(JSON_EXTRACT(json_data, '$.state')) st,      -- 'null' string means JSON null
       JSON_UNQUOTE(JSON_EXTRACT(json_data, '$.data.facility.locationDetails.state')) ld,
       JSON_UNQUOTE(JSON_EXTRACT(json_data, '$.data.facility.address')) addr
FROM facilities_master WHERE JSON_EXTRACT(json_data, '$.__facility_ref') = true;

-- Duplicates per location row
SELECT loc_name, COUNT(*) n, COUNT(DISTINCT fid) d FROM tmp_loc GROUP BY loc_name HAVING n <> d;

-- Facilities in 2+ location rows
SELECT fid, GROUP_CONCAT(DISTINCT loc_name) FROM tmp_loc WHERE fid IS NOT NULL
GROUP BY fid HAVING COUNT(DISTINCT loc_id) > 1;

-- Facilities in no location row
SELECT r.id, r.nm, r.st FROM tmp_ref r LEFT JOIN tmp_loc t ON t.fid = r.id WHERE t.fid IS NULL;

-- Nested address differs from the master row
SELECT COUNT(*) FROM tmp_loc t JOIN tmp_ref r ON r.id = t.fid
WHERE IFNULL(LEFT(t.addr, 80), '') <> IFNULL(LEFT(r.addr, 80), '');
```

Beware: `JSON_UNQUOTE` of a JSON null yields the four-character string
`null`, not SQL NULL. Test for both.

## Appendix B: Files that matter

| File | Role |
|---|---|
| `api/facility-promotion.php` | creates ref rows; name-only identity (root cause 1); reverse linker that appends clones |
| `api/save-master.php` | admin save; also clones facilities into locations_master per state (function around line 693) |
| `api/approve-edits.php`, `api/lib-suggested-edits.php` | public suggestion approval write path |
| `inc/admin.php` | init steps and seed appliers (`kop_apply_facility_record_seeds`, `kop_apply_facility_seed_to_nested`) |
| `inc/database.php` | `kop_unwrap_project_payload`, `kop_get_facilities_projects_from_database`, link attachers |
| `inc/rest-api.php` | `kop_normalize_project_payload` (line ~1025), `kop_state_collect_programs` (~2949), `kop_state_build_program_record` (~2590), `kop_state_merge_program_fields` (~2510), `kop_state_abbrev_to_name` |
| `inc/country-rest-api.php` | country page collector |
| `templates/page-state.php`, `js/state-page.js`, `js/country-page.js` | location index rendering |
| `templates/single-facility-profile.php` | reads `data.facility` directly |
| `js/data-form-modules/project.js`, `data-normalizer.js` | form-side canonical shape and normalizer |
| `api/manage-addresses.php` | existing address tables, rebuilt from facility data |
| `seeds/facility-records.json` | current workaround for the three-copy problem |
| `DATABASE-COLUMNS.md` | schema reference to update in phase 5 |
