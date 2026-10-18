# Registered-user report

## Last manual activation

`users.last_manual_activation_at` is a nullable timestamp with time zone. The
admin user API exposes it as `lastManualActivationAt` in UTC ISO 8601 format in
list, details and status-update responses. It records the server database time
only when an administrator changes a nonactive user to active. A repeated active
request preserves the timestamp; deactivation retains the previous value.
Concurrent updates lock and recheck the user row before deciding whether a new
activation occurred.

Registration, OTP verification, ordinary login and profile updates do not count
as manual activation. Old rows remain unknown (`null`) rather than receiving an
invented date during deployment. Authentication locks and counters retain their
existing behavior; this field is history, not an automatic unlock deadline.

## Registration-date filter

`GET /api/v1/admin/users` accepts optional `startDate` and `endDate` in Gregorian
`YYYY-MM-DD` format, from `0001-01-02` onward. This minimum keeps converted UTC
bounds out of unsupported PostgreSQL year zero. Dates represent calendar days
in `Asia/Tehran`. The start is
inclusive and the end includes its entire local day, implemented as an exclusive
upper bound at the next local midnight. The database compares the corresponding
UTC bounds with the existing naive-UTC `users.created_at` column.

Filtering happens before pagination and works with search. Existing page-size
limits remain unchanged. The frontend accepts Jalali dates with Persian, Arabic
or Latin digits and sends validated Gregorian date strings; display uses Tehran
time. Uncommitted or invalid input does not become a server filter.

## Deployment

Run these commands from the backend repository on `main`, with the existing
Compose `app` service running. The maintenance window prevents requests from
using the new field before its migration exists. No image build is required:

```bash
docker compose exec -T app php artisan down --retry=60
git pull --ff-only
docker compose exec -T app php artisan migrate --force --path=database/migrations/2026_10_18_220000_add_user_manual_activation_at.php
docker compose exec -T app php artisan optimize:clear
docker compose exec -T app php artisan up
```

Deploy the matching frontend build. Historical activation times cannot be
reconstructed from `updated_at` or the last login date.

## Rollback

Revert the related API/frontend code first. The nullable column can remain safely.
If schema rollback is necessary, apply the paired
`db/changes/20261018_220000_user_manual_activation_at.down.sql` after reverting the
API. That drops the activation history, preserving users, status, registration
dates and authentication data. The immutable baseline is unchanged.
