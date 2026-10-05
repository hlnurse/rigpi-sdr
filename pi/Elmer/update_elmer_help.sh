#!/usr/bin/env bash
set -euo pipefail

# Usage:
#   sudo /home/pi/Elmer/update_elmer_help.sh /path/to/HTML.zip
#
# Output:
#   /home/pi/Elmer/output/Elmer_Help_Knowledge_Update_YYYYMMDD-HHMMSS.tar.gz

ELMER_ROOT="/home/pi/Elmer"
INPUT_ZIP="${1:-}"

if [[ -z "${INPUT_ZIP}" || ! -f "${INPUT_ZIP}" ]]; then
	echo "Usage: $0 /path/to/HTML.zip"
	exit 1
fi

cd "${ELMER_ROOT}"

STAMP="$(date +%Y%m%d-%H%M%S)"
TMPDIR="$(mktemp -d /tmp/elmer-help.XXXXXX)"
NORMALIZED="${ELMER_ROOT}/Help-new-normalized.zip"
BACKUP="${ELMER_ROOT}/sources/Help.zip.backup-${STAMP}"
BUNDLE_DIR="${ELMER_ROOT}/output/elmer-knowledge-update-${STAMP}"
BUNDLE_TGZ="${ELMER_ROOT}/output/Elmer_Help_Knowledge_Update_${STAMP}.tar.gz"

cleanup() {
	rm -rf "${TMPDIR}"
}
trap cleanup EXIT

echo
echo "=== RigPi Ask Elmer Help Update ==="
echo "Input: ${INPUT_ZIP}"
echo "Stamp: ${STAMP}"
echo

# ------------------------------------------------------------
# 1. Determine archive root
# ------------------------------------------------------------

if unzip -Z1 "${INPUT_ZIP}" | grep -q '^HTML/'; then
	SOURCE_ROOT="HTML"
elif unzip -Z1 "${INPUT_ZIP}" | grep -q '^Help/'; then
	SOURCE_ROOT="Help"
else
	echo "ERROR: ZIP must contain a top-level HTML/ or Help/ directory."
	exit 1
fi

echo "Detected source directory: ${SOURCE_ROOT}/"

# ------------------------------------------------------------
# 2. Normalize to Help/
# ------------------------------------------------------------

mkdir -p "${TMPDIR}/Help"

unzip -q "${INPUT_ZIP}" "${SOURCE_ROOT}/*" -d "${TMPDIR}"

cp -a "${TMPDIR}/${SOURCE_ROOT}/." "${TMPDIR}/Help/"

# Remove macOS metadata if present
find "${TMPDIR}/Help" \
	\( -name '._*' -o -name '.DS_Store' \) \
	-delete

rm -rf "${TMPDIR}/${SOURCE_ROOT}"

rm -f "${NORMALIZED}"

(
	cd "${TMPDIR}"
	zip -qr "${NORMALIZED}" Help
)

echo
echo "Normalized Help archive created:"
ls -lh "${NORMALIZED}"

# ------------------------------------------------------------
# 3. Compare Help topic counts
# ------------------------------------------------------------

OLD_COUNT="$(
	unzip -Z1 sources/Help.zip 2>/dev/null |
	grep -E '^Help/.*\.html$' |
	wc -l
)"

NEW_COUNT="$(
	unzip -Z1 "${NORMALIZED}" |
	grep -E '^Help/.*\.html$' |
	wc -l
)"

echo
echo "Help topics:"
echo "  Existing: ${OLD_COUNT}"
echo "  New:      ${NEW_COUNT}"

if [[ "${NEW_COUNT}" -lt 1 ]]; then
	echo "ERROR: No HTML Help topics found."
	exit 1
fi

# ------------------------------------------------------------
# 4. Backup and install new Help source
# ------------------------------------------------------------

echo
echo "Backing up current Help source:"
cp -a sources/Help.zip "${BACKUP}"
echo "  ${BACKUP}"

cp -a "${NORMALIZED}" sources/Help.zip

