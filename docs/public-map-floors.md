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

## Engineering basemap on the selected floor

Apply `db/changes/20261117_190000_public_engineering_map_floors.up.sql` before
publishing the engineering-map selector. It removes the basement-only guard
from the public display tile function and filters `areas.floor = p_floor`.
The signature, default floor (-1), public-area filter, display attributes and
basement appearance stay unchanged. Null floors return an empty tile. No
routing tables, routing functions or graph data are changed.

Back up the database before applying it. The paired down migration restores
the exact basement-only function. CI rehearses apply/rollback/reapply and tests
identical XY on different floors, public/closed/private areas and null floors.
