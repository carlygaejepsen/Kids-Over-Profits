#!/usr/bin/env python3
"""
Sync the production MySQL database (kidsoverprofits.org, NixiHost) into a local SQLite file.

Read-only against production: it runs `mysqldump` over SSH, saves the raw dump, then rebuilds a
fresh SQLite database from it. The previous SQLite file is replaced only after a successful load.

Usage (from the repo root):

    python scripts/sync-prod-sqlite.py --list              # table names, row counts, MB on prod
    python scripts/sync-prod-sqlite.py                     # full sync -> tmp/prod.sqlite
    python scripts/sync-prod-sqlite.py --tables "facilities_master,wpdl_kop_*"
    python scripts/sync-prod-sqlite.py --from-dump tmp/prod-dump.sql   # rebuild without SSH

Requires: ssh on PATH with the kop_nixihost key (see --host/--port/--key), Python 3.8+.
Type mapping: MySQL integer types -> INTEGER, decimal/float/double -> REAL, binary/blob -> BLOB,
everything else (varchar, text, json, datetime, enum ...) -> TEXT. Primary keys are kept; every
KEY/UNIQUE KEY becomes a plain (non-unique) index so case-insensitive MySQL data never fails to load.
"""

import argparse
import fnmatch
import os
import re
import sqlite3
import subprocess
import sys
import time

DEFAULT_HOST = "kidsover@dfw-s07.nixihost.com"
DEFAULT_PORT = "1157"
DEFAULT_KEY = "~/.ssh/kop_nixihost"
DEFAULT_OUT = os.path.join("tmp", "prod.sqlite")
DEFAULT_DUMP = os.path.join("tmp", "prod-dump.sql")
DEFAULT_EXCLUDE = "wpdl_actionscheduler_*,wpdl_litespeed_*,wpdl_wf*,wpdl_dlm_*,wpdl_imunify_*,wpdl_asp_*"

# Remote prelude: read DB credentials from wp-config.php without printing them.
REMOTE_PRELUDE = r'''
set -euo pipefail
cd ~/public_html
getc() { sed -n "s/^[[:space:]]*define([[:space:]]*['\"]$1['\"][[:space:]]*,[[:space:]]*['\"]\([^'\"]*\)['\"].*/\1/p" wp-config.php | head -n1; }
DBN="$(getc DB_NAME)"
DBU="$(getc DB_USER)"
export MYSQL_PWD="$(getc DB_PASSWORD)"
if [ -z "$DBN" ] || [ -z "$DBU" ] || [ -z "$MYSQL_PWD" ]; then echo "could not read DB credentials from wp-config.php" >&2; exit 2; fi
'''

REMOTE_LIST = REMOTE_PRELUDE + r'''
mysql -u"$DBU" --default-character-set=utf8mb4 --batch --skip-column-names -e "SELECT table_name, IFNULL(table_rows,0), ROUND((data_length+index_length)/1048576,1) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_type='BASE TABLE' ORDER BY table_name" "$DBN"
'''

REMOTE_DUMP = REMOTE_PRELUDE + r'''
mysqldump -u"$DBU" --single-transaction --skip-lock-tables --no-tablespaces --set-gtid-purged=OFF \
  --default-character-set=utf8mb4 --hex-blob --compact --skip-triggers "$DBN" %TABLES%
'''

TABLE_NAME_RE = re.compile(r"^[A-Za-z0-9_]+$")


def ssh_base(args):
    return ["ssh", "-o", "BatchMode=yes", "-o", "ConnectTimeout=20",
            "-i", os.path.expanduser(args.key), "-p", args.port, args.host, "bash", "-s"]


def remote_tables(args):
    """Return [(name, rows, mb)] for every base table on prod."""
    proc = subprocess.run(ssh_base(args), input=REMOTE_LIST.encode(), capture_output=True)
    if proc.returncode != 0:
        sys.stderr.write(proc.stderr.decode(errors="replace"))
        sys.exit("remote table listing failed (exit %d)" % proc.returncode)
    rows = []
    for line in proc.stdout.decode("utf-8", errors="replace").splitlines():
        parts = line.rstrip("\n").split("\t")
        if len(parts) != 3 or parts[0] == "table_name":
            continue
        rows.append((parts[0], int(float(parts[1])), float(parts[2])))
    return rows


