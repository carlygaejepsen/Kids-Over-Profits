# Seeing Every Field of a Facility Record

**The "Show All Fields" view described in earlier versions of this file (`/tti-program-index/?view=all`) no longer exists.** The directory now has two tabs only, by parent company and by location (`?view=location`), and loads a slimmed list first, fetching a company's or place's full records only when a visitor opens it (`inc/directory-feed.php`, `js/tti-program-index.js`). This page says where to see a record's fields today.

## Where the fields are shown

| Need | Where |
|---|---|
| Everything public about one facility | Its generated page, `https://kidsoverprofits.org/facility/<slug>/` (`inc/facility-pages.php`, `templates/facility-page.php`): names by era, operator, staff, findings, lawsuits, news, federal funding, documents, sources |
| Everything about a parent company | `https://kidsoverprofits.org/operator/<slug>/` (`templates/operator-page.php`) |
| Browse and search | `https://kidsoverprofits.org/tti-program-index/` (by company) and `?view=location` (by place) |
| Edit every field (admins) | The admin data form (`templates/page-admin-data.php`, `api/save-master.php`), or the pencil on the facility page (`inc/inline-edit.php`) |
| Every record, in bulk | `https://kidsoverprofits.org/open-data/` daily CSV/JSON downloads (`inc/open-data.php`; public columns only) |
| The raw JSON document | `facilities_v2.json_data` (one document per facility; mirror in `tmp/prod.sqlite`) |

## Data source

- Facility records: `facilities_v2` (schema `docs/FACILITY-SCHEMA.md`); parent companies in `{prefix}kop_operators`. The legacy `facilities_master` table is frozen.
- Directory feed: `GET /wp-json/kop/v1/facilities?view=index` for the lists, `?view=detail&key[]=...` for opened entries; `api/get-master-data.php` is the fallback.
- A field the directory's closed lists need must be added to `kop_directory_feed_slim_facility()`; a field the facility page shows is added in `inc/facility-pages.php` (and in `inc/mobile-api.php` keep lists for the app).

## Schema references

- `docs/FACILITY-SCHEMA.md` - the facility document, field by field
- `DATABASE-COLUMNS.md` - tables and columns
- `api/get-database-schema.php` - live schema inspector (`?format=html`)
