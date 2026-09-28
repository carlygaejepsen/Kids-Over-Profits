"""Load the prod dump (tmp/prod-dump.sql) into a local MySQL/MariaDB database.

Refresh the dump first with `python scripts/sync-prod-sqlite.py`. This drops
and recreates the local database every run, so it is always a clean copy of
prod. It never connects to prod and never writes anywhere but localhost.

The local server is XAMPP's MariaDB 10.4 (C:\\xampp\\mysql\\bin), which lacks
a few MySQL 8 spellings the prod dump uses; they are rewritten on the way in:
  utf8mb4_0900_ai_ci          -> utf8mb4_unicode_ci
  utf8mb3                     -> utf8
  json_value(... returning X) -> json_value(...)   (generated columns only)

Usage:
  python scripts/load-local-mysql.py [--db kop_local] [--dump tmp/prod-dump.sql]
"""
import argparse
import re
import shutil
import subprocess
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent
RETURNING = re.compile(r" returning (?:signed|unsigned|char\(\d+\)|date|datetime|decimal\([\d,]+\))\)", re.I)


def find_mysql():
    exe = shutil.which("mysql")
    if exe:
        return exe
    xampp = Path(r"C:\xampp\mysql\bin\mysql.exe")
    if xampp.exists():
        return str(xampp)
    sys.exit("mysql.exe not found: add C:\\xampp\\mysql\\bin to PATH")


def patch(line):
    if "0900_ai_ci" in line:
        line = line.replace("utf8mb4_0900_ai_ci", "utf8mb4_unicode_ci")
    if "utf8mb3" in line:
        line = line.replace("utf8mb3", "utf8")
    if "GENERATED ALWAYS" in line:
        line = RETURNING.sub(")", line)
    return line


def main():
    ap = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    ap.add_argument("--db", default="kop_local")
    ap.add_argument("--dump", default=str(ROOT / "tmp" / "prod-dump.sql"))
    ap.add_argument("--user", default="root")
    args = ap.parse_args()

    dump = Path(args.dump)
    if not dump.exists():
        sys.exit(f"{dump} missing: run python scripts/sync-prod-sqlite.py first")
    if not re.fullmatch(r"\w+", args.db):
        sys.exit("--db must be a plain name")

    mysql = find_mysql()
    base = [mysql, "-u", args.user, "--default-character-set=utf8mb4"]
    # XAMPP ships a 1 MB packet limit; some prod rows (report text) are far bigger.
    subprocess.run(base + ["-e", "SET GLOBAL max_allowed_packet = 1073741824"], check=True)
    subprocess.run(base + ["-e", f"DROP DATABASE IF EXISTS `{args.db}`; "
                          f"CREATE DATABASE `{args.db}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"], check=True)

    print(f"Loading {dump.name} ({dump.stat().st_size // 1_000_000} MB) into {args.db} ...", flush=True)
    # WordPress tables default dates to 0000-00-00, which strict mode refuses.
    proc = subprocess.Popen(base + ["--max-allowed-packet=1G",
                                    "--init-command=SET SESSION sql_mode='NO_ENGINE_SUBSTITUTION'",
                                    args.db], stdin=subprocess.PIPE)
    with dump.open("rb") as f:
        for raw in f:
            line = raw.decode("utf-8", "surrogateescape")
            proc.stdin.write(patch(line).encode("utf-8", "surrogateescape"))
    proc.stdin.close()
    if proc.wait() != 0:
        sys.exit("mysql reported an error (see above)")

    out = subprocess.run(base + ["-N", "-e",
                         f"SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='{args.db}'"],
                         capture_output=True, text=True, check=True)
    print(f"Done: {out.stdout.strip()} tables in {args.db}")


if __name__ == "__main__":
    main()
