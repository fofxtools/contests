#!/usr/bin/env bash
#
# rebuild-all.sh — regenerate every derived data file in dependency order.
#
# This is the canonical answer to "what order do things rebuild in." Each
# step just calls an existing script; this file has no logic of its own
# beyond ordering. When a new script joins this chain, add one line here in
# the right slot -- that's the whole update.
#
# Usage: scripts/rebuild-all.sh [--reset-ids]
#   --reset-ids   passed through to gen-entrants.php: renumbers the entrant
#                 ledger from scratch instead of the normal append-only
#                 update. Rare, deliberate, not the default.
#
set -euo pipefail
cd "$(dirname "${BASH_SOURCE[0]}")/.."

RESET_IDS=()
if [[ "${1:-}" == "--reset-ids" ]]; then
    RESET_IDS=(--reset-ids)
fi

step() { echo; echo "=== $* ==="; }

step "build-contest-matches.php"
php scripts/build-contest-matches.php

step "gen-entrants.php ${RESET_IDS[*]:-}"
php scripts/gen-entrants.php "${RESET_IDS[@]}"

step "build-entrants.php"
php scripts/build-entrants.php

step "elo-compute.php"
php scripts/elo-compute.php

step "board8wiki-records.py"
python3 scripts/board8wiki-records.py

step "board8wiki-merge.py"
python3 scripts/board8wiki-merge.py

step "luce-fit.py"
python3 scripts/luce-fit.py

step "gallery-map.py"
python3 scripts/gallery-map.py

step "gallery-audit.py"
python3 scripts/gallery-audit.py

step "extract-round-division.py"
python3 scripts/extract-round-division.py

step "bracket-pick-stats.php"
php scripts/bracket-pick-stats.php

# board8wiki-build-writeups.php is deliberately NOT in this chain: its own
# docstring calls it "a one-time curation pass, not an idempotent rebuild"
# (hand-mapped exceptions for URLs that don't parse cleanly). Run it manually
# and review the diff if data/GameFAQs_contest.html or contest-matches.json
# ever changes in a way that could affect writeup matching.

step "board8wiki-bundle.py"
python3 scripts/board8wiki-bundle.py

echo
echo "=== rebuild-all.sh: done ==="
