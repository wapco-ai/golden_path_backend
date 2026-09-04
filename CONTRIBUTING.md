# GoldenPath Backend Development Workflow

`main` is the protected integration branch.

Development should use a short-lived branch and a pull request.

Recommended branch names:

- `feature/<description>`
- `fix/<description>`
- `chore/<description>`
- `db/<description>`

Before opening a pull request:

1. Make sure no secrets, runtime data, uploads, database data directories,
   dumps, or large generated assets are tracked.
2. Run repository guardrails.
3. Run Composer validation.
4. Review database changes as versioned source.
5. Review routing changes against the routing geometry invariants.
6. Document verification and rollback behavior in the pull request.

Do not modify historical files under `db/baseline/`.
Create a new versioned change under `db/changes/`.

Do not push directly to `main` once repository rules are enabled.
