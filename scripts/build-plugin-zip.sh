#!/usr/bin/env bash
set -euo pipefail

cd "$(dirname "$0")/.."

PLUGIN_SLUG="wp-pattern-import"
DIST_DIR="dist"

mkdir -p "$DIST_DIR"
python3 - "$PLUGIN_SLUG" "$DIST_DIR" <<'PY'
import pathlib
import shutil
import sys
import zipfile

slug = sys.argv[1]
dist = pathlib.Path(sys.argv[2])
source = pathlib.Path(slug)
package = dist / slug

if package.exists():
    shutil.rmtree(package)

root_zip = pathlib.Path(slug + ".zip")
dist_zip = dist / (slug + ".zip")
if root_zip.exists():
    root_zip.unlink()
if dist_zip.exists():
    dist_zip.unlink()

shutil.copytree(source, package)

for path in package.rglob(".DS_Store"):
    path.unlink()
for path in package.rglob("__MACOSX"):
    if path.is_dir():
        shutil.rmtree(path)

with zipfile.ZipFile(dist_zip, "w", zipfile.ZIP_DEFLATED) as archive:
    for path in sorted(package.rglob("*")):
        if path.is_file():
            archive.write(path, path.relative_to(dist).as_posix())

shutil.copyfile(dist_zip, root_zip)
PY

unzip -l "$DIST_DIR/$PLUGIN_SLUG.zip" >/dev/null
unzip -l "$PLUGIN_SLUG.zip" >/dev/null

