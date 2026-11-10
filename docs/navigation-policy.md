# Navigation policy and routing timing

`routing_timing_settings` is the existing singleton base-information table. `walk_speed_mps = 1.2` means 1.2 metres per second. Navigation thresholds live in `navigation_policy`; this does not change door, access-point, node or edge geometry.

Apply `20261110_150000_configurable_route_timing` before `20261111_120000_navigation_policy`. Both have versioned up/down SQL, mirrored inside the Laravel mount, and Artisan wrappers. The navigation migration gives existing application writer roles SELECT/UPDATE on the settings table; anonymous HTTP clients can only read the public policy.

GET `/api/v1/navigation-settings` is public and uncached. GET/PUT `/api/v1/admin/navigation-settings` requires the dashboard administrator role. PUT validates units, limits and relationships and uses `version` for optimistic concurrency (409 on stale edits). The frontend settings dialog exposes the speed and policy fields.

Default Haram policy disables GPS acquisition and correction. Dead reckoning keeps its raw estimate and cumulative uncertainty separate from the display projection. More than 20 m uncertainty or route discrepancy requires re-anchoring; a GPS fix cannot clear that state. The warning threshold is not an arrival radius or a statistical accuracy guarantee.

Validation: NavigationSettingsTest covers anonymous/unauthorized writes, persistence, stale updates, and invalid threshold combinations. CI rehearses SQL apply/rollback/reapply and checks speed and policy persistence. Existing PostgreSQL routing suites verify route output. SQLite cannot run the PostgreSQL spatial suites.

Rollback: restore the previous frontend/backend files first. The additive navigation columns can remain safely; if removing them, apply navigation down before timing down. Timing down restores the original route-speed functions. Back up settings and affected function definitions before production deployment.

Apply `20261111_130000_exact_door_route_timing` after the policy migration on existing installations. It updates the optional ten-argument exact-DAP solver, which may predate configurable timing. CI installs that overload before the timing migration to exercise this upgrade order. Roll it back before the original timing migration; it changes only the speed expression and preserves geometry and function privileges.
