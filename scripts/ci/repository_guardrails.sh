#!/usr/bin/env bash

set -euo pipefail

ROOT="$(git rev-parse --show-toplevel)"
cd "$ROOT"

fail()
{
    echo "ERROR: $*" >&2
    exit 1
}

echo "=== Forbidden/runtime tracked files ==="

python3 <<'PY'
import subprocess
import sys

raw = subprocess.check_output(["git", "ls-files", "-z"])

files = [
    x.decode(errors="replace")
    for x in raw.split(b"\0")
    if x
]

exact_forbidden = {
    ".env",
    "src/.env",
    "src/database/database.sqlite",
    "src/laravel12_backend.zip",
}

prefix_forbidden = (
    "docker-data/",
    "src/docker-data/",
    "fonts/",
    "src/public/fonts/",
    "src/vendor/",
    "src/node_modules/",
    "src/storage/logs/",
    "src/storage/framework/cache/",
    "src/storage/framework/sessions/",
    "src/storage/framework/views/",
)

bad = []

for f in files:
    if f in exact_forbidden:
        bad.append(f)
        continue

    if any(f.startswith(prefix) for prefix in prefix_forbidden):
        bad.append(f)
        continue

    if (
        f.startswith("src/storage/app/private/")
        and f != "src/storage/app/private/.gitignore"
        and not f.endswith("/.gitignore")
    ):
        bad.append(f)

if bad:
    print("Forbidden/runtime tracked files found:")
    for item in bad:
        print(" -", item)
    sys.exit(1)

print("OK")
PY

echo
echo "=== Large tracked blobs ==="

python3 <<'PY'
import subprocess
import sys

raw = subprocess.check_output(["git", "ls-files", "-z"])
files = [x.decode(errors="replace") for x in raw.split(b"\0") if x]

bad = []

for rel in files:
    try:
        data = subprocess.check_output(
            ["git", "show", f":{rel}"],
            stderr=subprocess.DEVNULL,
        )
    except subprocess.CalledProcessError:
        continue

    size = len(data)

    if size >= 5 * 1024 * 1024:
        bad.append((size, rel))

if bad:
    for size, rel in sorted(bad, reverse=True):
        print(f"{size / 1024 / 1024:.2f} MiB | {rel}")
    sys.exit(1)

print("No tracked blob >= 5 MiB: OK")
PY

echo
echo "=== High-confidence secret scan ==="

python3 <<'PY'
import re
import subprocess
import sys

raw = subprocess.check_output(["git", "ls-files", "-z"])
files = [x.decode(errors="replace") for x in raw.split(b"\0") if x]

patterns = [
    (
        "GitHub token",
        re.compile(
            r"(?:gh[pousr]_[A-Za-z0-9_]{20,}|github_pat_[A-Za-z0-9_]{20,})"
        ),
    ),
    (
        "AWS access key",
        re.compile(r"\bAKIA[0-9A-Z]{16}\b"),
    ),
    (
        "Private key",
        re.compile(
            r"-----BEGIN (?:RSA |EC |OPENSSH )?PRIVATE KEY-----"
        ),
    ),
]

findings = []

for rel in files:
    try:
        data = subprocess.check_output(
            ["git", "show", f":{rel}"],
            stderr=subprocess.DEVNULL,
        )
    except subprocess.CalledProcessError:
        continue

    if b"\0" in data[:8192]:
        continue

    text = data.decode("utf-8", errors="ignore")

    for label, pattern in patterns:
        if pattern.search(text):
            findings.append((rel, label))

    if rel.endswith(".env") or rel.endswith(".env.example"):
        for no, line in enumerate(text.splitlines(), 1):
            stripped = line.strip()

            if not stripped or stripped.startswith("#") or "=" not in stripped:
                continue

            key, value = stripped.split("=", 1)
            key = key.strip().upper()
            value = value.strip()

            if not any(
                token in key
                for token in ("PASSWORD", "SECRET", "TOKEN", "API_KEY")
            ):
                continue

            if value in ("", "null", '""', "''"):
                continue

            if value.startswith("${"):
                continue

            findings.append(
                (f"{rel}:{no}", f"non-placeholder value for {key}")
            )

if findings:
    print("Potential secret material:")
    for rel, reason in findings:
        print(f" - {rel}: {reason}")
    sys.exit(1)

print("OK")
PY

echo
echo "=== Immutable database baseline ==="

SCHEMA="db/baseline/2026-08-26/golden_path.schema.sql"
MANIFEST="db/baseline/2026-08-26/MANIFEST.txt"

test -f "$SCHEMA" || fail "baseline schema missing"
test -f "$MANIFEST" || fail "baseline manifest missing"

