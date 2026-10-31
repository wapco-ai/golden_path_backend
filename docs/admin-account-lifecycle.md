# Administrator account lifecycle

`POST /api/v1/admin/admins` requires `firstName`, `lastName`, `username`,
`email`, `password` (at least six characters), and a nonempty `roles` array.
The supplied password is hashed into both the administrator credential and the
legacy users credential; it is usable at the first login by username or email.

`PATCH /api/v1/admin/admins/{id}` accepts the existing role/email/password
fields and an optional `status` of `active` or `inactive`. The same section-role
and delegation checks used for editing administrators apply to status changes.

- `inactive` suspends login while preserving roles and credentials. Existing
  refresh tokens are revoked.
- `active` restores login, synchronizes both account-state fields, and clears
  temporary login locks. A valid email, at least one role and an administrator
  credential are required.
- `DELETE` retains its previous behavior: it disables the account and removes
  its roles and administrator credential. Such accounts can be recovered using
  the edit form by supplying roles, email and a new password, and choosing active.

Administrator list/detail responses include `email`, canonical `status` and
`requiresPasswordReset`. The last field tells the edit form that a credential
removed by legacy deletion must be supplied during recovery. Password hashes
are never included in these responses.

In the panel, use the existing edit action to change account status. The details
dialog displays email and status. Validation errors keep the edit form open.

Deploy the backend before the frontend. No database migration is needed. Emails
and original passwords discarded by older releases cannot be reconstructed;
repair affected accounts explicitly through the edit form.

The CI authorization suite covers creation followed by real login, required
credentials, email round trips, suspension/reactivation, legacy recovery and
forbidden status changes using an isolated SQLite database.
