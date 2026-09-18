# Facility Document Schema (v2)

The canonical shape of one facility, as stored in `facilities_master.json_data`
after the data model migration (`docs/DATA-MODEL-MIGRATION.md`).

- Implementation: `inc/facility-store.php` (`kop_facility_normalize`,
  `kop_facility_validate`, `kop_facility_save`). Everything that writes a
  facility goes through these.
- Admin form port: `js/data-form-modules/data-normalizer.js` (`facilityToV2`,
  `facilityFromV2`). It must stay identical to the PHP. Check with
  `scripts/check-facility-normalizer-parity.js` (see "Keeping PHP and JS in
  step" below).
- Based on the `facilities[0]` template in `js/data-form-modules/project.js`.

## Rules every document follows

1. **Fixed top-level keys.** Every document has exactly these keys, in this
   order: `schema_version`, `facility_id`, `identification`, `location`,
   `operatingPeriod`, `facilityDetails`, `staff`, `accreditations`,
   `memberships`, `certifications`, `licensing`, `profileLinks`, `resources`,
   `treatmentTypes`, `philosophy`, `conditions`, `criticalIncidents`, `notes`,
   `fieldNotes`, `documentFolderId`, `provenance`, `legacy`. Sections are always
   present, even when empty, so readers never need to check for them.
2. **Lists are always arrays** and **maps are always objects**. An empty map is
   stored as `{}`, never `[]` (`kop_facility_json_encode` handles this).
3. **Numbers are integers or `null`.** Years, capacity, census and ages are
   never strings. If a value could not be read as a number, the original text
   goes into `operatingPeriod.notes` as `migration: unparsed <field>: <value>`.
4. **Nothing is silently dropped.** Unknown keys go into `legacy`. Status
   values outside the allowed set become `Unknown`, and the original value is
   added to `operatingPeriod.notes`.

## Fields

### identification

| Field | Type | Notes |
|---|---|---|
| `name` | string, required | Display name. Legacy fallback order: `identification.name`, `identification.currentName`, `name`, wrapper `displayName`. |
| `nameKey` | string, required | `kop_facility_name_key(name)`. Stored as a generated column and used for identity. |
| `currentName` | string | The name the facility trades under now. Empty when it would only repeat `name`. |
| `otherNames` | string[] | |
| `pastNames` | string[] | Legacy `previousNames` is merged in. |
| `currentOperator` | string | |
| `currentOwners` | string[] | Legacy single `currentOwner` is merged in. |
| `otherOperators` | string[] | Was top-level `otherOperators`. |
| `pastOperators` | string[] | Was top-level `pastOperators`. |
| `knownReferrers` | string[] | |
| `investors` | string[] | Was top-level `investors`. |

`nameKey` follows the same rules as `kop_normalize_facility_name_rules()` in
`inc/rest-api.php`: lowercase, scraper labels removed, "dba" removed, dashes
and punctuation removed, `&` changed to "and", a leading "the" and a trailing
corporate suffix (inc, llc, corp...) dropped, whitespace collapsed. The curated
alias map in `kop_facility_name_aliases()` is **not** applied. Search still
applies it on top of the stored key, because the alias map converts one
rule-normalized key into another. Storing the aliased key would give two
aliased facilities the same identity index value.

### location

Replaces the legacy `address` (string or object), `addressParts`,
`locationDetails` and free-text `location`.

| Field | Type | Notes |
|---|---|---|
| `raw` | string | The original one-line address. Never rewritten by the migration. |
| `text` | string | The original free-text `location` ("Syracuse, UT"). |
| `street` | string | |
| `city` | string | |
| `state` | string or null | Two-letter US code, uppercase. `null` outside the US. |
| `zip` | string | Kept as a string so leading zeros survive ("04530"). |
| `country` | string or null | Canonical English name. `"United States"` whenever `state` is set. |
| `additionalLocations` | `{raw, street, city, state, zip, country}[]` | Other campuses, including further places named in `text`. |
| `formerLocations` | `{raw, city, state, country, fromYear, toYear}[]` | Places the facility moved away from. Years are integers or `null`. |

How each part is resolved, first non-empty value wins:

- **street, city, zip:** address object, then `addressParts`, then
  `locationDetails`, then the parsed `raw` address (or the parsed `text` when
  there is no address), then the wrapper `city`.
- **state:** the parsed `raw` address, then the address object, then
  `addressParts`, then `locationDetails.state`, then the wrapper `state`. The
  address comes first on purpose: the 23 Kansas facilities filed under
  Wisconsin carry the correct state only in their address.
- **country:** `locationDetails.country`, then the address object, then a
  trailing country in the address ("..., Mexico"), then `United States` when a
  state is known, then the non-US `locations_master` row the copy sits in.

Free-text locations (`kop_facility_location_text_places`) are read as places,
never matched by substring. The text is split on `/`, `;` and `|`. Each part
is read in this order: as an address; as ending in a capitalized state code
("Provo UT"); as a bare country; and only then as containing a whole state or
country name ("Southern Utah"). A name inside a longer place name does not
count: "Kansas City", "Nevada City", "Baja California", "New Mexico", "West
Virginia". A two-letter code on its own never matches, so "La Verne, CA" is
California only. When there is no address, the first place is the primary
location; each other place becomes an `additionalLocations` entry. The state
and country pages use the same parser.

Address parsing (`kop_facility_parse_address`) handles
`street, city, ST 12345`, `city, ST`, a trailing country, a zip in its own
segment, and a missing comma between street and city
(`616 High St. Bath, ME` becomes street `616 High St.` and city `Bath`).

State aliases: full names in any case, two-letter codes, trailing zips and
stray dots all map to the code. DC, PR, VI and GU are recognized.

Country aliases: `England`, `Scotland`, `Wales`, `Northern Ireland`, `Great
Britain`, `UK` and `Jersey` become `United Kingdom`. `Jerusalem` becomes
`Israel`, `The Netherlands` becomes `Netherlands`, and `US`, `USA` and `United
States of America` become `United States`. An unknown country is kept as
written, and the validator flags it as a warning.

### operatingPeriod

| Field | Type | Notes |
|---|---|---|
| `startYear` | int or null | |
| `endYear` | int or null | |
| `status` | enum | `Open`, `Closed`, `Suspended`, `Transferred`, `Unknown`. |
| `yearsOfOperation` | string | Free text, as entered. Fills `startYear` when that is empty, and `endYear` only for a Closed facility whose start year agrees (the text often covers one operator's tenure). |
| `notes` | string[] | Also receives migration notes (see rules 3 and 4). |

Status mapping: letter case is ignored. A blank status becomes `Unknown`.
`operating` and `active` become `Open`. `defunct`, `shut down` and `shutdown`
become `Closed`. Anything else, such as "Adults Only", becomes `Unknown`, with
a `migration: original status "..."` note.

### facilityDetails

| Field | Type |
|---|---|
| `type` | string: known synonyms become one spelling (see Standard shapes), others kept as entered |
| `capacity` | int or null |
| `currentCensus` | int or null |
| `ageRange` | `{min: int or null, max: int or null}` |
| `gender` | `Male`, `Female`, `Co-ed` or empty |
| `isPrivatelyOwned` | bool or null (was top-level) |

### Other sections

| Field | Type | Notes |
|---|---|---|
| `staff` | `{administrator[], notableStaff[], pastTTIJobs[]}` | `administrator` and `notableStaff` entries are `{name, role, pastJobs}`; `pastTTIJobs` entries are `{role, organization, employer}`. |
| `accreditations` | `{current[], past[]}` | |
| `memberships`, `certifications`, `licensing`, `notes` | string[] | |
| `profileLinks` | string[] | URLs. |
| `resources` | map | Every standard key is present (see Standard shapes): `hasX` booleans, `xDetails` strings, `customResources[]` and `notes[]`. Keys the form adds later are typed by the same naming rule. |
| `treatmentTypes`, `philosophy`, `conditions`, `criticalIncidents` | maps | Open-ended checklists. A legacy array becomes `{"_legacy": [...]}`. |
| `fieldNotes` | map | A legacy array becomes `{"_legacy": [...]}`. |
| `documentFolderId` | int or null | FileBird folder. |

### Identity and provenance

| Field | Type | Notes |
|---|---|---|
| `facility_id` | int or null | Copy of `facilities_master.id`, so a detached document still knows its row. `null` before the first save. |
| `provenance.sourceProject` | string | Legacy `sourceProject`. |
| `provenance.sourceProjectId` | int or null | Legacy `sourceProjectId`. |
| `provenance.sourceCategory` | string | Legacy `sourceCategory`. |
| `provenance.sourceOperator` | object or null | The operator block the copy was synced with, in a fixed shape (see Standard shapes). |
| `provenance.legacyIds` | int[] | Ids this facility used to share. Set when the identity split gave it a new id, or when old copies carried a project row's id. |
| `provenance.linkedFromRef` | bool | Legacy `linkedFromRef`. |
| `provenance.kopProfileVersion` | int or null | |
| `provenance.migratedAt` | ISO 8601 string | |
| `provenance.uniqueName` | string | The row's `unique_name` at migration time. |
| `provenance.source` | string | Which copy the document was built from (rehearsal and debugging). |
| `legacy` | map | Keys the normalizer does not know. Empty for every row in the 2026-09-16 dump. |

## Standard shapes

`kop_facility_normalize` (and its JS twin) writes every field in one shape, so
every save leaves the document standard. `api/standardize-facilities.php`
re-saved every document on 2026-09-18.

- **People** (`staff.administrator`, `staff.notableStaff`, operator
  `keyStaff.founders` and `keyStaff.keyExecutives`): `{name, role, pastJobs}`.
  A bare string is a name. Entries with nothing in them are dropped.
- **Past TTI jobs**: `{role, organization, employer}`, `employer` mirroring
  `organization` as the admin form writes it. A bare string is the
  organization.
- **Links** (`profileLinks`, operator `websites`): URL strings. An object
  gives its `url`.
- **Resources**: `hasNews`, `newsDetails`, `hasPressReleases`,
  `pressReleasesDetails`, `hasInspections`, `hasStateReports`,
  `hasRegulatoryFilings`, `hasViolations`, `hasSettlements`, `hasLawsuits`,
  `hasPoliceReports`, `hasArticlesOfOrganization`, `hasPropertyRecords`,
  `hasPromotionalMaterials`, `hasEnrollmentDocuments`, `hasResearch`,
  `hasFinancial`, `hasStudent`, `studentDetails`, `hasStaff`, `hasParent`,
  `hasWebsite`, `hasSocialMedia`, `hasAudio`, `hasVideo`, `hasNATSAP`,
  `hasSurvivorStories`, `hasOther`, `customResources`, `notes`, in every
  document.
- **Gender**: `Male`, `Female` or `Co-ed`. Male/boys/men, female/girls/women
  and coed/co-ed/all/both/mixed map directly. A longer value takes its first
  gender word, and the original is kept in `notes` as `Gender as recorded:
  ...`.
- **Type**: `RTC`, `Residential Treatment Center (RTC)` and `Residential
  Treatment Facility` are `Residential Treatment Center`; `PRTF` and
  `Psychiatric Residential Treatment Facility (PRTF)` are `Psychiatric
  Residential Treatment Facility`; `Wilderness`, `Wilderness Program` and
  `Wilderness Therapy Program` are `Wilderness Therapy`; `Juvenile Justice
  Residential Treatment Center` is `Juvenile Justice RTC`; `Therapeutic
  Residential School` and `TBS` are `Therapeutic Boarding School`. Other
  values are kept as entered.
- **Operator block** (`provenance.sourceOperator`): `name`, `currentName`,
  `otherNames[]`, `founded`, `headquarters`, `headquartersCity`,
  `headquartersState`, `location`, `locationCity`, `locationState`,
  `operatingPeriod`, `status`, `websites[]`, `parentCompanies[]`, `owners[]`,
  `investors[]`, `keyStaff {ceo, founders[], keyExecutives[]}`, `notes[]`,
  `fieldNotes[]`. Unknown keys are kept.
- **Additional locations**: the street is re-derived from `raw` whenever
  there is one (the form never writes it). The address parser drops a
  trailing bracketed note and reads `City ST 12345` without a comma before
  the state; a two-letter code is taken this way only when a zip follows.

## Validation (`kop_facility_validate`)

Returns a list of `{rule, severity, path, message}`. A document can be saved
when there are no `error` entries.

| Rule | Severity | Check |
|---|---|---|
| `schema.version` | error | `schema_version` is 2. |
| `schema.keys` | error | Top-level keys are exactly the fixed set. |
| `identity.name` | error | `identification.name` is not empty. |
| `identity.nameKey` | error | `nameKey` equals `kop_facility_name_key(name)` and is not empty. |
| `location.state` | error | `state` is `null` or a canonical two-letter code. |
| `location.country` | error / warning | Error if the name is not canonical; warning if it is not in the known list. |
| `location.mixed` | warning | A US state together with a non-US country. |
| `location.missing` | warning | No state and no country, so the facility lands in the Unknown location group. |
| `status.vocabulary` | error | Status is one of the five allowed values. |
| `types.int` | error | Year, capacity, census and age fields are integers or `null`. |
| `years.order` | warning | `endYear` is not earlier than `startYear`. |
| `types.list` | error | List fields are JSON arrays. |
| `types.map` | error | Map fields are JSON objects. |

Result on the 2026-09-16 production dump (10,199 copies): one key set; 2
errors, both empty form-template placeholders with no name that the state
pages already skip; 1 unknown-country warning (Switzerland); 112 warnings for
copies with no location.

## Identity (`kop_facility_resolve_identity`)

This is the only lookup for an existing facility:

1. Match `(nameKey, state, city)`. The city comparison ignores punctuation and
   case.
2. If the incoming city matches no candidate, a candidate with no city matches.
3. If the incoming record has no city, match `(nameKey, state)` only when
   exactly one candidate exists.
4. A record without a state only matches candidates without a state.

A name alone never identifies a facility that knows where it is. The old
name-only lookup gave "Hope House" in Idaho, North Carolina and Texas a single
id.

Location memberships (`kop_facility_derive_memberships`) come from `state`
(or a non-US `country`), `additionalLocations` and `formerLocations`. The
`location_key` values match `locations_master.unique_name` (`UTAH`, `MEXICO`).
A facility with none of these gets `UNKNOWN`.

## Example

Hyde School in Woodstock, CT. It used to share id 11021 with the Bath, ME
campus; the identity split gave it its own row:

```json
{
  "schema_version": 2,
  "facility_id": 14182,
  "identification": {
    "name": "Hyde School",
    "nameKey": "hyde school",
    "currentName": "",
    "otherNames": [],
    "pastNames": [],
    "currentOperator": "",
    "currentOwners": [],
    "otherOperators": [],
    "pastOperators": [],
    "knownReferrers": [],
    "investors": []
  },
  "location": {
    "raw": "Woodstock, CT",
    "text": "Woodstock, CT",
    "street": "",
    "city": "Woodstock",
    "state": "CT",
    "zip": "",
    "country": "United States",
    "additionalLocations": [],
    "formerLocations": []
  },
  "operatingPeriod": {
    "startYear": null,
    "endYear": null,
    "status": "Closed",
    "yearsOfOperation": "",
    "notes": []
  },
  "facilityDetails": {
    "type": "",
    "capacity": null,
    "currentCensus": null,
    "ageRange": { "min": null, "max": null },
    "gender": "",
    "isPrivatelyOwned": null
  },
  "staff": { "administrator": [], "notableStaff": [], "pastTTIJobs": [] },
  "accreditations": { "current": [], "past": [] },
  "memberships": [],
  "certifications": [],
  "licensing": [],
  "profileLinks": [],
  "resources": { "hasNews": false, "hasLawsuits": false, "customResources": [] },
  "treatmentTypes": {},
  "philosophy": {},
  "conditions": {},
  "criticalIncidents": {},
  "notes": [],
  "fieldNotes": {},
  "documentFolderId": null,
  "provenance": {
    "sourceProject": "",
    "sourceProjectId": null,
    "sourceCategory": "",
    "sourceOperator": null,
    "legacyIds": [11021],
    "linkedFromRef": false,
    "kopProfileVersion": null,
    "migratedAt": "2026-09-16T19:58:45+00:00",
    "uniqueName": "Hyde School (CT)",
    "source": ""
  },
  "legacy": {}
}
```

(`resources` is shortened here. Real rows carry the full `hasX` set from the
form.)

## Keeping PHP and JS in step

```bash
php scripts/normalize-dump.php --dump <dump dir> --emit copies.jsonl
node scripts/check-facility-normalizer-parity.js copies.jsonl
```

As of 2026-09-16, all 10,199 copies match exactly, and the round trip
v2 -> legacy -> v2 is lossless in both languages. Run the check after any
change to either normalizer.

Since the legacy tables were frozen, the copies file is built from
`facilities_v2` itself: each stored document, and its legacy projection
(`kop_facility_to_legacy`, what the form sends), each with the PHP result.
On 2026-09-18 all 9,326 inputs matched, the round trip was lossless, and a
second normalize of every document changed nothing.
