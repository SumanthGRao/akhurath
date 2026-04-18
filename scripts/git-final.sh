#!/usr/bin/env bash
# Stage all changes, commit with message "final" (if there is anything to commit), then push.
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT"

git add .

if git diff --cached --quiet; then
  echo "Nothing staged to commit."
else
  git commit -m "final"
fi

git push
