# Public map floor selection

Public landmark/search results now include the POI's integer `floor`. The
function signature, result ordering and existing fields remain unchanged.
The public QR endpoint adds `[lat, lon]` coordinates and nullable `floor`:
an explicit `attrs.floor` wins, otherwise a referenced POI/door/area supplies
the floor. Unknown, invalid or unconfigured floors remain null. Coordinates
alone cannot identify a floor.

Apply `db/changes/20260917_180000_public_place_floors.up.sql` once after taking
a database backup. The change is transactional and safe to reapply. No graph
rebuild is needed. Deploy backend code and SQL before the public frontend.
The paired `.down.sql` restores the prior landmark response; no records are
deleted. Older clients tolerate the added response fields.

The frontend keeps display floor separate from both route endpoints. GPS and
legacy coordinates without floor metadata require an explicit floor choice
before routing. Search remains available across all floors.

Validation: `phpunit -c phpunit.integration.xml` runs against the isolated
PostgreSQL/PostGIS/pgRouting database in CI, including identical XY points on
different floors, QR floor resolution, invalid metadata and existing
multi-floor connector routing. CI also checks SQL rollback/reapply and PHP
syntax. Historical database baseline files remain immutable.
