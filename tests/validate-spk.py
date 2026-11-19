#!/usr/bin/env python3
"""Verify the built DSM 7.2+ SPK, including a fail-closed login."""
from __future__ import annotations

import io
import json
from pathlib import Path
import tarfile
from PIL import Image

ROOT = Path(__file__).resolve().parents[1]
SPK = ROOT / "dist/NZBManager-0.2.1-0001-noarch-test.spk"
assert SPK.is_file(), "SPK missing"
with tarfile.open(SPK, "r:gz") as package:
    names = set(package.getnames())
    for mandatory in ("INFO", "package.tgz", "conf/privilege", "conf/resource",
                      "WIZARD_UIFILES/install_uifile", "PACKAGE_ICON.PNG", "PACKAGE_ICON_256.PNG",
                      "scripts/preinst", "scripts/postinst", "scripts/preuninst",
                      "scripts/postuninst", "scripts/preupgrade",
                      "scripts/postupgrade", "scripts/start-stop-status"):
        assert mandatory in names, f"Missing SPK member {mandatory}"
    for name in names:
        assert ".." not in name.split("/"), "Archive traversal entry"
    resource = json.load(package.extractfile("conf/resource"))
    service = resource["webservice"]["services"][0]
    assert service["root"] == "nzbmanager/public"
    assert service["php"]["backend"] == 10
    assert service["php"]["user"] == "nzbmanager"
    assert "session.upload_progress.enabled" in service["php"]["php_settings"]
    wizard = json.load(package.extractfile("WIZARD_UIFILES/install_uifile"))
    assert wizard[0]["items"][0]["subitems"][0]["key"] == "wizard_nzb_password"
    postinst = package.extractfile("scripts/postinst").read().decode("utf-8")
    assert "openssl passwd -6" in postinst and "wizard_nzb_password" in postinst
    assert "no-auth" not in postinst
    for member in package.getmembers():
        if member.isfile() and member.name.startswith("scripts/"):
            assert b"no-auth" not in package.extractfile(member).read(), "Passwordless lifecycle script"
    assert package.getmember("scripts/postinst").mode & 0o111
    for icon_path, size in (("PACKAGE_ICON.PNG", 64), ("PACKAGE_ICON_256.PNG", 256)):
        with Image.open(io.BytesIO(package.extractfile(icon_path).read())) as icon:
            assert icon.size == (size, size)
    inner = package.extractfile("package.tgz").read()
    with tarfile.open(fileobj=io.BytesIO(inner), mode="r:gz") as application:
        paths = set(application.getnames())
        assert "webapp/public/index.php" in paths
        assert "webapp/src/WebSecurity.php" in paths
        assert "webapp/src/AppConfig.php" in paths
        assert "webapp/views/download-station.php" in paths
        assert not any(path.startswith(("webapp/current", "webapp/tests", "webapp/dist")) for path in paths)
        auth = application.extractfile("webapp/src/WebSecurity.php").read().decode("utf-8")
        config = application.extractfile("webapp/src/AppConfig.php").read().decode("utf-8")
        assert "passwordlessDsm" not in auth and "no-auth" not in auth
        assert "passwordlessDsm" not in config and "no-auth" not in config
        assert "password_verify" in auth and "auth.hash" in config
print("PASS SPK contents, DSM7 resources, icons, password wizard and fail-closed authentication")
print("Verified:", SPK, SPK.stat().st_size, "bytes")
