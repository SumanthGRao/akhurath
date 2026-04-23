#!/usr/bin/env bash
# Copy logo images from your local "website logo" folder into the site.
# Then either rename them to match config slugs (e.g. plutography.png) or set the `file` key in config/clients.php.
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
DEST="$ROOT/assets/images/client-logos"
SRC="/Users/sumanth/Documents/logo/website logo"
mkdir -p "$DEST"
if [[ ! -d "$SRC" ]]; then
  echo "Source folder not found: $SRC" >&2
  exit 1
fi
shopt -s nullglob
n=0
for f in "$SRC"/*.{png,jpg,jpeg,webp,svg,PNG,JPG,JPEG,WEBP,SVG}; do
  cp "$f" "$DEST/"
  echo "Copied $(basename "$f")"
  n=$((n + 1))
done
if [[ "$n" -eq 0 ]]; then
  echo "No image files found in $SRC" >&2
  exit 1
fi
echo "Done. $n file(s) in $DEST — match names in config/clients.php (\`file\`) or rename to slug + extension."
# Apache/XAMPP runs as another user; without +r the browser gets 403 on images/video.
chmod -R a+rX "$ROOT/assets"
