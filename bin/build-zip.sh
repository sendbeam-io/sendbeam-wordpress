#!/usr/bin/env bash
# Build build/sendbeam.zip — the file to upload to a WordPress site or to
# unpack into the wordpress.org SVN trunk. Honours .distignore.
set -euo pipefail
cd "$(dirname "$0")/.."
rm -rf build/sendbeam build/sendbeam.zip
mkdir -p build/sendbeam
rsync -a --exclude-from=.distignore ./ build/sendbeam/
( cd build && zip -qr sendbeam.zip sendbeam )
echo "build/sendbeam.zip ($(du -h build/sendbeam.zip | cut -f1))"
