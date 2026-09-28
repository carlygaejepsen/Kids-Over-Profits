"""Local DB: a small window for the practice copy of the prod database.

Double-click to open (or: pythonw scripts/local-db.pyw). It runs against the
XAMPP MariaDB on this computer only and never writes to prod:

  - start / stop the local database engine
  - "Get fresh copy from prod": scripts/sync-prod-sqlite.py (downloads a dump
    over SSH, read-only) then scripts/load-local-mysql.py (loads it locally)
  - "Reset my copy": reloads the last download, throwing away local edits
  - browse tables and run any SQL against the kop_local database
"""
import os
import queue
import socket
import subprocess
import sys
import threading
import time
import tkinter as tk
import xml.etree.ElementTree as ET
from datetime import datetime
from pathlib import Path
from tkinter import messagebox, ttk

ROOT = Path(__file__).resolve().parent.parent
BIN = Path(r"C:\xampp\mysql\bin")
MYSQL = BIN / "mysql.exe"
MYSQLD = BIN / "mysqld.exe"
MYSQLADMIN = BIN / "mysqladmin.exe"
DB = "kop_local"
DUMP = ROOT / "tmp" / "prod-dump.sql"
MAX_SHOWN = 1000
NIL = "{http://www.w3.org/2001/XMLSchema-instance}nil"
NO_WINDOW = getattr(subprocess, "CREATE_NO_WINDOW", 0)

# pythonw has no console; child scripts need the console python for their output.
PYTHON = Path(sys.executable)
if PYTHON.name.lower() == "pythonw.exe":
    PYTHON = PYTHON.with_name("python.exe")


def server_running():
    try:
        with socket.create_connection(("127.0.0.1", 3306), timeout=0.5):
            return True
    except OSError:
        return False


def mysql_cmd(*extra, db=DB):
    cmd = [str(MYSQL), "-u", "root", "--default-character-set=utf8mb4", *extra]
    return cmd + [db] if db else cmd


def run_sql(sql):
    """Run SQL in kop_local. Returns (list of (columns, rows), error text, rows changed)."""
    body = sql.strip().rstrip(";")
    # ROW_COUNT() reports what the last statement changed (-1 after a SELECT).
    script = body + "\n;\nSELECT ROW_COUNT() AS __changed;\n"
    p = subprocess.run(mysql_cmd("--xml"), input=script.encode("utf-8"),
                       capture_output=True, creationflags=NO_WINDOW)
    results, changed = [], None
    for chunk in p.stdout.decode("utf-8", "replace").split('<?xml version="1.0"?>'):
        if not chunk.strip():
            continue
        rs = ET.fromstring(chunk.strip())
        cols, rows = [], []
        for row in rs.findall("row"):
            fields = row.findall("field")
            if not cols:
                cols = [f.get("name") for f in fields]
            rows.append([None if f.get(NIL) == "true" else (f.text or "") for f in fields])
        if cols == ["__changed"]:
            changed = int(rows[0][0]) if rows else None
            continue
        results.append((cols, rows))
    return results, p.stderr.decode("utf-8", "replace").strip(), changed


