#!/usr/bin/env bash
# Build an optional DSM7 SPK staging package. Web Station setup is manual.
set -euo pipefail
root="$(cd "$(dirname "$0")/../.." && pwd)"
output="$root/dist"
if [ "$#" -ge 1 ]; then output="$1"; fi
mkdir -p "$output"
output="$(cd "$output" && pwd)"
tmp="$(mktemp -d)"
trap 'rm -rf "$tmp"' EXIT
mkdir -p "$tmp/payload" "$tmp/spk/scripts" "$tmp/spk/conf"
cp -R "$root/public" "$root/src" "$tmp/payload/"
cp "$root/packaging/synology/INFO" "$tmp/spk/INFO"
cp "$root/packaging/synology/conf/privilege" "$tmp/spk/conf/privilege"
cp "$root/packaging/synology/scripts/"* "$tmp/spk/scripts/"
cp "$root/packaging/synology/PACKAGE_ICON.PNG" "$tmp/spk/PACKAGE_ICON.PNG"
chmod 755 "$tmp/spk/scripts/"*
tar -czf "$tmp/spk/package.tgz" -C "$tmp/payload" public src
artifact="$output/NZB-0.2.0-0001-noarch.spk"
tar -cf "$artifact" -C "$tmp/spk" INFO package.tgz scripts conf PACKAGE_ICON.PNG
echo "Built $artifact"
echo "Staging SPK only: Web Station, PHP, and credentials require manual setup."
