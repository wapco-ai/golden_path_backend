# Admin notification read state

Dashboard notifications remain derived from dated non-admin registrations,
comments and support feedback. Reading a notification does not change or delete
the underlying user, comment, approval status or support message.

`admin_notification_reads` records `(admin_user_id, notification_type, entity_id)`
with a database-owned `read_at`. The authenticated admin is always taken from
AdminAuth; a caller cannot select another admin. Repeated reads preserve the
first read timestamp. Existing source records initially remain unread for each
admin; the migration does not invent historical read receipts.

## API

- `GET /api/v1/admin/dashboard/notifications` accepts `limit` (1–50, default 10),
  `unreadOnly` and `includeMeta` (both default false). The default response remains
  a bare array. `includeMeta=true` returns `{ "items": [], "unreadCount": 0 }`.
- IDs are stable strings: `new_user:123`, `new_comment:456`, or
  `support_message:789`. All dates are UTC ISO 8601. User registration timestamps
  use their existing naive-UTC interpretation; missing dates are not presented
  as new registrations. Sorting is chronological before limiting.
- `unreadOnly` filters the source feed before the display limit. `unreadCount`
  counts all existing unread source records for this admin, beyond that limit.
- `POST /api/v1/admin/dashboard/notifications/read` accepts
  `{ "ids": ["new_comment:456"] }` with 1–50 distinct valid IDs. It records only
  the explicitly requested, existing notifications; deleted or missing source
  records are ignored, so future IDs cannot be marked in advance.
- `POST /api/v1/admin/dashboard/notifications/read-all` marks all source records
  visible to one PostgreSQL statement snapshot. It includes old notifications
  and items beyond the display limit; newly committed records after that
  statement snapshot remain unread. It does not loop over only the fetched page.
- Successful read operations return `{ "success": true, "unreadCount": 0 }`
  with the current global count; new events may make that count nonzero.

All three routes use the existing active-admin JWT middleware. Notification read
state is independent for different admins and persists across browser reloads
and sessions. Deleting a source record removes it from the derived feed and
count; unrelated read receipts do not make deleted data reappear.

## Frontend

The current popup shows unread notifications only and refreshes each time it
opens. Its existing badge uses the server count. Clicking a group submits only
the IDs shown in that group; success refreshes the list, while failure retains
the previous unread state. The existing read-all button applies to all ages.
Old reads and requests from a previous admin session cannot overwrite newer
notification state. Labels and layout retain the existing appearance.

## Deployment

Deploy backend before frontend. In the backend repository on `main`, use the
existing running Compose `app` service and a short maintenance window:

```bash
docker compose exec -T app php artisan down --retry=60
git pull --ff-only
docker compose exec -T app php artisan migrate --force --path=database/migrations/2026_10_18_230000_create_admin_notification_reads.php
docker compose exec -T app php artisan optimize:clear
docker compose exec -T app php artisan up
```

Then pull and build the matching frontend. The existing backend bind mount needs
no image rebuild. Historical baseline SQL is unchanged; the versioned up/down
SQL is mirrored under `src/database/sql` for the targeted Laravel migration.

## Rollback

Revert the API/frontend code before dropping `admin_notification_reads`. Keeping
the receipt table is safe. If required, apply the paired versioned down SQL
after reverting the code. Dropping it loses only read history and causes old
source notifications to become unread again; source users and feedback remain.

Raw SQL does not update Laravel's migration history. If the down SQL was used,
recreate the table with the paired `20261018_230000_admin_notification_reads.up.sql`
before redeploying this API; the targeted `artisan migrate` command alone will
skip a migration that is still recorded as applied.
