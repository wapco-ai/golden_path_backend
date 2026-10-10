# Multi-floor connectors

Deploy the backend before the matching frontend. Apply the two versioned up.sql files in timestamp order, with a database backup and `psql -v ON_ERROR_STOP=1`. The immutable baseline must not be reimported into an existing environment. Additional building floors are registered in `routing_floors` before importing geometry on those floors.

A connector owns an ordered set of stops. Each stop references a door access point and its walkable area on one floor. Existing door geometry remains display-only. Stops with identical XY coordinates have separate floor-specific graph nodes. Regenerated node IDs are resolved at query time. A DAP replacement is accepted only when unique for the same door and area.

A connector already shared across floors can be reclassified from `stair` to the generic kind `connection` using the existing connector PUT API. This updates **the entire connector**, preserving connector ID, floor-specific stops, DAP identities/geometry, order, direction and travel times. A shared generic connection links consecutive stops (not an elevator-style direct jump). `connection` still denotes an ordinary same-floor door/access point when it has no shared connector ID. The type change does not automatically grant wheelchair access: keep existing `transport_modes` unless a physical/accessibility review explicitly changes them. Deploy `20261010_220000_multifloor_generic_connection.up.sql` before enabling this option on the frontend. Its down.sql refuses rollback while any shared generic connections remain.

Elevators permit direct journeys between all accessible served stops, with one waiting charge per boarding. Stairs, ramps and escalators link consecutive landings only. Direction is relative to the displayed stop order. Wheelchairs cannot traverse stairs/escalators. Existing per-door time, prayer, gender and mode rules apply at boarding/alighting points. A closed intermediate elevator landing does not force passengers to exit there. Incomplete or rebuilding connectors are not used.

The admin API writes the group and all stops atomically and uses an optimistic version to prevent stale edits. New point geometry and metadata are committed together. Standard doors retain their existing API; connected doors are edited through the shared connector endpoint.

Route responses preserve ordered floor segments and explicit transfers. Transfer geometry is null (in particular, a lift with identical XY must not disappear). Per-floor geometries come only from routing edges. The client must not run the floor-unaware local fallback after a multi-floor routing failure.

Verification: the `multifloor-integration` CI job creates a disposable `golden_path_connector_test` database, imports the baseline and versioned changes, and tests real HTTP validation, transactional rollback and pgRouting with three floors. It never uses a development or production database. Frontend tests/build and browser form checks are in the linked frontend PR.

Rollback: restore the previous frontend/backend first, then apply the routing down.sql. Connector schema rollback intentionally refuses populated connector configuration or geometry on additional floors; export/reconcile that data before executing the schema down.sql. Down scripts are transactional.