def select_tables(all_names, include, exclude):
    inc = [p.strip() for p in include.split(",") if p.strip()] if include else []
    exc = [p.strip() for p in exclude.split(",") if p.strip()] if exclude else []
    chosen = []
    for name in all_names:
        if inc and not any(fnmatch.fnmatchcase(name, p) for p in inc):
            continue
        if any(fnmatch.fnmatchcase(name, p) for p in exc):
            continue
        chosen.append(name)
    return chosen


def dump_remote(args, tables, dump_path):
    for t in tables:
        if not TABLE_NAME_RE.match(t):
            sys.exit("refusing odd table name: %r" % t)
    script = REMOTE_DUMP.replace("%TABLES%", " ".join(tables))
    os.makedirs(os.path.dirname(dump_path) or ".", exist_ok=True)
    tmp_path = dump_path + ".part"
    started = time.time()
    with open(tmp_path, "wb") as fh:
        proc = subprocess.Popen(ssh_base(args), stdin=subprocess.PIPE, stdout=fh, stderr=subprocess.PIPE)
        _, err = proc.communicate(script.encode())
    if proc.returncode != 0:
        sys.stderr.write(err.decode(errors="replace"))
        try:
            os.remove(tmp_path)
        except OSError:
            pass
        sys.exit("mysqldump over ssh failed (exit %d)" % proc.returncode)
    os.replace(tmp_path, dump_path)
    size = os.path.getsize(dump_path)
    print("dump saved: %s (%.1f MB, %.0fs)" % (dump_path, size / 1048576, time.time() - started))


# ---------------------------------------------------------------------------
# Dump parsing
# ---------------------------------------------------------------------------

COL_RE = re.compile(r"^\s*`([^`]+)`\s+([A-Za-z]+)")
PK_RE = re.compile(r"^\s*PRIMARY KEY\s*\((.*)\)\s*,?\s*$")
KEY_RE = re.compile(r"^\s*(UNIQUE\s+|FULLTEXT\s+|SPATIAL\s+)?KEY\s+`([^`]+)`\s*\((.*)\)\s*,?\s*$")
CREATE_RE = re.compile(r"^CREATE TABLE `([^`]+)` \(")
INSERT_RE = re.compile(r"^INSERT INTO `([^`]+)`(?: \(([^)]*)\))? VALUES ")
GENERATED_RE = re.compile(r"GENERATED ALWAYS AS \((.*)\) (?:STORED|VIRTUAL)", re.S)

INT_TYPES = {"tinyint", "smallint", "mediumint", "int", "integer", "bigint", "bit", "year", "boolean", "bool"}
REAL_TYPES = {"decimal", "numeric", "float", "double", "real"}
BLOB_TYPES = {"binary", "varbinary", "blob", "tinyblob", "mediumblob", "longblob"}

# One value plus the separator that follows it. Strings use an unrolled loop so huge JSON blobs
# match in linear time.
VALUE_RE = re.compile(r"""
    \s*(?:
        (?P<null>NULL)
      | (?P<str>'[^'\\]*(?:\\.[^'\\]*)*')
      | (?P<bin>_binary\s+'[^'\\]*(?:\\.[^'\\]*)*')
      | (?P<hex>0[xX][0-9A-Fa-f]*)
      | (?P<num>-?\d+(?:\.\d+)?(?:[eE][+-]?\d+)?)
    )\s*(?P<sep>[,)])
""", re.X | re.S)

UNESCAPE_RE = re.compile(r"\\(.)", re.S)
UNESCAPE_MAP = {"0": "\0", "b": "\b", "n": "\n", "r": "\r", "t": "\t", "Z": "\x1a",
                "\\": "\\", "'": "'", '"': '"'}


