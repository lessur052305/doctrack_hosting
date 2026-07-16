#!/usr/bin/env bash
# Rebuilds storage/tesseract-bin — a self-contained copy of the tesseract
# OCR engine, used only as a fallback when the system has no root access to
# run `apt-get install tesseract-ocr` directly (see the docblock on
# TextExtractionService::useBundledTesseractIfPresent()).
#
# This is a repackaging of the official Debian/Ubuntu tesseract-ocr .deb
# packages via `apt-get download` + `dpkg-deb -x`, both of which work
# without root. The result is committed to git under storage/tesseract-bin
# so a fresh clone has working OCR out of the box on Debian/Ubuntu amd64 —
# run this script only if you're on a different distro/architecture and
# need to rebuild it, or if the committed copy stops working.
#
# If you *do* have root on the target machine, skip all of this and just
# run: sudo apt-get install -y tesseract-ocr tesseract-ocr-eng
# TextExtractionService automatically prefers a system install (via $PATH)
# and only falls back to this bundled copy if none is found.
set -euo pipefail

PROJECT_PATH="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
OUT_DIR="$PROJECT_PATH/storage/tesseract-bin"
WORK_DIR="$(mktemp -d)"
trap 'rm -rf "$WORK_DIR"' EXIT

cd "$WORK_DIR"

echo "Downloading .deb packages (no root required)..."
apt-get download tesseract-ocr tesseract-ocr-eng libtesseract5 liblept5 2>&1 \
    || { echo "apt-get download failed — is this a Debian/Ubuntu system with apt sources configured?" >&2; exit 1; }

rm -rf "$OUT_DIR"
mkdir -p "$OUT_DIR"

for deb in *.deb; do
    echo "Extracting $deb..."
    dpkg-deb -x "$deb" "$WORK_DIR/extracted"
done

# Flatten the parts TextExtractionService actually needs into a predictable
# layout: bin/tesseract, lib/*.so*, share/5/tessdata/eng.traineddata
mkdir -p "$OUT_DIR/bin" "$OUT_DIR/lib" "$OUT_DIR/share"
# The app only ever shells out to bin/tesseract, but copy every executable
# tesseract-ocr ships (training utilities like cntraining, mftraining, etc.)
# rather than just that one, so a rebuild reproduces the full committed set.
find "$WORK_DIR/extracted/usr/bin" -maxdepth 1 -type f -exec cp {} "$OUT_DIR/bin/" \;
chmod +x "$OUT_DIR/bin/"*
# dpkg-deb -x resolves the .deb's symlinks into real files rather than
# preserving them as symlinks, so the runtime SONAME (e.g. libtesseract.so.5,
# which the dynamic linker looks for) never lands in $OUT_DIR/lib on its
# own — only the fully-versioned file (libtesseract.so.5.0.3) does. Rebuild
# that SONAME symlink explicitly for every versioned .so we copy.
find "$WORK_DIR/extracted" -type f -name "*.so.*" -exec cp {} "$OUT_DIR/lib/" \;
(
    cd "$OUT_DIR/lib"
    for f in *.so.*.*.*; do
        [ -e "$f" ] || continue
        soname="$(echo "$f" | grep -oE '^.*\.so\.[0-9]+')"
        ln -sf "$f" "$soname"
    done
)
find "$WORK_DIR/extracted" -type d -name "tessdata" -exec cp -r {} "$OUT_DIR/share/5-tmp-tessdata" \; 2>/dev/null || true

mkdir -p "$OUT_DIR/share/5"
if [ -d "$OUT_DIR/share/5-tmp-tessdata" ]; then
    mv "$OUT_DIR/share/5-tmp-tessdata" "$OUT_DIR/share/5/tessdata"
fi

echo ""
echo "Built $OUT_DIR:"
find "$OUT_DIR" -maxdepth 2 -type f -o -maxdepth 2 -type d | sort

echo ""
echo "Verify with:"
echo "  LD_LIBRARY_PATH=$OUT_DIR/lib TESSDATA_PREFIX=$OUT_DIR/share/5/tessdata $OUT_DIR/bin/tesseract --version"
