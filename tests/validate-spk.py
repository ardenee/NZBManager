#!/usr/bin/env python3
"""Check DSM test SPK contents, including NO login or install wizard."""
from __future__ import annotations

import io
import json
import tarfile
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
SPK = ROOT / "dist/NZB-Manager-DSM7-0.2.1-0001-noauth-test.spk"
assert SPK.is_file(), f"SPK missing: {SPK}"

with tarfile.open(SPK, "r:") as package:
    names = set(package.getnames())
    expected = {
        "INFO", "package.tgz", "conf/privilege", "conf/resource",
        "PACKAGE_ICON.PNG", "PACKAGE_ICON_256.PNG",
        "scripts/preinst", "scripts/postinst", "scripts/preuninst",
        "scripts/postuninst", "scripts/preupgrade",
        "scripts/postupgrade", "scripts/start-stop-status"
    }
    assert expected.issubset(names), "Missing mandatory SPK members"
    assert not any(name.startswith("WIZARD_UIFILES") for name in names), "Install wizard must not be packaged"
    assert not any(".." in name.split("/") for name in names), "Archive traversal"
    resource = json.load(package.extractfile("conf/resource"))
    service = resource["webservice"]["services"][0]
    assert service["root"] == "nzbmanager/public"
    assert service["php"]["backend"] == 10
    assert service["php"]["user"] == "nzbmanager"
    assert "session.upload_progress.enabled" in service["php"]["php_settings"]

    for name in ("postinst", "postupgrade"):
        script = package.extractfile("scripts/" + name).read().decode("utf-8")
        assert 'touch "$DATA_DIR/no-auth"' in script
        assert "wizard_nzb_password" not in script
        assert "auth.hash" not in script
        assert package.getmember("scripts/" + name).mode & 0o111

    for path, expected_size in (("PACKAGE_ICON.PNG", 64), ("PACKAGE_ICON_256.PNG", 256)):
        data = package.extractfile(path).read()
        assert data[:8] == bytes.fromhex("89504e470d0a1a0a")
        assert int.from_bytes(data[16:20], "big") == expected_size
        assert int.from_bytes(data[20:24], "big") == expected_size

    payload = package.extractfile("package.tgz").read()
    with tarfile.open(fileobj=io.BytesIO(payload), mode="r:gz") as app:
        paths = set(app.getnames())
        assert "webapp/public/index.php" in paths
        assert "webapp/src/WebSecurity.php" in paths
        assert "webapp/src/AppConfig.php" in paths
        assert "webapp/views/download-station.php" in paths
        assert not any(path.startswith(("webapp/current", "webapp/tests", "webapp/dist")) for path in paths)
        security = app.extractfile("webapp/src/WebSecurity.php").read().decode("utf-8")
        config = app.extractfile("webapp/src/AppConfig.php").read().decode("utf-8")
        assert "passwordlessDsm()" in security
        assert "!$passwordlessDsm" in security
        assert "passwordlessDsm()" in config
        assert "'/no-auth'" in config

print("PASS DSM7 test package contains no installation wizard or application password.")
print("PASS Web Station service, package-private no-login marker, icons and source layout.")
print("Validated:", SPK, SPK.stat().st_size, "bytes")
