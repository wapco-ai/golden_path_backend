# Explicit administrator section access

Each checkbox in the administrator editor grants its own section. The dashboard
role (`super_dashboard_admin`, `ادمین کل داشبورد`) grants the dashboard; it no
longer overrides missing map, category, user or administrator-management grants.
Both role-code and role-title profiles follow this rule in the frontend.

The backend checks the current database roles on each protected request, so an
already-issued access token cannot restore a removed grant. Legacy permission
rows attached to the dashboard role cannot confer unrelated section access.
Guidance-point mutations require the explicit map-management role.

An administrator with `مدیریت ادمین ها` may only edit accounts and delegate roles
within that administrator's own grants. Role titles are normalized before the
delegation check, matching storage normalization. Dashboard membership does not
allow granting removed or unowned roles.

The frontend revalidates the profile on window focus, when the document becomes
visible and every 30 seconds while visible. Saving the current account's roles
also refreshes its profile immediately. A changed role set discards open editors
and cached section data. Responses from earlier sessions or older profile
requests cannot restore superseded grants.

## Deployment

No database migration or automatic role reassignment is required. Before rollout,
verify that the designated administrator has the explicit section roles needed
for daily work, especially `manage_admins` to manage other administrator accounts.
Do not rely on dashboard membership alone. Existing checkbox selections remain
the source of authority; this deployment does not grant unchecked sections.

Deploy the backend before the matching frontend, using the existing deployment
process and preserving runtime configuration, storage and uploaded files. Clear
the application caches and reload the PHP application/queue after deployment.

## Verification

The isolated SQLite HTTP tests cover current-token revocation, legacy dashboard
permission rows, forbidden delegation (including whitespace variants), explicit
grant restoration, first login with supplied credentials, email round trips,
suspension/reactivation and recovery of legacy deleted accounts.

Browser coverage checks section visibility, self-edited role removal, revocation
while an editor is open, stale profile responses and refresh/logout races. The
existing administrator lifecycle and responsive details-dialog tests remain in
the suite. PostgreSQL integration CI exercises the existing protected endpoints.
