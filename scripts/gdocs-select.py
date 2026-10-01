"""Pick the Google Docs and Sheets that hold facility information and write the
Apps Script that exports them (docs/PLAN.md 3.9, step 1).

Reads tmp/gdocs/inventory.json (every Google Doc/Sheet on the G: and I: Drive
for desktop mounts, listed from DriveFS's metadata_sqlite_db) and writes:
  tmp/gdocs/selected.json   the chosen files, with the reason each was chosen
  tmp/gdocs/export.gs       paste into script.google.com as ttiresearch.dani

The export lands in the Drive folder "KOP Doc Export" (G:\\My Drive\\KOP Doc
Export once Drive for desktop syncs it): one <id>.html per Doc (HTML keeps the
address behind every linked word), one <id>__<gid>.csv per Sheet tab, and
manifest.json. Both outputs carry Drive file ids, so they stay in tmp/, never
in the repo.

Left out by the owner's decision (2026-09-30): podcast transcripts, survivor
interviews, the NATSAP directories (already backfilled from the media library).

Usage: python scripts/gdocs-select.py [--list]
"""
import json
import os
import sys

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
TMP = os.path.join(ROOT, "tmp", "gdocs")
KOP = "My Drive/Kids Over Profits/"

# Whole folders under Kids Over Profits/ that are about programs.
FOLDERS = [
    "Active Programs/", "Closed Programs/", "Altior/", "Aurora Center for Healing/",
    "Embark/", "FHW/", "Focus Locations/", "Investigations/", "Investigatory Spotlight/",
    "Lawsuits/", "Legislation/", "Sequel/", "UHS/", "WWASP/", "Educational Consultants/",
    "Coercive Methods/GGI PPC/", "Poison/",
]
# Single files, by path with the .gdoc/.gsheet extension dropped.
FILES = {
    KOP + "In progress/CLOSED RECENTLY", KOP + "In progress/Death List",
    KOP + "In progress/TTI Program Index", KOP + "Staff & Owners", KOP + "TTI Staff",
    KOP + "Spring Ridge Academy Profile", KOP + "Three Springs Company Profile",
    KOP + "KidLink Treatment Services", KOP + "Red Flag Providers", KOP + "Utah CCL Links",
    KOP + "utah_citations", KOP + "Sources",
    "My Drive/Investigative Spotlight  Montana Academy", "My Drive/Anasazi Notes",
    "My Drive/California STRTP", "My Drive/Facility profile tracking",
    "My Drive/Facility_Address_Table", "My Drive/Programs with websites",
    "My Drive/Transporters", "My Drive/TTI Staff", "My Drive/UHS", "My Drive/Utah",
    "My Drive/Ohio", "My Drive/Educational Consultants", "My Drive/RCR HEAL",
    "My Drive/WR Youth Health Associates Report", "My Drive/Troubled Teens Wiki  Missing Entries",
    "My Drive/Troubled Teen Wiki Updates", "My Drive/people_jobs2", "My Drive/Seq Timeline Draft",
    "My Drive/FileBird Cloud - kidsoverprofits.org/NWBHS/HEAL INFO NWBHS",
    # Shared with ttiresearch.dani from other Drives (no My Drive path).
    "AZ Facilities", "AZ Residential Behavioral Health Facilities for Children",
    "Andrew Erkis Public Buisness Affiliations", "Aurora Center for Healing ",
    "Crowdsourced - Inappropriate Staff", "Human Services - Notices of Agency Actions",
    "Robert Lichfield Business search ", "Smokey Point/Buisness Affiliations  CEO of HealthVest (Richard A. Kresch)",
    "Smokey Point/Smokey Point New Article Archive Link", "Smokey Point/Summary of Info  Smokey Point",
    "Staff & Owners", "Sundown Ranch Inc/Cheat Sheet", "TTI Database - Master (Chelsea Maldonado)",
    "Troubled Teen Industry Program Analysis - 2014", "Vivant Provider Report - 2024(CM)",
    "Copy of Hyde_Names_Detailed_Table SL editscsv",
}
# Inside the chosen folders, still left out.
SKIP = ["/Survivors/", "Transcript", "Interview"]


def stem(path):
    for ext in (".gdoc", ".gsheet"):
        if path.endswith(ext):
            return path[: -len(ext)]
    return path


def choose(item):
    path = stem(item["path"])
    if any(s in path for s in SKIP):
        return None
    if path in FILES:
        return "named file"
    if path.startswith(KOP):
        rest = path[len(KOP):]
        for f in FOLDERS:
            if rest.startswith(f):
                return "folder " + f.rstrip("/")
    return None


def main():
    with open(os.path.join(TMP, "inventory.json"), encoding="utf-8") as fh:
        inventory = json.load(fh)
    chosen = []
    for item in inventory:
        why = choose(item)
        if why:
            chosen.append(dict(item, why=why, path=stem(item["path"])))
    found = {c["path"] for c in chosen}
    missing = sorted(f for f in FILES if f not in found)
    chosen.sort(key=lambda c: c["path"])

    with open(os.path.join(TMP, "selected.json"), "w", encoding="utf-8") as fh:
        json.dump(chosen, fh, indent=1, ensure_ascii=False)
    files = [{"id": c["id"], "kind": c["kind"], "path": c["path"]} for c in chosen]
    with open(os.path.join(os.path.dirname(os.path.abspath(__file__)), "gdocs-export.gs.txt"), encoding="utf-8") as fh:
        template = fh.read()
    with open(os.path.join(TMP, "export.gs"), "w", encoding="utf-8") as fh:
        fh.write(template.replace("/*FILES*/[]", json.dumps(files, indent=1, ensure_ascii=False)))

    docs = sum(1 for c in chosen if c["kind"] == "doc")
    print(f"{docs} docs, {len(chosen) - docs} sheets -> tmp/gdocs/export.gs")
    on_i = [c["path"] for c in chosen if c["drive"] != "G"]
    if on_i:
        print("On I: (kidsoverprofitsdani), run the script there too or share them to ttiresearch.dani:")
        for p in on_i:
            print("  " + p)
    if missing:
        print("Named but not found:")
        for p in missing:
            print("  " + p)
    if "--list" in sys.argv:
        for c in chosen:
            print(f"{c['kind'][0]} {c['path']}")


if __name__ == "__main__":
    main()