def _unescape(m):
    c = m.group(1)
    return UNESCAPE_MAP.get(c, "\\" + c)


def unescape(s):
    if "\\" not in s:
        return s
    return UNESCAPE_RE.sub(_unescape, s)


def index_cols(spec):
    """'`a`,`b`(191)' -> ['a', 'b']"""
    return [c.strip().strip("`") for c in re.sub(r"\(\d+\)", "", spec).split(",") if c.strip()]


def q(ident):
    return '"' + ident.replace('"', '""') + '"'


class Table:
    def __init__(self, name):
        self.name = name
        self.columns = []      # (name, sqlite_type)
        self.pk = []
        self.indexes = []      # (index_name, [cols])
        self.generated = []    # (col, mysql_expr)
        self.rows = 0

    def finish(self, lines):
        for line in lines:
            m = COL_RE.match(line)
            if m and not line.lstrip().upper().startswith(("PRIMARY", "KEY", "UNIQUE", "FULLTEXT", "SPATIAL", "CONSTRAINT")):
                t = m.group(2).lower()
                if t in INT_TYPES:
                    st = "INTEGER"
                elif t in REAL_TYPES:
                    st = "REAL"
                elif t in BLOB_TYPES:
                    st = "BLOB"
                else:
                    st = "TEXT"
                self.columns.append((m.group(1), st))
                g = GENERATED_RE.search(line)
                if g:
                    self.generated.append((m.group(1), g.group(1)))
                continue
            m = PK_RE.match(line)
            if m:
                self.pk = index_cols(m.group(1))
                continue
            m = KEY_RE.match(line)
            if m:
                kind = (m.group(1) or "").strip().upper()
                if kind in ("FULLTEXT", "SPATIAL"):
                    continue
                self.indexes.append((m.group(2), index_cols(m.group(3))))

    def create_sql(self):
        types = dict(self.columns)
        parts = []
        rowid_pk = len(self.pk) == 1 and types.get(self.pk[0]) == "INTEGER"
        for name, st in self.columns:
            if rowid_pk and name == self.pk[0]:
                parts.append("%s INTEGER PRIMARY KEY" % q(name))
            else:
                parts.append("%s %s" % (q(name), st))
        if self.pk and not rowid_pk:
            parts.append("PRIMARY KEY (%s)" % ", ".join(q(c) for c in self.pk))
        return "CREATE TABLE %s (\n  %s\n)" % (q(self.name), ",\n  ".join(parts))

    def index_sql(self):
        known = {c for c, _ in self.columns}
        out = []
        for idx_name, cols in self.indexes:
            if not cols or any(c not in known for c in cols):
                continue
            out.append("CREATE INDEX IF NOT EXISTS %s ON %s (%s)" % (
                q("%s__%s" % (self.name, idx_name)), q(self.name), ", ".join(q(c) for c in cols)))
        return out


def _translate_left(expr):
    """MySQL left(x, n) -> SQLite substr(x, 1, n), handling nested parens."""
    out = expr
    while True:
        i = re.search(r"\bleft\(", out, re.I)
        if not i:
            return out
        start = i.end()
        depth, j, last_comma = 1, start, None
        while j < len(out) and depth:
            c = out[j]
            if c == "(":
                depth += 1
            elif c == ")":
                depth -= 1
            elif c == "," and depth == 1:
                last_comma = j
            j += 1
        if depth or last_comma is None:
            return out
        inner = out[start:last_comma]
        n = out[last_comma + 1:j - 1]
        out = out[:i.start()] + "substr(" + inner + ", 1," + n + ")" + out[j:]


