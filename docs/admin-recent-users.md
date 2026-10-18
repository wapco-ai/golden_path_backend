# Dashboard recent-user details

`GET /api/v1/admin/dashboard/recent-users` keeps its existing admin JWT guard,
search, pagination, user composition and `{ data, pagination }` response.
The dashboard row and its existing details popup now use actual profile data.

## Data contract

Each row retains `id`, `name`, `email`, `mobile`, `joinDate` and `successCount`.
Additional profile fields are:

| Field | Source and meaning |
| --- | --- |
| `phone` | Alias of `users.mobile`; no country prefix is invented. |
| `birthDate` | `users.birth_date`, Gregorian `YYYY-MM-DD` or null; a date, not an instant. |
| `gender` | Stored `male`, `female` or `other`; unknown legacy values become null. |
| `province`, `city` | String members of `users.address`; missing or malformed values become null. |
| `lastLoginAt` | Actual `users.last_login_at`, UTC ISO 8601 or null. |
| `savedRoutesCount`, `placeVisitsCount` | Null: no server source currently measures these two activities. |

`joinDate` uses the existing naive-UTC interpretation of `users.created_at`,
serialized as explicit UTC ISO 8601. Unknown registration history stays null;
it is never replaced by the current time. `successCount` retains the existing
count of successful route requests (`route_logs.ok` and the user ID in metadata);
it is not a count of saved routes or confirmed place visits. Malformed legacy
metadata is ignored safely instead of failing the whole profile list.

Only the displayed profile fields are selected for this response. Full street
address, national ID, login IP, password and OTP fields are not returned. The GET
does not update profiles, login times, route logs or any other source records.

## Frontend behavior

The existing six-column table, popup, labels and controls stay in place. DOB is
displayed as a Jalali calendar date without shifting its day with the browser
timezone. Registration and login instants use Tehran time. Missing data uses the
existing missing-value display; real numeric zero remains zero. The current-page
Excel action uses the same real success count and unmodified mobile as the table.

## Deployment and rollback

No schema change or migration is required. Deploy the backend before the
matching frontend so the new profile fields are available immediately.
In the backend repository, on `main` with the existing running Compose service:

```bash
git pull --ff-only
docker compose exec -T app php artisan optimize:clear
```

Then, in the frontend repository on `main`:

```bash
git pull --ff-only
npm ci
npm run build
```

Use the existing frontend hosting process to serve the resulting build. The
backend uses the existing bind mount and needs no image rebuild for this change.
Rollback is a code rollback; no database records need restoration or deletion.
An older backend omits the new fields, which the new frontend shows as unknown.

## Verification

`DashboardRecentUsersAdminTest` runs against real PostgreSQL with actual admin
JWTs and a real successful public OTP verification. It covers distinct profiles,
unknowns, timezones, search/pagination, malformed metadata and bigint IDs,
private-field exclusion, and source immutability after dashboard reads.

The frontend helper tests cover date-only birthdays, UTC legacy timestamps,
Tehran midnight, invalid values, real zero and three host timezones. Browser
coverage checks the existing table/details layout, two different birthdays,
missing values, refresh/retry, pagination/search and the existing Excel action.