# ------------------------------------------------------------
# 5. Build Elmer knowledge database
# ------------------------------------------------------------

echo
echo "=== Building Ask Elmer knowledge base ==="

python3 build_knowledge.py

# ------------------------------------------------------------
# 6. Validate generated files
# ------------------------------------------------------------

echo
echo "=== Validating Help images and knowledge database ==="

python3 validate_owned_images.py \
	--db output/knowledge.db \
	--images output/owned-images

echo
echo "=== SQLite quick check ==="

python3 - <<'PY'
import sqlite3

path = "output/knowledge.db"

with sqlite3.connect(path) as db:
	result = db.execute("PRAGMA quick_check").fetchone()[0]

if result != "ok":
	raise SystemExit(f"knowledge.db quick_check failed: {result}")

print("knowledge.db quick_check: ok")
PY

# ------------------------------------------------------------
# 7. Show build summary
# ------------------------------------------------------------

echo
echo "=== Build Summary ==="

python3 - <<'PY'
import json
from pathlib import Path

report = json.loads(
	Path("output/build-report.json").read_text(encoding="utf-8")
)

s = report["summary"]

print(f"Topics:                {s['topics']}")
print(f"Approved images:       {s['approved_images']}")
print(f"Broken internal links: {s['broken_internal_links']}")
print(f"Missing images:        {s['missing_images']}")
print(f"Missing anchors:       {s['missing_anchors']}")
print(f"Overall quality:       {s['overall_quality']}%")
PY

# ------------------------------------------------------------
# 8. Restart local Elmer so it reopens the rebuilt DB
# ------------------------------------------------------------

echo
echo "=== Restarting local Elmer ==="

systemctl restart elmer-web
sleep 2

if systemctl is-active --quiet elmer-web; then
	echo "elmer-web: active"
else
	echo "ERROR: elmer-web failed to restart."
	systemctl status elmer-web --no-pager
	exit 1
fi

# ------------------------------------------------------------
# 9. Build cloud update bundle
# ------------------------------------------------------------

echo
echo "=== Creating cloud update bundle ==="

rm -rf "${BUNDLE_DIR}"
mkdir -p "${BUNDLE_DIR}/deploy"

cp -a output/knowledge.db "${BUNDLE_DIR}/"
cp -a output/owned-images "${BUNDLE_DIR}/"
cp -a validate_owned_images.py "${BUNDLE_DIR}/"

INSTALLER="output/elmer-knowledge-update-20260823/deploy/install_owned_knowledge_update.sh"

if [[ ! -f "${INSTALLER}" ]]; then
	echo "ERROR: cloud installer not found:"
	echo "  ${INSTALLER}"
	exit 1
fi

cp -a "${INSTALLER}" "${BUNDLE_DIR}/deploy/"

rm -f "${BUNDLE_TGZ}"

tar -C output -czf "${BUNDLE_TGZ}" "$(basename "${BUNDLE_DIR}")"

echo
echo "Cloud update bundle:"
ls -lh "${BUNDLE_TGZ}"

# ------------------------------------------------------------
# 10. Final instructions
# ------------------------------------------------------------

echo
echo "============================================================"
echo "LOCAL UPDATE COMPLETE"
echo "============================================================"
echo
echo "Local Help source:"
echo "  ${ELMER_ROOT}/sources/Help.zip"
echo
echo "Backup:"
echo "  ${BACKUP}"
echo
echo "Cloud package:"
echo "  ${BUNDLE_TGZ}"
echo
echo "Next:"
echo "  1. Copy the .tar.gz file to the cloud Elmer server."
echo "  2. On the cloud server:"
echo
echo "     tar -xzf $(basename "${BUNDLE_TGZ}")"
echo "     cd $(basename "${BUNDLE_DIR}")"
echo "     sudo ./deploy/install_owned_knowledge_update.sh knowledge.db owned-images"
echo
echo "  3. Test Ask Elmer from the RigPi browser."
echo
echo "73 from Elmer!"