def translate_generated(expr):
    """Best-effort MySQL generated-column expression -> SQLite expression."""
    e = expr
    e = re.sub(r"_utf8mb4'", "'", e)
    e = re.sub(r"`([^`]+)`", lambda m: q(m.group(1)), e)

    def jv(m):
        col, path, ret = m.group(1).strip(), m.group(2), (m.group(3) or "").lower()
        base = "json_extract(%s, %s)" % (col, path)
        if ret.startswith(("signed", "unsigned")):
            return "CAST(%s AS INTEGER)" % base
        if ret.startswith(("decimal", "double", "float")):
            return "CAST(%s AS REAL)" % base
        return base
    e = re.sub(r"json_value\(\s*([^,]+),\s*('[^']*')\s*(?:returning\s+([a-z]+(?:\(\d+(?:,\d+)?\))?))?\s*\)", jv, e, flags=re.I)
    e = _translate_left(e)
    return e


def backfill_generated(db, table):
    for col, expr in table.generated:
        sql = "UPDATE %s SET %s = %s" % (q(table.name), q(col), translate_generated(expr))
        try:
            db.execute(sql)
        except sqlite3.Error as exc:
            sys.stderr.write("could not backfill generated column %s.%s: %s\n  %s\n" % (table.name, col, exc, sql))


def parse_values(line, start, col_types, table_name):
    """Yield tuples from an extended INSERT line, starting at the first '('."""
    pos = start
    n = len(line)
    ncols = len(col_types)
    while pos < n:
        if line[pos] != "(":
            raise ValueError("expected '(' at %d in INSERT for %s" % (pos, table_name))
        pos += 1
        row = []
        while True:
            m = VALUE_RE.match(line, pos)
            if not m:
                raise ValueError("bad value at %d in INSERT for %s: %r" % (pos, table_name, line[pos:pos + 80]))
            if m.group("null") is not None:
                row.append(None)
            elif m.group("str") is not None:
                row.append(unescape(m.group("str")[1:-1]))
            elif m.group("bin") is not None:
                s = m.group("bin")
                row.append(unescape(s[s.index("'") + 1:-1]).encode("utf-8", "surrogateescape"))
            elif m.group("hex") is not None:
                h = m.group("hex")[2:]
                if len(h) % 2:
                    h = "0" + h
                row.append(bytes.fromhex(h))
            else:
                num = m.group("num")
                if "." in num or "e" in num or "E" in num:
                    row.append(float(num))
                else:
                    row.append(int(num))
            pos = m.end()
            if m.group("sep") == ")":
                break
        if len(row) != ncols:
            raise ValueError("row has %d values, table %s has %d columns" % (len(row), table_name, ncols))
        yield tuple(row)
        # after ')' : ',' then next '(' , or ';' end
        while pos < n and line[pos] in " \t\r\n":
            pos += 1
        if pos < n and line[pos] == ",":
            pos += 1
            while pos < n and line[pos] in " \t\r\n":
                pos += 1
            continue
        if pos < n and line[pos] == ";":
            return
        if pos >= n:
            return
        raise ValueError("unexpected %r after tuple in INSERT for %s" % (line[pos:pos + 20], table_name))


