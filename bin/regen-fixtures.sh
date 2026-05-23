#!/usr/bin/env bash
#
# regen-fixtures.sh — regenerate each tests/fixtures/*/expected.css by
# running the upstream `@tailwindcss/cli` JavaScript compiler against
# the fixture inputs.
#
# This script is the source of truth for what "upstream parity" means
# for charged/tailwindphp. Run it:
#
#   - on every release of upstream Tailwind, to catch divergence early
#   - whenever a new fixture is added under tests/fixtures/
#   - whenever a maintainer wants to promote php-seeded baselines
#     (committed by FixtureParityTest's first-run path) to authoritative
#     upstream outputs
#
# Requires:
#   - Node.js 20+ on PATH
#   - npx (ships with Node)
#   - Network access on first run (npx pulls @tailwindcss/cli)
#
# Usage:
#   ./bin/regen-fixtures.sh                  # regen everything
#   ./bin/regen-fixtures.sh <fixture-name>   # regen one fixture
#
# Output: rewrites tests/fixtures/<name>/expected.css with
#   `source=upstream` and the JS compiler's output. Commit the diff.

set -euo pipefail

repo_root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
fixtures_dir="$repo_root/tests/fixtures"
tailwind_version="${TAILWIND_VERSION:-latest}"

if ! command -v npx >/dev/null 2>&1; then
    echo "error: npx is required (install Node.js 20+ and re-run)" >&2
    exit 1
fi

if [[ ! -d "$fixtures_dir" ]]; then
    echo "error: $fixtures_dir does not exist" >&2
    exit 1
fi

# Optional filter — regenerate only the named fixture.
filter="${1-}"

regen_one() {
    local dir="$1"
    local name
    name="$(basename "$dir")"

    if [[ ! -f "$dir/input.html" || ! -f "$dir/input.css" ]]; then
        echo "skip $name: missing input.html or input.css"
        return
    fi

    echo "regenerating $name"

    local tmp
    tmp="$(mktemp -d)"
    trap 'rm -rf "$tmp"' RETURN

    cp "$dir/input.html" "$tmp/input.html"
    cp "$dir/input.css"  "$tmp/input.css"

    # @tailwindcss/cli reads --content to scan, --input as the entry
    # CSS, and writes to --output. We mirror the same invocation the PHP
    # port internally emulates.
    (
        cd "$tmp"
        npx --yes "@tailwindcss/cli@${tailwind_version}" \
            --input ./input.css \
            --content ./input.html \
            --output ./expected.css \
            >/dev/null
    )

    {
        printf '/* fixture: source=upstream tailwind=%s generated=%s */\n' \
            "$tailwind_version" "$(date -u +%Y-%m-%dT%H:%M:%SZ)"
        cat "$tmp/expected.css"
    } > "$dir/expected.css"

    echo "  -> wrote $dir/expected.css"
}

if [[ -n "$filter" ]]; then
    target="$fixtures_dir/$filter"
    if [[ ! -d "$target" ]]; then
        echo "error: no such fixture: $filter" >&2
        exit 1
    fi
    regen_one "$target"
else
    for dir in "$fixtures_dir"/*/; do
        [[ -d "$dir" ]] && regen_one "$dir"
    done
fi

echo
echo "Done. Review the diff with: git diff $fixtures_dir"
