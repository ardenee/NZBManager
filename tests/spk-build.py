#!/usr/bin/env python3
"""SPK build regression: only modern web source + DSM overlay is required."""
from __future__ import annotations

import hashlib
import shutil
import subprocess
import sys
import tarfile
import tempfile
from pathlib import Path

SOURCE = Path(__file__).resolve().parents[1]
VERSION = "0.4.4-0001"


def run(*args: str, cwd: Path) -> str:
    done = subprocess.run([sys.executable, *args], cwd=cwd, capture_output=True, text=True)
    if done.returncode:
        raise AssertionError(f"Command failed ({done.returncode}): {args}\n{done.stdout}\n{done.stderr}")
    return done.stdout


def main() -> None:
    with tempfile.TemporaryDirectory(prefix="nzbm-standalone-spk-") as temp:
        clean = Path(temp) / "generic-web-source"
        clean.mkdir()
        for folder in ("public", "src", "views", "bin", "packaging"):
            shutil.copytree(SOURCE / folder, clean / folder,
                            ignore=shutil.ignore_patterns("__pycache__", "*.pyc"))
        assert not (clean / "current").exists()
        assert not (clean / "original-spk").exists()
        builder = clean / "packaging/dsm7/build.py"

        result = run(str(builder), "--version", VERSION, cwd=clean)
        path = clean / f"dist/NZB-Manager-DSM7-{VERSION}-noauth-test.spk"
        assert path.is_file() and "generic application files=" in result
        first_digest = hashlib.sha256(path.read_bytes()).hexdigest()
        assert "PASS: verified" in run(str(builder), "--verify", str(path), cwd=clean)
        with tarfile.open(path) as spk:
            members = spk.getnames()
            assert members[:2] == ["INFO", "package.tgz"]
            assert "scripts/start-stop-status" in members
            assert "conf/resource" in members
            assert "PACKAGE_ICON_256.PNG" in members
            payload = spk.extractfile("package.tgz")
            assert payload is not None
            with tarfile.open(fileobj=payload, mode="r:gz") as inner:
                names = inner.getnames()
                assert "webapp/public/index.php" in names
                assert "webapp/src/Modules/Par2DiskMatrix.php" in names
                assert "webapp/bin/automation-worker.php" in names
                assert "original-icons/PACKAGE_ICON_120.PNG" in names
                assert not any(n.startswith(("webapp/current/", "webapp/original-spk/",
                                             "webapp/var/", "webapp/tests/")) for n in names)
        run(str(builder), "--version", VERSION, cwd=clean)
        second_digest = hashlib.sha256(path.read_bytes()).hexdigest()
        assert first_digest == second_digest, "Identical source produced different SPK bytes"
        print(f"PASS: clean generic web checkout builds DSM SPK without historical sources ({len(names)} payload entries)")
        print("PASS: deterministic SPK SHA-256 across two builds:", first_digest)

        # Refuse to package unreviewed runtime files or secrets.
        forbidden = clean / "src" / "private.env"
        forbidden.write_text("SECRET=must-not-package\n", encoding="utf-8")
        failed = subprocess.run([sys.executable, str(builder), "--version", VERSION],
                                cwd=clean, capture_output=True, text=True)
        assert failed.returncode != 0 and "possible secret" in failed.stderr
        forbidden.unlink()
        print("PASS: unexpected source/secret files are rejected")

        another = "0.4.4-0002"
        run(str(builder), "--version", another, "--output-dir", str(clean / "out"), cwd=clean)
        override = clean / f"out/NZB-Manager-DSM7-{another}-noauth-test.spk"
        assert override.is_file()
        run(str(builder), "--verify", str(override), "--version", another, cwd=clean)
        print("PASS: custom DSM version/output directory, without modifying INFO or generic application")


if __name__ == "__main__":
    main()
