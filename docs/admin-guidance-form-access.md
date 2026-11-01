# Independent access to the guidance-points form

Every active sidebar form has an assignable role. The guidance-points form now
uses `manage_guidance_points` (`مدیریت نقاط راهنما`) independently from
`manage_map`. A single checkbox grants the whole form, including its read and
write operations; this release introduces no operation-level checkboxes.
The same grant protects generic file uploads/deletions in both the current
`guidance_points` namespace and the legacy `poi_points` gallery namespace.

The administrator add/edit forms use the same frontend role catalog as the
section guards. The existing legacy aliases for registered users and dashboard
access remain compatible. Doors, connectors, van and temporary restrictions
remain tools inside the map-management form.

## Upgrade

Deploy the backend and run only the versioned data migration before deploying
the matching frontend:

```bash
docker exec gp_app php artisan migrate --force --path=database/migrations/2026_11_01_120000_add_guidance_points_admin_role.php
```

This adds one role, one permission and their relation. Existing holders of the
explicit map-management role receive the guidance role once, preserving the
guidance access they already had. Dashboard-only and unrelated accounts receive
no grant. Later map-role changes do not grant or remove the guidance role.
The migration is repeatable, but it must not be rerun manually after administrators
start editing the new selections; let Laravel's migration history track it.

After upgrading, map and guidance checkboxes can be selected independently.
Delegation remains limited to the acting administrator's own roles.

Preserve environment files, storage and uploaded media. Use the running stack's
deployment configuration, clear application caches, restart the queue/PHP service
as appropriate, and validate/reload Nginx after restarting `gp_app`:

```bash
docker exec gp_nginx nginx -t && docker exec gp_nginx nginx -s reload
```

Check a real API response; `/up` may be handled by the SPA fallback in this
production configuration. This is a data-only role migration, with no map,
routing graph, user credential or content changes.

## Verification and rollback

SQLite HTTP tests cover independent grants, forbidden delegation, profile round
trips and immediate revocation with an existing token. Migration tests run on
both SQLite and the isolated PostgreSQL baseline, including repeat execution,
rollback and reapplication. The PostgreSQL HTTP flow exercises the entire
guidance form with only the guidance role. Browser tests cover the catalog in
both administrator forms and independent map/guidance visibility.

If reverting this release, revert both application builds together and roll back
only this migration batch. Its `down()` removes guidance-role assignments and
the new catalog entries; map assignments remain unchanged. Back up the new role
selections before rollback if they need to be retained for a later redeploy.
