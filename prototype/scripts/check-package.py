#!/usr/bin/env python3
import hashlib
import json
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent
forbidden = {".git", ".jarvis", "vendor", "node_modules", "storage", "prensa"}
paths = []
for path in ROOT.rglob("*"):
    if not path.is_file():
        continue
    rel = path.relative_to(ROOT)
    if ".git" in rel.parts:
        continue
    if any(part in forbidden for part in rel.parts):
        raise SystemExit(f"forbidden path in package: {rel}")
    if rel.as_posix() in {"reproduction/package.env", "reproduction/.local-project"}:
        raise SystemExit(f"generated runtime file in package: {rel}")
    if rel.name == "MANIFEST.sha256":
        continue
    if rel.name in {".env", "credentials.json"}:
        raise SystemExit(f"credential-like file in package: {rel}")
    paths.append(rel)
manifest = ROOT / "MANIFEST.sha256"
lines = []
for rel in sorted(paths):
    digest = hashlib.sha256((ROOT / rel).read_bytes()).hexdigest()
    lines.append(f"{digest}  {rel.as_posix()}")
expected = "\n".join(lines) + "\n"
if "--write" in sys.argv:
    manifest.write_text(expected)
    print(json.dumps({"files": len(lines), "manifest": str(manifest), "mode": "write"}))
else:
    if not manifest.is_file() or manifest.read_text() != expected:
        raise SystemExit("manifest mismatch: run scripts/check-package.py --write after intentional package changes")
    print(json.dumps({"files": len(lines), "manifest": str(manifest), "mode": "verify"}))