class App(tk.Tk):
    def __init__(self):
        super().__init__()
        self.title("Local DB - practice copy of kidsoverprofits.org")
        self.geometry("1200x780")
        self.minsize(900, 600)
        self.busy = False
        self.log_q = queue.Queue()
        self.all_tables = []

        style = ttk.Style(self)
        if "vista" in style.theme_names():
            style.theme_use("vista")
        style.configure("Big.TButton", font=("Segoe UI", 10, "bold"), padding=(12, 6))

        self._build()
        self.refresh_status()
        self.after(100, self._drain_log)
        if not MYSQL.exists():
            messagebox.showerror("Local DB", f"Can't find {MYSQL}.\nIs XAMPP installed?")

    # ---------- layout ----------
    def _build(self):
        top = ttk.Frame(self, padding=(12, 10, 12, 4))
        top.pack(fill="x")
        self.light = tk.Canvas(top, width=18, height=18, highlightthickness=0)
        self.light.pack(side="left")
        self.dot = self.light.create_oval(2, 2, 16, 16, fill="grey", outline="")
        self.status_lbl = ttk.Label(top, text="Checking...", font=("Segoe UI", 10))
        self.status_lbl.pack(side="left", padx=(6, 10))
        self.power_btn = ttk.Button(top, text="Start", command=self.toggle_server)
        self.power_btn.pack(side="left")
        self.age_lbl = ttk.Label(top, text="", foreground="#555")
        self.age_lbl.pack(side="right")

        actions = ttk.Frame(self, padding=(12, 4))
        actions.pack(fill="x")
        self.fresh_btn = ttk.Button(actions, text="Get fresh copy from prod", style="Big.TButton",
                                    command=self.fresh_copy)
        self.fresh_btn.pack(side="left")
        self.reset_btn = ttk.Button(actions, text="Reset my copy (no download)", command=self.reset_copy)
        self.reset_btn.pack(side="left", padx=8)
        ttk.Label(actions, text="Everything here is a practice copy. Nothing you do in this window touches the real site.",
                  foreground="#2a6b33").pack(side="left", padx=10)

        panes = ttk.PanedWindow(self, orient="horizontal")
        panes.pack(fill="both", expand=True, padx=12, pady=6)

        left = ttk.Frame(panes)
        ttk.Label(left, text="Tables (click to peek)").pack(anchor="w")
        self.filter_var = tk.StringVar()
        self.filter_var.trace_add("write", lambda *_: self._fill_tables())
        ttk.Entry(left, textvariable=self.filter_var).pack(fill="x", pady=(2, 4))
        self.tables = tk.Listbox(left, width=34, activestyle="none", font=("Consolas", 9))
        self.tables.pack(fill="both", expand=True)
        self.tables.bind("<<ListboxSelect>>", self.peek_table)
        panes.add(left, weight=0)

        right = ttk.PanedWindow(panes, orient="vertical")
        qframe = ttk.Frame(right)
        bar = ttk.Frame(qframe)
        bar.pack(fill="x")
        ttk.Label(bar, text="SQL  (Ctrl+Enter to run)").pack(side="left")
        self.run_btn = ttk.Button(bar, text="Run", command=self.run_query)
        self.run_btn.pack(side="right")
        self.query = tk.Text(qframe, height=7, font=("Consolas", 10), undo=True, wrap="none")
        self.query.pack(fill="both", expand=True, pady=(2, 0))
        self.query.bind("<Control-Return>", lambda e: (self.run_query(), "break")[1])
        right.add(qframe, weight=1)

        rframe = ttk.Frame(right)
        self.result_lbl = ttk.Label(rframe, text="Results  (double-click a row to see it in full)")
        self.result_lbl.pack(anchor="w")
        tree_box = ttk.Frame(rframe)
        tree_box.pack(fill="both", expand=True)
        self.tree = ttk.Treeview(tree_box, show="headings")
        ys = ttk.Scrollbar(tree_box, orient="vertical", command=self.tree.yview)
        xs = ttk.Scrollbar(tree_box, orient="horizontal", command=self.tree.xview)
        self.tree.configure(yscrollcommand=ys.set, xscrollcommand=xs.set)
        self.tree.grid(row=0, column=0, sticky="nsew")
        ys.grid(row=0, column=1, sticky="ns")
        xs.grid(row=1, column=0, sticky="ew")
        tree_box.rowconfigure(0, weight=1)
        tree_box.columnconfigure(0, weight=1)
        self.tree.bind("<Double-1>", self.show_row)
        right.add(rframe, weight=3)

        lframe = ttk.Frame(right)
        ttk.Label(lframe, text="Messages").pack(anchor="w")
        self.log = tk.Text(lframe, height=6, font=("Consolas", 9), state="disabled",
                           background="#f6f6f6", wrap="word")
        self.log.pack(fill="both", expand=True)
        right.add(lframe, weight=1)
        panes.add(right, weight=1)

        self.full_rows = {}

    # ---------- helpers ----------
    def say(self, text):
        self.log.configure(state="normal")
        self.log.insert("end", text.rstrip("\n") + "\n")
        self.log.see("end")
        self.log.configure(state="disabled")

    def _drain_log(self):
        try:
            while True:
                item = self.log_q.get_nowait()
                if callable(item):
                    item()
                else:
                    self.say(item)
        except queue.Empty:
            pass
        self.after(100, self._drain_log)

    def set_busy(self, busy):
        self.busy = busy
        state = "disabled" if busy else "normal"
        for b in (self.fresh_btn, self.reset_btn, self.power_btn, self.run_btn):
            b.configure(state=state)
        self.configure(cursor="watch" if busy else "")

    def refresh_status(self):
        up = server_running()
        self.light.itemconfigure(self.dot, fill="#2e9e44" if up else "#c0392b")
        self.status_lbl.configure(text="Database engine is ON" if up else "Database engine is OFF")
        self.power_btn.configure(text="Stop" if up else "Start")
        if DUMP.exists():
            t = datetime.fromtimestamp(DUMP.stat().st_mtime)
            days = (datetime.now() - t).days
            ago = "today" if days == 0 else "yesterday" if days == 1 else f"{days} days ago"
            self.age_lbl.configure(text=f"Last download from prod: {t:%b %d, %I:%M %p} ({ago})")
        else:
            self.age_lbl.configure(text="No download from prod yet")
        if up:
            self.load_tables()
        else:
            self.all_tables = []
            self._fill_tables()

    def load_tables(self):
        results, err, _ = run_sql(
            "SELECT table_name AS t, table_rows AS n FROM information_schema.tables "
            f"WHERE table_schema = '{DB}' ORDER BY table_name")
        self.all_tables = [(r[0], r[1]) for cols, rows in results for r in rows]
        self._fill_tables()
        if "Unknown database" in err:
            self.say(f"There's no {DB} database yet. Click \"Get fresh copy from prod\".")

    def _fill_tables(self):
        needle = self.filter_var.get().lower()
        self.tables.delete(0, "end")
        self.shown_tables = [t for t, _ in self.all_tables if needle in t.lower()]
        for t, n in self.all_tables:
            if needle in t.lower():
                self.tables.insert("end", t)

    # ---------- engine ----------
    def toggle_server(self):
        if server_running():
            subprocess.run([str(MYSQLADMIN), "-u", "root", "shutdown"], creationflags=NO_WINDOW)
            self.say("Database engine stopped.")
        else:
            subprocess.Popen([str(MYSQLD), f"--defaults-file={BIN / 'my.ini'}", "--standalone"],
                             creationflags=NO_WINDOW | subprocess.DETACHED_PROCESS,
                             stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
            for _ in range(30):
                if server_running():
                    break
                time.sleep(0.3)
            self.say("Database engine started." if server_running()
                     else "The engine didn't start. Try Start in the XAMPP Control Panel to see why.")
        self.refresh_status()

    def ensure_server(self):
        if not server_running():
            self.toggle_server()
        return server_running()

    # ---------- copies ----------
    def fresh_copy(self):
        if not messagebox.askokcancel("Get fresh copy",
                "Download prod's data and rebuild your practice copy?\n\n"
                "Anything you changed in your copy will be thrown away. "
                "This takes a few minutes. The real site is only read, never changed."):
            return
        self._run_steps([
            ("Downloading from prod (read-only)...", [str(PYTHON), "-u", "scripts/sync-prod-sqlite.py"]),
            ("Loading it into your copy...", [str(PYTHON), "-u", "scripts/load-local-mysql.py"]),
        ])

    def reset_copy(self):
        if not DUMP.exists():
            messagebox.showinfo("Reset", "There's no download yet. Use \"Get fresh copy from prod\" first.")
            return
        if not messagebox.askokcancel("Reset my copy",
                "Put your practice copy back the way it was at the last download?\n\n"
                "Anything you changed in your copy will be thrown away."):
            return
        self._run_steps([("Reloading your copy...", [str(PYTHON), "-u", "scripts/load-local-mysql.py"])])

    def _run_steps(self, steps):
        if not self.ensure_server():
            return
        self.set_busy(True)
        env = dict(os.environ, PYTHONIOENCODING="utf-8")

        def work():
            started = time.time()
            ok = True
            for label, cmd in steps:
                self.log_q.put(label)
                p = subprocess.Popen(cmd, cwd=ROOT, env=env, stdout=subprocess.PIPE, stderr=subprocess.STDOUT,
                                     creationflags=NO_WINDOW, text=True, encoding="utf-8", errors="replace")
                for line in p.stdout:
                    if line.strip() and not line.startswith("  "):
                        self.log_q.put("   " + line.strip())
                if p.wait() != 0:
                    ok = False
                    self.log_q.put("Something went wrong (see the lines above). Your old copy may be half-loaded; "
                                   "try again, or ask Claude with the message above.")
                    break
            mins = (time.time() - started) / 60
            if ok:
                self.log_q.put(f"All done in {mins:.1f} min.")
            self.log_q.put(lambda: (self.set_busy(False), self.refresh_status()))

        threading.Thread(target=work, daemon=True).start()

    # ---------- queries ----------
    def peek_table(self, _event=None):
        sel = self.tables.curselection()
        if not sel:
            return
        name = self.shown_tables[sel[0]]
        self.query.delete("1.0", "end")
        self.query.insert("1.0", f"SELECT * FROM `{name}` LIMIT 100;")
        self.run_query()

    def run_query(self):
        sql = self.query.get("1.0", "end").strip()
        if not sql or self.busy:
            return
        if not self.ensure_server():
            return
        self.set_busy(True)

        def work():
            try:
                out = run_sql(sql)
            except ET.ParseError as e:
                out = ([], f"Couldn't read the result: {e}", None)
            self.log_q.put(lambda: self._show_results(*out))

        threading.Thread(target=work, daemon=True).start()

    def _show_results(self, results, err, changed):
        self.set_busy(False)
        self.tree.delete(*self.tree.get_children())
        self.full_rows = {}
        if err:
            self.say("SQL error: " + err.replace("ERROR", "").strip())
        shown = results[-1] if results else ([], [])
        cols, rows = shown
        self.tree["columns"] = cols
        for c in cols:
            self.tree.heading(c, text=c)
            self.tree.column(c, width=140, minwidth=60, stretch=False)
        for r in rows[:MAX_SHOWN]:
            cells = ["NULL" if v is None else v.replace("\n", " ")[:200] for v in r]
            iid = self.tree.insert("", "end", values=cells)
            self.full_rows[iid] = (cols, r)
        if results:
            more = f" (showing first {MAX_SHOWN})" if len(rows) > MAX_SHOWN else ""
            self.result_lbl.configure(text=f"{len(rows)} row(s){more}  -  double-click a row to see it in full")
        else:
            self.result_lbl.configure(text="No rows to show")
        if changed is not None and changed >= 0 and not err:
            self.say(f"Done. {changed} row(s) changed by the last statement.")
            self.refresh_status()
        elif results and not err:
            self.say(f"Got {len(rows)} row(s).")

    def show_row(self, _event=None):
        iid = self.tree.focus()
        if iid not in self.full_rows:
            return
        cols, row = self.full_rows[iid]
        win = tk.Toplevel(self)
        win.title("Row")
        win.geometry("760x560")
        txt = tk.Text(win, font=("Consolas", 10), wrap="word")
        txt.pack(fill="both", expand=True)
        for c, v in zip(cols, row):
            txt.insert("end", f"{c}\n", "col")
            txt.insert("end", ("NULL" if v is None else v) + "\n\n")
        txt.tag_configure("col", font=("Consolas", 10, "bold"), foreground="#000080")
        txt.configure(state="disabled")


if __name__ == "__main__":
    try:  # sharp text on high-DPI screens
        import ctypes
        ctypes.windll.shcore.SetProcessDpiAwareness(1)
    except Exception:
        pass
    App().mainloop()