def load_dump(dump_path, out_path, source_label):
    tmp_out = out_path + ".building"
    if os.path.exists(tmp_out):
        os.remove(tmp_out)
    os.makedirs(os.path.dirname(out_path) or ".", exist_ok=True)
    db = sqlite3.connect(tmp_out)
    db.execute("PRAGMA journal_mode=OFF")
    db.execute("PRAGMA synchronous=OFF")
    db.execute("PRAGMA temp_store=MEMORY")

    tables = {}
    order = []
    current = None
    create_lines = []
    batch = []
    batch_table = None
    batch_cols = None      # explicit column list from the INSERT, or None for all columns
    started = time.time()

    def flush():
        nonlocal batch, batch_table
        if batch and batch_table:
            t = tables[batch_table]
            cols = batch_cols or [c for c, _ in t.columns]
            placeholders = ",".join("?" * len(cols))
            db.executemany("INSERT INTO %s (%s) VALUES (%s)" % (
                q(t.name), ", ".join(q(c) for c in cols), placeholders), batch)
            t.rows += len(batch)
        batch = []

    with open(dump_path, "r", encoding="utf-8", errors="replace", newline="") as fh:
        for line in fh:
            if current is not None:
                if line.startswith(")"):
                    current.finish(create_lines)
                    db.execute(current.create_sql())
                    tables[current.name] = current
                    order.append(current.name)
                    current = None
                    create_lines = []
                else:
                    create_lines.append(line)
                continue
            m = CREATE_RE.match(line)
            if m:
                current = Table(m.group(1))
                create_lines = []
                continue
            m = INSERT_RE.match(line)
            if m:
                name = m.group(1)
                if name not in tables:
                    sys.stderr.write("skipping INSERT for unknown table %s\n" % name)
                    continue
                cols = index_cols(m.group(2)) if m.group(2) else None
                if batch_table != name or cols != batch_cols:
                    flush()
                    batch_table = name
                    batch_cols = cols
                t = tables[name]
                col_types = [st for _, st in t.columns] if cols is None else cols
                for row in parse_values(line, m.end(), col_types, name):
                    batch.append(row)
                    if len(batch) >= 2000:
                        flush()
                        batch_table = name
                continue
    flush()

    for name in order:
        backfill_generated(db, tables[name])
        for sql in tables[name].index_sql():
            db.execute(sql)

    db.execute("CREATE TABLE IF NOT EXISTS _kop_sync (key TEXT PRIMARY KEY, value TEXT)")
    meta = {
        "synced_at": time.strftime("%Y-%m-%d %H:%M:%S"),
        "source": source_label,
        "dump_path": os.path.abspath(dump_path),
        "tables": ",".join(order),
    }
    db.executemany("INSERT OR REPLACE INTO _kop_sync VALUES (?, ?)", meta.items())
    db.commit()
    db.close()

    os.replace(tmp_out, out_path)

    width = max([len(n) for n in order] + [5])
    print("%-*s %10s" % (width, "table", "rows"))
    for name in order:
        print("%-*s %10d" % (width, name, tables[name].rows))
    print("sqlite written: %s (%.1f MB, %d tables, %.0fs)" % (
        out_path, os.path.getsize(out_path) / 1048576, len(order), time.time() - started))


def main():
    ap = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    ap.add_argument("--list", action="store_true", help="list prod tables with row counts and size, then exit")
    ap.add_argument("--tables", default="", help="comma-separated names or globs to include (default: all)")
    ap.add_argument("--exclude", default=DEFAULT_EXCLUDE, help="comma-separated globs to skip (default: %(default)s)")
    ap.add_argument("--out", default=DEFAULT_OUT, help="SQLite file to write (default: %(default)s)")
    ap.add_argument("--dump", default=DEFAULT_DUMP, help="where to keep the raw mysqldump (default: %(default)s)")
    ap.add_argument("--from-dump", default="", help="convert an existing dump file instead of connecting to prod")
    ap.add_argument("--host", default=DEFAULT_HOST)
    ap.add_argument("--port", default=DEFAULT_PORT)
    ap.add_argument("--key", default=DEFAULT_KEY)
    args = ap.parse_args()

    if args.from_dump:
        load_dump(args.from_dump, args.out, "dump file %s" % args.from_dump)
        return

    listing = remote_tables(args)
    if args.list:
        width = max([len(r[0]) for r in listing] + [5])
        print("%-*s %12s %8s" % (width, "table", "rows(est)", "MB"))
        for name, rows, mb in listing:
            print("%-*s %12d %8.1f" % (width, name, rows, mb))
        print("%d tables, %.1f MB total" % (len(listing), sum(r[2] for r in listing)))
        return

    chosen = select_tables([r[0] for r in listing], args.tables, args.exclude)
    if not chosen:
        sys.exit("no tables selected")
    skipped = [r[0] for r in listing if r[0] not in chosen]
    print("dumping %d tables from %s (skipping %d: %s)" % (
        len(chosen), args.host, len(skipped), ", ".join(skipped) if skipped else "none"))
    dump_remote(args, chosen, args.dump)
    load_dump(args.dump, args.out, "mysqldump %s" % args.host)


if __name__ == "__main__":
    main()
