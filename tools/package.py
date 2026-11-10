"""Build a reproducible WordPress plugin archive and its SHA-256 checksum."""

from hashlib import sha256
import json
from pathlib import Path
import re
from uuid import NAMESPACE_URL, uuid5
from zipfile import ZIP_DEFLATED, ZipFile, ZipInfo


ROOT = Path(__file__).resolve().parents[1]
PLUGIN = ROOT / "wp-utility-suite"
ENTRY = PLUGIN / "wp-utility-suite.php"
VERSION = re.search(r"(?m)^ \* Version: (\d+\.\d+\.\d+)$", ENTRY.read_text(encoding="utf-8")).group(1)
ARCHIVE = ROOT / f"wp-utility-suite-{VERSION}.zip"
REQUIRED = [
    ENTRY,
    PLUGIN / "uninstall.php",
    PLUGIN / "readme.txt",
    PLUGIN / "LICENSE",
    PLUGIN / "vendor/action-scheduler/action-scheduler.php",
]

for file in REQUIRED:
    if not file.is_file():
        raise FileNotFoundError(file)

with ZipFile(ARCHIVE, "w") as output:
    for file in sorted(PLUGIN.rglob("*")):
        if not file.is_file():
            continue
        if file.is_symlink():
            raise ValueError(f"Symlink in release input: {file}")
        name = file.relative_to(ROOT).as_posix()
        entry = ZipInfo(name)
        entry.create_system = 3
        entry.external_attr = 0o100644 << 16
        output.writestr(entry, file.read_bytes(), ZIP_DEFLATED, compresslevel=9)

with ZipFile(ARCHIVE) as output:
    bad_file = output.testzip()
    if bad_file:
        raise ValueError(f"Failed ZIP integrity check: {bad_file}")

checksum = sha256(ARCHIVE.read_bytes()).hexdigest()
ARCHIVE.with_suffix(".zip.sha256").write_text(f"{checksum}  {ARCHIVE.name}\n", encoding="ascii", newline="\n")

scheduler = REQUIRED[-1]
vendor_entries = {path.name for path in scheduler.parent.parent.iterdir()}
if vendor_entries != {"action-scheduler"}:
    raise ValueError("Update the SBOM inventory for the bundled vendor entries")
scheduler_version = re.search(r"(?m)^ \* Version: (\d+\.\d+\.\d+)$", scheduler.read_text(encoding="utf-8")).group(1)
cron_dir = scheduler.parent / "lib" / "cron-expression"
cron_version = re.search(r"(?m)^Based on version (\d+\.\d+\.\d+):", (cron_dir / "README.md").read_text(encoding="utf-8")).group(1)
plugin_ref = f"pkg:github/previnus/PressUtils@v{VERSION}"
scheduler_ref = f"pkg:github/woocommerce/action-scheduler@{scheduler_version}"
cron_ref = f"action-scheduler:cron-expression:{cron_version}"
bom = {
    "$schema": "https://cyclonedx.org/schema/bom-1.6.schema.json",
    "bomFormat": "CycloneDX",
    "specVersion": "1.6",
    "serialNumber": uuid5(NAMESPACE_URL, f"https://github.com/previnus/PressUtils#{checksum}").urn,
    "version": 1,
    "metadata": {"component": {
        "type": "application", "bom-ref": plugin_ref, "name": "PressUtils", "version": VERSION,
        "purl": plugin_ref, "hashes": [{"alg": "SHA-256", "content": checksum}],
        "licenses": [{"license": {"id": "GPL-3.0-or-later"}}],
    }},
    "components": [
        {"type": "library", "bom-ref": scheduler_ref, "name": "Action Scheduler", "version": scheduler_version,
         "purl": scheduler_ref, "licenses": [{"license": {"id": "GPL-3.0-or-later"}}]},
        {"type": "library", "bom-ref": cron_ref, "name": "cron-expression (Action Scheduler fork)",
         "version": cron_version, "description": "Action Scheduler's bundled fork, based on the listed upstream version.",
         "licenses": [{"license": {"id": "MIT"}}],
         "externalReferences": [{"type": "vcs", "url": f"https://github.com/mtdowling/cron-expression/tree/v{cron_version}"}]},
    ],
    "dependencies": [
        {"ref": plugin_ref, "dependsOn": [scheduler_ref]},
        {"ref": scheduler_ref, "dependsOn": [cron_ref]},
        {"ref": cron_ref, "dependsOn": []},
    ],
}
ARCHIVE.with_suffix(".cdx.json").write_text(json.dumps(bom, indent=2) + "\n", encoding="utf-8", newline="\n")
print(f"{ARCHIVE.name}  {checksum}")
