# Cultural content creation dates

`GET /api/v1/cultural-items` and `GET /api/v1/cultural-items/{id}` expose
`createdAt` as an ISO 8601 UTC timestamp or `null`. The date describes cultural
content, not the POI's creation or last geometry update.

An item groups every language's `contents` row. Its date is the earliest recorded
creation time only when every row has a known date. An item with any unknown
legacy row remains unknown when edited or when another translation is added.
POIs without content remain in the existing list with `createdAt: null`.

The migration adds a nullable `contents.created_at` column without a default,
then enables `CURRENT_TIMESTAMP` for future inserts. Existing rows remain
`NULL`; no dates are inferred or backfilled. API inserts use the database clock
and `timestamptz` records the instant independently of session timezone; output
is always UTC. Conflict updates exclude `created_at`, preserving original dates
and unknowns.

## Deployment

With the current bind-mounted `src` deployment, briefly enable maintenance before
pulling the release so the new API cannot run against a missing column:

```sh
docker compose exec -T app php artisan down
git pull --ff-only
docker compose exec -T app php artisan migrate --force --path=database/migrations/2026_10_18_160000_add_cultural_content_created_at.php
docker compose exec -T app php artisan optimize:clear
docker compose exec -T app php artisan up
```

Run from the backend repository and stop if the pull or migration fails; keep
maintenance enabled until the failure is resolved or the prior code is restored.
The migration loads the packaged SQL under `src/database/sql/`; it matches
`db/changes/20261018_160000_cultural_content_created_at.up.sql`. The latter can
also be applied with `psql -v ON_ERROR_STOP=1` by deployments managing SQL
directly. Do not run both paths as separate release steps.

## Rollback

Revert the API code first. The nullable additive column can remain in place
without affecting the previous API. If schema rollback is required, run the
matching migration rollback (limited to this migration) or the matching
`db/changes/20261018_160000_cultural_content_created_at.down.sql` file. This
drops only `created_at` and permanently discards recorded creation dates;
content, media, POIs, geometry, and restrictions are retained. Reapplying the
migration leaves those existing dates unknown again.
