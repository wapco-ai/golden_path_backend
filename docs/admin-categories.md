# Admin category dates and name validation

Category and subcategory responses expose `createdAt` as a UTC ISO 8601 timestamp.
The nullable `categories.created_at` column records new rows using the database
clock. Renaming, changing status, replacing an icon or editing translations does
not replace the creation date. Existing rows whose actual date was never stored
remain `null`; the admin UI shows `-` rather than inventing historical dates.

Persian category names are unique among top-level categories. Subcategory names
are unique within their parent, so different parents can use the same child name.
Inactive rows also reserve their names. Comparison trims and collapses Unicode
whitespace, equates Arabic `ي`/`ك` with Persian `ی`/`ک`, and ignores letter case.
ZWNJ, diacritics and numbers remain distinct. Display labels and translations are
retained as entered. Existing duplicate rows are not deleted or merged; editing
one without changing its normalized name remains possible.

Create and rename paths validate names within a PostgreSQL transaction advisory
lock for the relevant scope, including the empty-scope race. Duplicate batch
children fail before inserting their parent or storing its uploaded icon. Errors
use HTTP 422 with Persian field messages. JSON and multipart `payload` input use
the same validation. File edits use POST with `_method=PUT`; plain JSON edits can
continue using PUT. Uploaded `icon` and `image` file aliases are accepted.

## Deployment

Apply the targeted migration before serving the updated category API. No Docker
image build, routing SQL or data cleanup is required:

```bash
docker compose exec -T app php artisan down --retry=60
git pull --ff-only
docker compose exec -T app php artisan migrate --force --path=database/migrations/2026_10_18_200000_add_category_created_at.php
docker compose exec -T app php artisan optimize:clear
docker compose exec -T app php artisan up
```

Deploy the matching frontend build to display Persian-calendar dates and the
specific server errors. A code update alone cannot recover old creation dates.

## Rollback

Revert the category API/frontend code first. The nullable column can safely remain
in place. If schema rollback is necessary, apply the paired
`db/changes/20261018_200000_category_created_at.down.sql` after reverting the API;
it drops creation-date history but preserves all category rows and translations.
Do not edit the immutable baseline or delete existing duplicate categories.