# Verify the canonical repository content rather than raw worktree
# bytes. Text files are stored with LF line endings in Git according
# to the repository .gitattributes policy.
EXPECTED="$(
    git show "HEAD:$MANIFEST" |
    grep 'golden_path.schema.sql' |
    awk '{print $1}' |
    tail -1
)"

ACTUAL="$(
    git show "HEAD:$SCHEMA" |
    sha256sum |
    awk '{print $1}'
)"

echo "manifest: $EXPECTED"
echo "schema  : $ACTUAL"

[ "$EXPECTED" = "$ACTUAL" ] ||
    fail "database baseline SHA256 mismatch"

TABLES="$(grep -c '^CREATE TABLE' "$SCHEMA" || true)"
FUNCTIONS="$(
    grep -Ec '^CREATE (OR REPLACE )?FUNCTION' "$SCHEMA" || true
)"
VIEWS="$(
    grep -Ec '^CREATE( OR REPLACE)? VIEW' "$SCHEMA" || true
)"
MATVIEWS="$(
    grep -c '^CREATE MATERIALIZED VIEW' "$SCHEMA" || true
)"
TYPES="$(grep -c '^CREATE TYPE' "$SCHEMA" || true)"
INDEXES="$(grep -c '^CREATE INDEX' "$SCHEMA" || true)"

echo "objects: $TABLES / $FUNCTIONS / $VIEWS / $MATVIEWS / $TYPES / $INDEXES"

[ "$TABLES" = "60" ] || fail "baseline table count changed"
[ "$FUNCTIONS" = "93" ] || fail "baseline function count changed"
[ "$VIEWS" = "10" ] || fail "baseline view count changed"
[ "$MATVIEWS" = "1" ] || fail "baseline materialized view count changed"
[ "$TYPES" = "13" ] || fail "baseline type count changed"
[ "$INDEXES" = "121" ] || fail "baseline index count changed"

BASELINE_CHANGED=""

if [ "${GITHUB_EVENT_NAME:-}" = "pull_request" ] &&
   [ -n "${GITHUB_BASE_REF:-}" ]; then

    BASELINE_CHANGED="$(
        git diff --name-only \
            "origin/${GITHUB_BASE_REF}...HEAD" \
            -- db/baseline/ || true
    )"

elif [ "${GITHUB_ACTIONS:-}" = "true" ] &&
     git rev-parse HEAD^ >/dev/null 2>&1; then

    BASELINE_CHANGED="$(
        git diff --name-only \
            HEAD^ HEAD \
            -- db/baseline/ || true
    )"

else
    BASELINE_CHANGED="$(
        git diff --cached --name-only \
            -- db/baseline/ || true
    )"
fi

if [ -n "$BASELINE_CHANGED" ]; then
    echo "$BASELINE_CHANGED"
    fail "historical baseline is immutable; create db/changes instead"
fi

echo "Database baseline: OK"

echo
echo "=== Routing policy documentation ==="

grep -F 'doors.geom' db/README.md >/dev/null ||
    fail "doors.geom routing policy missing"

grep -F 'door_access_points.geom' db/README.md >/dev/null ||
    fail "door_access_points.geom routing policy missing"

grep -F 'routing_nodes.geom' db/README.md >/dev/null ||
    fail "routing_nodes.geom routing policy missing"

grep -F 'routing_edges_static.geom' db/README.md >/dev/null ||
    fail "routing_edges_static.geom routing policy missing"

echo "Routing policy documentation: OK"

echo
echo "=== PHPUnit production database safety ==="

grep -F \
    '<env name="APP_ENV" value="testing" force="true"/>' \
    src/phpunit.xml >/dev/null ||
    fail "APP_ENV testing force guard missing"

grep -F \
    '<env name="DB_CONNECTION" value="sqlite" force="true"/>' \
    src/phpunit.xml >/dev/null ||
    fail "DB_CONNECTION test guard missing"

grep -F \
    '<env name="DB_DATABASE" value=":memory:" force="true"/>' \
    src/phpunit.xml >/dev/null ||
    fail "DB_DATABASE test guard missing"

grep -F \
    'GOLDEN_PATH_TEST_DB_GUARD' \
    src/tests/TestCase.php >/dev/null ||
    fail "TestCase database safety guard missing"

echo "PHPUnit DB safety: OK"

echo
echo "=== Docker Compose ==="

POSTGRES_PASSWORD=ci-placeholder \
    docker compose config >/dev/null

echo "Docker Compose: OK"

echo
echo "=================================================="
echo "REPOSITORY GUARDRAILS: PASS"
echo "=================================================="
