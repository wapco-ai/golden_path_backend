# Employer direct authentication

Public OTP login uses the employer's Razavi auth service. Nasim and administrator
authentication are outside this change.
The selected Swagger environment is **Test Server**:
`https://haram-api-dev.razavi.ir/auth` (without a trailing slash).

## Request contract

The existing public endpoints remain:

- `POST /api/v1/auth/otp/request` with JSON `phone`.
- `POST /api/v1/auth/otp/verify` with JSON `phone` and `otp`.

The backend sends `POST /app/v1/login` and `POST /app/v1/verify` under the configured
base URL. As required by the supplied OpenAPI, `mobile`, `project`, `device` and
verification `code` are query parameters, including on POST requests. The phone
is normalized to Iranian national format locally and `+98` format upstream.
Persian and Arabic digits are accepted. The backend does not retry these calls.

After verification, a bearer-authenticated `GET /app/v1/profile` must return a
valid UUID and the same normalized mobile. JWT claims are not decoded and trusted
locally. No API key is required by the supplied login/verify/profile contract.

The OTP request response retains its existing shape, adds `otpLength` and
`retryAfter`, and always returns `devOtp: null` with the Razavi provider.
Provider errors are sanitized, have no development fallback, and do not include
provider response bodies, URLs, codes or credentials. A 429 response includes
`retryAfter` and `Retry-After` where the phone/provider cooldown is known.
IP limits also apply: six sends and twelve verifications per minute.

## Account continuity and session policy

The trusted profile's `(provider, issuer base URL, UUID)` is linked to the existing
local user with the same mobile. National/international mobile aliases, Persian
and Arabic digits, whitespace, parentheses and hyphens in historical records
are normalized in the database lookup; ambiguous matches are rejected. The provider must not
silently replace an existing UUID mapping. Development and production provider
URLs have separate identity namespaces. Local user IDs, saved destinations,
profile data, roles, locked/disabled states and administrator accounts are
preserved. Names are imported only for a newly created public account. Employer
roles and `is_admin` never grant local privileges. The provider profile's
`is_authenticated` and civil-registry verification flags are not login checks.

Verification creates the existing local public JWT/refresh-token session and
discards employer access and refresh tokens. Employer tokens are never returned
to the browser or persisted. Access/refresh expiration, rotation and logout use
the existing local policy (`JWT_PUBLIC_ACCESS_TTL` / `JWT_PUBLIC_REFRESH_TTL`).
Consequently employer revocation, expiry or logout does not terminate an existing
local session, and local logout does not log the user out of employer apps. This
is a direct-login adapter, with no central SSO session/revocation synchronization.

OTP challenges use the configured shared Laravel cache and per-phone locks.
Production must use a persistent cache store shared by application instances;
`array` is only appropriate for tests. Request state is created only after an
accepted upstream send; local accounts are created only after verified identity.
Successful verification consumes the challenge; expiry and the configured
failed-attempt limit require a new OTP. Local account lock/disable checks apply
before sends and verification and again before linking identity.

## Configuration and rollout

Set these values in `src/.env`; their defaults already match the requested pilot:

```dotenv
PUBLIC_AUTH_PROVIDER=razavi
RAZAVI_AUTH_BASE_URL=https://haram-api-dev.razavi.ir/auth
RAZAVI_AUTH_PROJECT=noorolhoda
RAZAVI_AUTH_DEVICE=web
RAZAVI_AUTH_CONNECT_TIMEOUT=3
RAZAVI_AUTH_TIMEOUT=10
RAZAVI_AUTH_OTP_LENGTH=4
RAZAVI_AUTH_OTP_TTL_SECONDS=120
RAZAVI_AUTH_OTP_COOLDOWN_SECONDS=30
RAZAVI_AUTH_OTP_MAX_ATTEMPTS=5
OTP_RETURN_IN_RESPONSE=false
OTP_DEV_BYPASS=false
```

`noorolhoda` is the user's provisional selection until the employer supplies the
proper routing channel. Four digits comes only from the OpenAPI example, not a
guaranteed code length. Length (supported range 4–8), expiry and resend delay are
configurable deployment assumptions and must be confirmed in a live pilot.
The production URL listed by the supplied OpenAPI is
`https://haram-api.razavi.ir/auth`; switching environments requires updating the
base URL explicitly. Keep HTTPS verification enabled. Do not log upstream query
strings: those contain mobile numbers and OTP codes per the employer contract.

Apply the additive identity-table migration before accepting the new login:

```bash
docker compose exec app php artisan migrate --force --path=database/migrations/2026_10_19_000000_create_user_auth_identities.php
docker compose exec app php artisan config:clear
```

For database deployments managed with versioned SQL, the equivalent source is
`db/changes/20261019_000000_user_auth_identities.up.sql`. Choose one schema path;
do not apply both Laravel and SQL creation to the same environment. No baseline
file is modified. The change stores only identity links, never provider secrets.
Restart long-running app processes after configuration changes if applicable.

Before rollout, verify API network access from the backend host and run an
authorized OTP test with an actual test mobile and employer project. Automated
tests use fake HTTP and send no messages. Existing sessions remain valid.

For rollback, restore the previous application version/configuration and leave
the additive mapping table in place. If the table must be removed, stop new
public logins first and apply the matching `.down.sql` (or Laravel rollback for
this migration). Removing mappings does not delete local users or their data.
The explicit `PUBLIC_AUTH_PROVIDER=local` alternative preserves legacy development
OTP for controlled environments; development code responses and bypass require
both local/testing environment and explicit opt-in. Provider failures never
activate that alternative.

In Razavi mode, the legacy public `POST /api/v1/users` endpoint returns
`403 REGISTRATION_DISABLED` before payload validation or writes. Account creation
occurs only inside successful employer identity verification, so an unauthenticated
caller cannot reserve phone aliases and block their owner. Existing frontend
profile completion uses authenticated `PATCH /api/v1/users/me` and remains
available. Legacy creation remains available only with the explicitly selected
local provider.

PostgreSQL uses the nonunique `users_mobile_auth_lookup_idx` functional index for
normalized phone matching. The query and Laravel migration share one fixed SQL
expression; the equivalent versioned SQL keeps the same expression. Fixed
normalization constants let generic prepared plans use the index. Existing
equivalent phone rows remain unchanged and still trigger ambiguity rejection.
The schema rollback removes this index together with identity mappings.

## Verification

`src/tests/Feature/RazaviDirectAuthTest.php` uses an isolated SQLite schema and
`Http::fake()` / `Http::preventStrayRequests()` to exercise the real public routes,
upstream contract, identity checks, local session behavior and failure paths.
Identity migration and SQL roundtrip checks run separately against an isolated
PostgreSQL database. No live employer endpoint is needed for automated checks.
`RazaviAuthPostgresTest` checks the production expression with formatted
disabled/duplicate accounts and verifies index use with 20,000 user fixtures and
a generic prepared plan, without forcing sequential scans off.
