# GoldenPath Database Changes

The historical database baseline under `db/baseline/` is immutable.

Never edit an existing baseline to represent a new database change.
Every database change after the baseline must be committed as a
versioned migration/change file.

## What belongs in Laravel migrations

Use `src/database/migrations/` for ordinary application-owned relational
schema changes when Laravel can express the change clearly and safely.

Examples:

- application tables
- columns
- ordinary indexes
- ordinary foreign keys
- application-level constraints

## What belongs in db/changes

Use `db/changes/` for PostgreSQL/PostGIS/database-native changes.

Examples:

- PostgreSQL functions and procedures
- views and materialized views
- triggers
- PostGIS-specific schema
- pgRouting-related objects
- routing graph SQL
- controlled database data fixes
- database-native constraints that should not be hidden inside PHP

## File naming

Use UTC sortable timestamps:

`YYYYMMDD_HHMMSS_short_description.up.sql`

When a safe rollback exists, add:

`YYYYMMDD_HHMMSS_short_description.down.sql`

Example:

`20260902_190000_fix_routing_edge_source.up.sql`

`20260902_190000_fix_routing_edge_source.down.sql`

## SQL change header

Every SQL change must begin with a short header describing:

- purpose
- dependencies
- affected database objects
- routing impact
- rollback strategy

Schema/function changes should normally be transaction-safe when
PostgreSQL permits it.

## Routing geometry invariants

These rules are architectural requirements, not suggestions:

- `doors.geom` is raw drawing/display geometry only.
- `door_access_points.geom` is the only permitted door geometry source
  for routing.
- `routing_nodes.geom` must be created from `door_access_points.geom`.
- `routing_edges_static.geom` must be created from `routing_nodes.geom`.
- Route output and route steps must come from
  `routing_edges_static` or `door_access_points`, never `doors.geom`.

A change that violates these rules must not be merged.

## Production changes

Do not develop a database change by manually modifying production and
then trying to reconstruct the SQL afterward.

The versioned file is the source change.

Apply the reviewed versioned change to each environment in a controlled
order.

## Tests

The current baseline has PostgreSQL/PostGIS-specific migrations.
Full integration testing will use an isolated PostgreSQL test database.

Until that isolated test database is added to CI, CI must not point
Laravel tests at the development or production `golden_path` database.
