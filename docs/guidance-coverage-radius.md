# Guidance-point coverage radius

The approved default for NEW guidance points is 100 metres. The admin frontend now submits coverage_radius_m on create and update. Older API clients omitting the field on create get 100 m. Omission during an update preserves the saved radius.

The existing maximum stays 100 m. Validation accepts 0.01 through 100 with at most two decimal places, matching numeric(8,2). Blank, null, zero, negative, nonnumeric and excessive-precision input is rejected instead of silently defaulting or rounding to zero.

Run only the new migration:

    php artisan migrate --database=pgsql --path=database/migrations/2026_09_08_180000_set_guidance_coverage_default_to_100.php

The SQL under db/changes is an alternative for SQL-managed deployments. Historical migrations and db/baseline are unchanged. Changing the default does NOT rewrite existing points: old 10 m radii remain 10 until explicitly edited and saved in the admin form. No automatic bulk update or graph rebuild is performed.

The selector continues using min(max_distance, coverage_radius_m); only its null-radius fallback becomes 100 m. Floor, heading, FOV, POI fallback policy and route geometry rules do not change. A larger radius does not override angular rejection or imply visibility through walls.

Verification: mocked controller tests cover omitted/default and explicit create values, range validation, radius updates without image/geometry replacement, omitted-update preservation, selection SQL and reversible default-only migration. No database server is used by these tests. Verify persistence/reload and spatial behavior on the user's local PostGIS dataset after deployment.

Rollback: revert code through a PR and reset the database default to 10.00 if required. Never overwrite radii already selected by administrators as part of rollback.
