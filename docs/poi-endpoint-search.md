# POI endpoint search

## Contract

The existing public `GET /api/v1/landmark-places?language=fa&search=...&limit=30`
request used by MPR origin/destination and MPB text search now searches all
matching `poi_points`, across all floors. A nonblank `search` with
`featured=false` (the default) does not require `has_content`, a `contents` row,
an image, or a category assignment. Matching remains the existing localized POI
name match, with the existing Persian fallback, distance ordering and limit.
Filtering is performed before the result limit, not on a preloaded landmark list.

The response shape stays unchanged (`places.landmarkPlaces`, string POI `id`,
authoritative `floor`, `[lat, lon]` coordinates). Blank/absent search and featured
map discovery retain their existing cultural-landmark behavior. POI metadata
is not a replacement for route geometry. Routing still uses DAP/routing edges;
this change does not alter doors, graph construction, route output or steps.

## Deployment

No historical baseline or existing change file is edited. Apply either:

- From the Laravel `src` directory:
  `php artisan migrate --path=database/migrations/2026_09_18_160000_enable_all_poi_endpoint_search.php --force`
- Or run `db/changes/20260918_160000_poi_endpoint_search.up.sql` through psql with
  `ON_ERROR_STOP=1`, using the existing project database connection.

Use one deployment method. Keep the repository's `db/changes` directory alongside
`src`, as the Artisan wrapper reads the versioned SQL file. The matching down SQL
is an exact copy of the preceding floor-aware definition from
`20260917_180000_public_place_floors.up.sql`; it restores the previous search
behavior without deleting any POIs or losing their floor metadata.

Merging source on GitHub does not execute SQL against a local or production DB.

## Verification

`vendor/bin/phpunit -c phpunit.integration.xml` runs `PoiEndpointSearchTest` in the
already-existing CI integration database. It applies the actual deployment
migration inside each test transaction and covers contentless/imageless and
uncategorized POIs with identical names/XY on floors -1/0/1, unchanged blank and
featured discovery, language fallback, limits, no-match input, and rollback/reapply.
No new database infrastructure is introduced.
