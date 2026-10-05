# Distinct route alternatives

All requests still use the floor-aware live graph. The main route is the same first KSP path with the original access costs. Alternative searches reuse that graph, temporarily penalize middle edges and transfers already searched, and try at most six candidates. Origin/destination anchors, direction, closures, gender, transport mode and transfer timing are unchanged.

For routes using the same physical connectors, a candidate must have no more than 85% middle overlap within a 1.5 m buffer and at least 4 m Hausdorff separation from every route already selected. The first and last 15% of walking distance are ignored for overlap, and comparison is per floor; identical XY on different floors cannot be conflated. Consecutive hops on one elevator count as one physical transfer choice. A different connector can be useful even when walking geometry is identical.

Alternatives are limited to 1.75 times the primary live access cost. Search penalties are temporary and do not affect reported distance, duration or output geometry. If no suitable alternative exists, return fewer routes instead of filling slots with nearly identical options. `maxAlternatives=0` performs one shortest-path search.

## Deploy

The Docker application mount includes `src` only, so the migration reads the identical versioned SQL copy in `src/database/sql`. After deploying the backend code, run the targeted migration from the stack directory:

```bash
docker compose exec -T app php artisan migrate --path=database/migrations/2026_10_05_170000_restore_diverse_route_alternatives.php --force
```

Use the actual application service name from `docker compose config --services` (the repository currently calls it `app`). Alternatively, apply `db/changes/20261005_170000_diverse_route_alternatives.up.sql` with `psql -v ON_ERROR_STOP=1`. Do not import the historical baseline into an existing environment. No frontend deployment or graph rebuild is required.

## Verification and rollback

CI loads the immutable baseline and connector functions into its isolated test database, then applies this change. Regressions exercise actual DAP-derived graph edges, near-duplicate door choices, distinct corridors, per-floor comparisons, accessible physical transfers, original primary-route parity and the API's requested alternative count. Existing connector, one-way, closure, wheelchair and route-history tests remain active.

The paired down.sql restores the preceding function exactly. The Laravel migration's `down()` reads that same rollback SQL. Existing connector configuration and geometry are not removed or rewritten.
