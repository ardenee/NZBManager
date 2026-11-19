#!/bin/sh
set -eu
ROOT=/mnt/c/Users/arden/source/repos/nzb
TEST_DATA="$ROOT/dist/_auth_smoke"
export SYNOPKG_PKGVAR="$TEST_DATA"
export wizard_nzb_password="test-install-password-5678"
sh "$ROOT/packaging/dsm7/scripts/postinst"
test -s "$TEST_DATA/auth.hash"
test ! -e "$TEST_DATA/no-auth"
test -d "$TEST_DATA/nzb"
test -d "$TEST_DATA/sessions"
printf '%s\n' "PASS DSM test installer creates password hash and persistent folders"
