"""
Browser test for the review inbox (js/review-inbox.js, css/review-inbox.css).

Draws the Submissions Review page's markup with the real stylesheets and
script, answers kop/v1/review-inbox/* from fixtures, and checks: the other
queues get tabs with counts, a queue opens with its cards, category and tags
save from the card, Edit details saves and runs "Fill empty fields with AI",
an action shows its message and Undo, the page's own cards get the quick
row, and nothing is wider than a phone screen. Screenshots go to --shots.

    python scripts/test-review-inbox-ui.py [--shots tmp/review-inbox-ui]
"""
import argparse
import json
import pathlib
import sys

from playwright.sync_api import sync_playwright

ROOT = pathlib.Path(__file__).resolve().parent.parent
BASE = "https://inbox.test/"
REST = BASE + "wp-json/kop/v1/review-inbox/"

PAGE = """<!doctype html><html><head><meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<link rel="stylesheet" href="/css/colors.css"><link rel="stylesheet" href="/css/admin-submissions.css">
<link rel="stylesheet" href="/css/review-inbox.css"></head>
<body style="background:#F2EEDF;margin:0;padding:16px">
<div class="admin-submissions-page"><div class="admin-submissions-container">
<header class="admin-header"><h1>Submissions Review</h1><div class="admin-stats" id="adminStats"></div></header>
<div class="admin-controls">
  <div class="filter-controls">
    <input type="hidden" id="typeFilter" value="news">
    <div class="submission-tabs type-tabs" role="tablist">
      <button type="button" class="submission-tab is-active" data-type="news">News <span class="tab-count" data-count-for="news">2</span></button>
      <button type="button" class="submission-tab" data-type="lawsuit">Lawsuits <span class="tab-count" data-count-for="lawsuit"></span></button>
    </div>
  </div>
  <div class="filter-controls"><input type="hidden" id="statusFilter" value="submitted"><div class="submission-tabs status-tabs"><button type="button" class="submission-tab is-active">Pending</button></div>
    <label for="originFilter">Came from:</label><select id="originFilter" class="origin-filter"><option value="">Everywhere</option></select>
    <button type="button" id="refreshBtn">Refresh</button></div>
  <div class="bulk-actions"><button type="button">Approve selected</button></div>
</div>
<div class="submissions-list-container"><div id="submissionsList" class="submissions-list"></div></div>
</div></div>
<script>window.kopReviewInbox = {rest: %REST%, nonce: "n"};</script>
<script>window.kopRefreshes = 0; document.addEventListener('click', function (e) { if (e.target.id === 'refreshBtn') window.kopRefreshes++; });</script>
<script src="/js/url-labels.js"></script>
<script src="/js/review-inbox.js"></script>
<script>
setTimeout(function () {
  var c = document.createElement('div');
  c.className = 'submission-card is-pending'; c.dataset.id = '546';
  c.innerHTML = '<div class="submission-header"><h3>Teen dies at ranch</h3><span class="status-badge">submitted</span></div>' +
    '<div class="submission-meta"><span>Some Paper</span></div>' +
    '<div class="submission-footer"><button type="button" class="btn-view">View Details</button></div>';
  document.getElementById('submissionsList').appendChild(c);
}, 50);
</script>
</body></html>""".replace("%REST%", json.dumps(REST))


def closure_item(status="pending", stage="closed", name="Sunrise Ranch", message=None, key="36"):
    acts = ([{"id": "apply", "label": "Confirm closure", "style": "approve",
              "params": [{"name": "end_year", "label": "End year", "type": "number", "value": "2024"}]},
             {"id": "dismiss", "label": "Dismiss", "style": "reject", "help": "Nothing on the site changes."}]
            if status == "pending" else [{"id": "undo", "label": "Undo", "style": "undo"}])
    return {
        "key": key, "title": name, "subtitle": "Closed, 2024-05-01 · Utah", "created": "2026-09-30 10:00:00",
        "url": "https://www.ksl.com/article/50912345/utah-youth-ranch-closes-after-state-investigation",
        "text": '"The ranch closed its doors on May 1."', "status": status,
        "status_label": "To review" if status == "pending" else "Confirmed",
        "facility": {"id": 12, "name": "Sunrise Ranch (Hurricane, UT)", "url": "https://inbox.test/facility/sunrise-ranch-ut/"},
        "fields": [
            {"name": "program_name", "label": "Program name", "type": "text", "value": name},
            {"name": "facility_id", "label": "Facility", "type": "facility", "value": 12},
            {"name": "stage", "label": "What happened", "type": "select", "category": True, "value": stage,
             "options": {"closed": "Closed", "closing": "Announced closing", "suspended": "Suspended"}},
            {"name": "closure_date", "label": "Closure date", "type": "text", "value": ""},
            {"name": "notes", "label": "Notes", "type": "textarea", "value": ""},
        ],
        "actions": acts, "moves": [], "links": [], "tags": ["follow up"],
        "details": [{"label": "Facility status now", "value": "Open"}],
        "compare": {"heads": ["This report", "The record"], "rows": [{"label": "Status", "values": ["Closed", "Open"], "differs": True}]},
        "preview": {"label": "Show the article", "url": "https://inbox.test/preview.html"},
    }


NEWS = {
    "key": "546", "title": "Teen dies at ranch", "status": "submitted", "status_label": "submitted", "url": "https://example.com/a",
    "fields": [{"name": "article_title", "label": "Title", "type": "text", "value": "Teen dies at ranch"},
               {"name": "article_type", "label": "Category", "type": "select", "category": True, "value": "general",
                "options": {"general": "General", "closure": "Closure", "lawsuit": "Lawsuit"}}],
    "moves": [{"id": "lawsuit", "label": "Move to Lawsuits"},
              {"id": "website", "label": "Move to Facility website", "params": [{"name": "facility_id", "label": "Facility", "type": "facility", "value": 0}]}],
    "actions": [], "tags": ["Neglect"], "links": [],
    "approve_help": "Puts this article on the site.", "reject_help": "Nothing on the site changes.",
}

SOURCES = {"sources": [
    {"key": "news", "label": "News", "group": "Submissions", "views": {"pending": "Pending"}, "count": 2, "native": True,
     "tool_url": "", "help": "", "can_save": True, "can_ai": True, "has_origins": True},
    {"key": "closure", "label": "Closure reports", "group": "Found by the news scans",
     "views": {"pending": "To review", "applied": "Confirmed"}, "count": 6, "native": False,
     "tool_url": "https://inbox.test/wp-admin/admin.php?page=kop-closure-reports",
     "help": "Articles that say a facility closed.", "can_save": True, "can_ai": True, "has_origins": True,
     "tools": [{"id": "scan", "label": "Scan the next articles now", "params": [{"name": "count", "label": "How many", "type": "number", "value": 10}]}]},
    {"key": "drive", "label": "Drive Docs", "group": "Imports to review", "views": {"pending": "Waiting", "applied": "Added"}, "count": 2,
     "native": False, "tool_url": "", "help": "", "can_save": True, "can_ai": True,
     "filters": [{"name": "kind", "label": "Kind", "options": {"news": "News article", "court": "Court record"}}]},
], "tags": ["follow up", "needs source", "Neglect"], "admins": [{"id": 1, "name": "Dani"}, {"id": 2, "name": "Pat"}],
    "me": 1, "held": {"mine": 1, "snoozed": 0}}

LOG = {"rows": [{"id": 9, "source": "closure", "source_label": "Closure reports", "key": "36", "title": "Sunrise Ranch",
                 "action": "apply", "action_label": "Confirm closure", "style": "approve", "message": "Confirmed.",
                 "user": "dani", "created": "2026-10-05 14:00:00Z", "can_undo": True, "undone": None}], "total": 1}


def drive_item(key, sure):
    """A Drive Docs link: a sure match starts ticked; Add's Record box may stay empty; a company is found by name."""
    return {
        "key": key, "title": "Link " + key, "status": "pending", "status_label": "Waiting", "selected": sure,
        "fields": [], "moves": [], "links": [], "tags": [],
        "actions": [{"id": "apply", "label": "Add: News queue", "style": "approve",
                     "params": [{"name": "facility", "label": "Record", "type": "facility", "value": 0, "optional": True}]},
                    {"id": "file", "label": "File", "style": "neutral",
                     "params": [{"name": "company", "label": "Company", "type": "text", "value": "", "lookup": "company", "optional": True}]}],
    }


DRIVE_ROWS = {}   # label/kind changes the mocked save and ai make to the facility card's links


def drive_card():
    """One card per facility: a link and three from one website folded into a row, Add/Skip the ticked ones."""
    def row(k, label, kind="news"):
        return dict({"label": label, "kind": kind}, **DRIVE_ROWS.get(k, {}))
    return {
        "key": "g:f9::", "title": "Sunrise Ranch", "subtitle": "4 links: 4 news articles", "status": "pending", "status_label": "Waiting",
        "selected": False, "fields": [], "moves": [], "links": [], "tags": [],
        "checklist": [
            dict({"keys": ["k1"], "url": "https://www.ksl.com/a", "sub": "News article · goes to news, live at once",
                  "note": "", "checked": True, "rename": True}, **row("k1", "Ranch under investigation")),
            {"keys": ["k2", "k3", "k4"], "label": "sltrib.com: 3 news articles", "sub": "goes to news, live at once", "checked": True,
             "items": [dict({"key": k, "url": "https://www.sltrib.com/" + k, "sub": "", "note": "", "rename": True}, **row(k, "Tribune story " + k))
                       for k in ("k2", "k3", "k4")]},
        ],
        "row_tools": {"kinds": {"news": "News article", "court": "Court record", "other": "Other"}, "ai": True},
        "actions": [{"id": "add_picked", "label": "Add ticked links", "style": "approve",
                     "params": [{"name": "facility", "label": "Record", "type": "facility", "value": 9, "optional": True}]},
                    {"id": "skip_picked", "label": "Skip ticked links", "style": "reject"}],
    }


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--shots", default=str(ROOT / "tmp" / "review-inbox-ui"))
    args = ap.parse_args()
    shots = pathlib.Path(args.shots)
    shots.mkdir(parents=True, exist_ok=True)
    calls = []
    fails = []

    def check(ok, what, detail=""):
        print(("PASS " if ok else "FAIL ") + what + (f"  ({detail})" if detail else ""))
        if not ok:
            fails.append(what)

    def route(r):
        url = r.request.url
        if url.startswith(REST):
            path = url[len(REST):].split("?")[0]
            body = json.loads(r.request.post_data) if r.request.post_data else None
            calls.append((path, body, url))
            if path == "sources":
                out = SOURCES
            elif path == "origins":
                out = {"origins": [{"key": "scraper-google", "label": "News scraper: Google News", "count": 3},
                                   {"key": "sciad", "label": "SCIAD NET", "count": 2}]}
            elif path == "lookup":
                out = {"options": [{"value": "c45", "label": "Aspen Education Group · Company (operator)"}]}
            elif path == "items" and "keys=" in url and "source=closure" in url:
                out = {"items": [dict(closure_item(), hold={"assigned_to": 2, "hidden": True})], "total": 1}
            elif path == "items" and "source=drive" in url:
                out = {"items": [drive_item("a1", True), drive_item("a2", False), drive_card()], "total": 3, "view_counts": {"pending": 2, "applied": 7}}
            elif path == "items":
                if "source=news" in url:
                    out = {"items": [NEWS], "total": 1}
                elif "view=applied" in url:
                    out = {"items": [closure_item("applied")], "total": 1}
                else:
                    out = {"items": [closure_item(), closure_item(name="Canyon House", key="37")], "total": 2}
            elif path == "item" and "source=drive" in url:
                out = {"item": drive_card()}
            elif path in ("save", "ai") and body["source"] == "drive":
                if path == "ai":
                    DRIVE_ROWS.setdefault(body["key"], {})["label"] = "AI title for " + body["key"]
                else:
                    DRIVE_ROWS.setdefault(body["key"], {}).update(body["fields"])
                out = {"message": "Saved." if path == "save" else "Named.", "item": drive_item(body["key"], True)}
            elif path == "save":
                f = body["fields"]
                if body["source"] == "news":
                    item = dict(NEWS, title=f.get("article_title", NEWS["title"]))
                else:
                    item = closure_item(stage=f.get("stage", "closed"), name=f.get("program_name", "Sunrise Ranch"))
                out = {"message": "Saved.", "item": item}
            elif path == "tags":
                out = {"tags": sorted(body["tags"], key=str.lower), "message": "Tags saved."}
            elif path == "ai":
                out = {"message": "Filled: Closure date.", "filled": ["closure_date"], "item": closure_item() if body["source"] == "closure" else NEWS}
            elif path == "preview":
                out = {"url": "https://www.ksl.com/a", "host": "ksl.com", "frame_url": "", "kind": "page", "site": "KSL",
                       "title": "Utah youth ranch closes", "image": "", "text": "The ranch closed its doors on May 1. " + chr(10) * 2 + "State officials said...", "note": ""}
            elif path == "hold":
                out = {"message": "Handed to Pat, back on Oct 12."}
            elif path == "held":
                out = {"items": [dict(closure_item(key="40", name="Held Ranch"), source="closure", source_label="Closure reports",
                                      hold={"assigned_to": 1, "assigned_name": "Dani", "mine": True, "snooze_until": "", "note": "check dates", "by": "pat", "hidden": False})], "total": 1}
            elif path == "log":
                out = LOG
            elif path == "undo":
                out = {"message": "Undone."}
            elif path == "tool":
                out = {"message": "Scanned 10 articles: 1 closure found."}
            elif path == "act" and body["source"] == "drive" and body["key"].startswith("g:"):
                left = drive_card()
                left["checklist"] = left["checklist"][1:]
                out = {"message": "Added 1 link, each where its kind goes.", "item": left}
            elif path == "act" and body["source"] == "drive":
                out = {"message": "Added to the news queue.", "item": None}
            elif path == "act":
                if body["action"] == "apply":
                    out = {"message": "Confirmed. Sunrise Ranch is marked closed.", "item": closure_item("applied")}
                elif body["action"] == "move":
                    out = {"message": "Moved. It is waiting in Lawsuits as #67.", "item": dict(NEWS, moves=[], actions=[{"id": "unmove", "label": "Undo move to Lawsuits", "style": "undo"}])}
                elif body["action"] == "dismiss":
                    out = {"message": "Dismissed.", "item": closure_item("dismissed", key=body["key"])}
                else:
                    out = {"message": "Done.", "item": closure_item()}
            else:
                out = {"error": "unknown"}
            return r.fulfill(status=200, content_type="application/json", body=json.dumps(out))
        if url == BASE + "preview.html":
            return r.fulfill(status=200, content_type="text/html", body="<p>The article</p>")
        if url == BASE or url.startswith(BASE + "?"):
            return r.fulfill(status=200, content_type="text/html", body=PAGE)
        local = ROOT / url[len(BASE):].split("?")[0]
        if local.is_file():
            ctype = "text/css" if local.suffix == ".css" else "application/javascript"
            return r.fulfill(status=200, content_type=ctype, body=local.read_text(encoding="utf-8"))
        return r.fulfill(status=404, body="")

    with sync_playwright() as p:
        b = p.chromium.launch(channel="chrome")
        for width in (1280, 390):
            calls.clear()
            pg = b.new_page(viewport={"width": width, "height": 900})
            errors = []
            pg.on("pageerror", lambda e: errors.append(str(e)))
            pg.on("dialog", lambda d: d.accept())
            pg.route("https://inbox.test/**", route)
            pg.goto(BASE, wait_until="load")
            pg.wait_for_selector(".rinbox-tab")
            tabs = pg.locator(".rinbox-tab").all_inner_texts()
            check(any("Closure reports" in t and "6" in t for t in tabs) and not any(t.startswith("News") for t in tabs),
                  f"@{width} other queues get tabs with counts, the page's own types do not", " | ".join(tabs))

            pg.wait_for_selector(".submission-card .rinbox-native")
            check(pg.locator(".submission-card .rinbox-native select").count() >= 1, f"@{width} the page's own card gets the quick row")
            ev = pg.locator(".submission-card .rinbox-native-evidence")
            check("Puts this article on the site" in ev.inner_text() and ev.locator(".rinbox-pv-btn").count() == 1,
                  f"@{width} the page's own card shows its link with Preview and what Approve does", ev.inner_text())
            pg.locator(".submission-card .rinbox-native select").first.select_option("closure")
            pg.wait_for_function("() => document.querySelector('.rinbox-native .rinbox-message') && /Saved/.test(document.querySelector('.rinbox-native .rinbox-message').textContent)")
            check(any(c[0] == "save" and c[1]["fields"] == {"article_type": "closure"} for c in calls), f"@{width} native category saves on change")
            pg.locator(".rinbox-native button", has_text="Rename").click()
            pg.locator(".rinbox-native-name").fill("Teen dies at Utah ranch")
            pg.locator(".rinbox-native-rename button").click()
            pg.wait_for_function("() => /Utah ranch/.test(document.querySelector('.submission-card h3').textContent)")
            check(True, f"@{width} rename updates the card's heading")
            pg.wait_for_function("() => document.getElementById('originFilter').options.length === 3")
            check("SCIAD NET (2)" in pg.locator("#originFilter").inner_text(), f"@{width} Came from lists the page's own origins with counts")
            pg.locator("#originFilter").select_option("sciad")
            check(pg.evaluate("window.kopRefreshes") >= 1, f"@{width} choosing an origin reloads the page's own list")
            pg.locator(".rinbox-native select[aria-label='Move to another queue']").select_option("website")
            check(pg.locator(".rinbox-native .rinbox-move-form input[data-field='facility_id']").is_visible(), f"@{width} a facility destination asks for the facility first")
            pg.locator(".rinbox-native .rinbox-move-form input[data-field='facility_id']").fill("12")
            pg.locator(".rinbox-native .rinbox-move-form button").click()
            pg.wait_for_function("() => /Moved/.test(document.querySelector('.rinbox-native .rinbox-message').textContent)")
            check(any(c[0] == "act" and c[1]["params"] == {"to": "website", "facility_id": "12"} for c in calls), f"@{width} the move sends the facility",
                  json.dumps([c[1] for c in calls if c[0] == "act"]))
            calls.clear()
            pg.reload(wait_until="load")
            pg.wait_for_selector(".submission-card .rinbox-native")
            pg.locator(".rinbox-native select[aria-label='Move to another queue']").select_option("lawsuit")
            pg.wait_for_selector(".rinbox-native button:has-text('Undo move')")
            check(True, f"@{width} move shows its Undo")
            if width == 1280:
                pg.screenshot(path=str(shots / f"native-{width}.png"), full_page=True)

            pg.locator(".rinbox-tab", has_text="Closure reports").click()
            pg.wait_for_selector(".rinbox-card")
            check(pg.locator(".rinbox-card").first.locator(".rinbox-details").inner_text().find("Open") >= 0, f"@{width} facts show on the card")
            check(pg.locator(".rinbox-card").first.locator(".rinbox-compare tr.rinbox-differs").count() == 1, f"@{width} a comparison marks what differs")
            pg.locator(".rinbox-card").first.locator(".rinbox-preview-toggle").click()
            check(pg.locator(".rinbox-card").first.locator(".rinbox-preview iframe").is_visible(), f"@{width} the preview opens inside the card")
            pg.locator(".rinbox-tools input[data-field='count']").fill("5")
            pg.locator(".rinbox-tools button").click()
            pg.wait_for_function("() => /Scanned/.test(document.querySelector('.rinbox-status').textContent)")
            check(any(c[0] == "tool" and c[1]["tool"] == "scan" and c[1]["params"] == {"count": "5"} for c in calls), f"@{width} a queue tool runs with its value")
            pg.wait_for_selector(".rinbox-card")
            pg.locator(".rinbox-bulk input[type='checkbox']").check()
            pg.locator(".rinbox-bulk button", has_text="Dismiss (2)").click()
            pg.wait_for_function("() => /Dismiss: 2 done/.test(document.querySelector('.rinbox-status').textContent)")
            check(sum(1 for c in calls if c[0] == "act" and c[1]["action"] == "dismiss") == 2, f"@{width} a bulk action runs on every selected card")
            check(pg.locator(".rinbox-bulk button", has_text="Confirm closure").count() == 0, f"@{width} an action that needs a value per item is not offered in bulk unless filled")
            pg.locator(".rinbox-views button", has_text="To review").click()
            pg.wait_for_selector(".rinbox-card")
            pg.wait_for_function("() => document.querySelector('.rinbox-origin') && document.querySelector('.rinbox-origin').options.length === 3")
            pg.locator(".rinbox-origin").select_option("sciad")
            pg.wait_for_timeout(300)
            check(any(c[0] == "items" and "origin=sciad" in c[2] for c in calls), f"@{width} a queue filters by where items came from")
            pg.locator(".rinbox-origin").select_option("")
            pg.wait_for_selector(".rinbox-card")
            check(pg.locator(".submissions-list-container").is_hidden(), f"@{width} the page's own list steps aside")
            check("type=closure" in pg.url, f"@{width} the open queue is in the address", pg.url)
            link = pg.locator(".rinbox-card").first.locator(".rinbox-links a").first
            check("ksl.com" in link.inner_text() and "http" not in link.inner_text(), f"@{width} the article link reads as words", link.inner_text())

            pg.locator(".rinbox-card .rinbox-quick select").first.select_option("suspended")
            pg.wait_for_function("() => /Saved/.test(document.querySelector('.rinbox-card .rinbox-message').textContent)")
            check(any(c[0] == "save" and c[1]["fields"] == {"stage": "suspended"} for c in calls), f"@{width} category saves from the card")

            tag = pg.locator(".rinbox-card .rinbox-tag-input").first
            tag.fill("Needs Source")
            tag.press("Enter")
            pg.wait_for_function("() => [...document.querySelectorAll('.rinbox-card .rinbox-tag')].some(t => /Needs Source/.test(t.textContent))")
            check(any(c[0] == "tags" and "Needs Source" in c[1]["tags"] and "follow up" in c[1]["tags"] for c in calls), f"@{width} a tag is added and saved")
            pg.locator(".rinbox-card").first.locator(".rinbox-tag-x").first.click()
            pg.wait_for_timeout(200)
            check(calls[-1][0] == "tags" and len(calls[-1][1]["tags"]) == 1, f"@{width} a tag is removed", json.dumps(calls[-1][1]))

            pg.locator(".rinbox-edit-toggle").first.click()
            pg.locator(".rinbox-editor input[data-field='program_name']").first.fill("Sunrise Ranch for Girls")
            pg.locator(".rinbox-editor button[type='submit']").first.click()
            pg.wait_for_function("() => /Sunrise Ranch for Girls/.test(document.querySelector('.rinbox-title').textContent)")
            check(any(c[0] == "save" and c[1]["fields"].get("program_name") == "Sunrise Ranch for Girls" for c in calls), f"@{width} Edit details saves every field")
            pg.locator(".rinbox-card .rinbox-rename-toggle").first.click()
            pg.locator(".rinbox-card .rinbox-rename-name").first.fill("Sunrise Ranch")
            pg.locator(".rinbox-card .rinbox-rename button[type='submit']").first.click()
            pg.wait_for_function("() => /Sunrise Ranch$/.test(document.querySelector('.rinbox-title').textContent.trim())")
            check(any(c[0] == "save" and c[1]["fields"] == {"program_name": "Sunrise Ranch"} for c in calls), f"@{width} Rename beside the title saves only the name")
            pg.locator(".rinbox-edit-toggle").first.click()
            pg.locator(".rinbox-card .rinbox-ai").first.click()
            pg.wait_for_function("() => /Filled/.test(document.querySelector('.rinbox-card .rinbox-message').textContent)")
            check(any(c[0] == "ai" for c in calls), f"@{width} Fill empty fields with AI runs")
            if width == 1280:
                pg.locator(".rinbox-edit-toggle").first.click()
                pg.screenshot(path=str(shots / f"closure-edit-{width}.png"), full_page=True)
                pg.locator(".rinbox-edit-toggle").first.click()

            card0 = pg.locator(".rinbox-card").first
            check("Nothing on the site changes" in card0.locator(".rinbox-does").inner_text(), f"@{width} the card says what Reject does")
            check(card0.locator(".rinbox-decide .rinbox-btn-approve").count() == 1 and card0.locator(".rinbox-decide .rinbox-btn-reject").count() == 1,
                  f"@{width} Approve and Reject sit together first")
            card0.locator(".rinbox-pv-btn").first.click()
            pg.wait_for_selector(".rinbox-card .rinbox-pv-reader h4")
            check("Utah youth ranch closes" in card0.locator(".rinbox-pv").inner_text() and any(c[0] == "preview" for c in calls),
                  f"@{width} Preview shows a reading copy inside the card")
            card0.locator(".rinbox-pv-head button", has_text="Close").click()
            check(card0.locator(".rinbox-pv").is_hidden(), f"@{width} the preview closes")

            pg.locator(".rinbox-card .rinbox-param input").first.fill("2023")
            pg.locator(".rinbox-card .rinbox-btn-approve").first.click()
            pg.wait_for_selector(".rinbox-card .rinbox-btn-undo")
            check(any(c[0] == "act" and c[1]["action"] == "apply" and c[1]["params"] == {"end_year": "2023"} for c in calls),
                  f"@{width} an action sends its parameters and shows Undo")
            pg.wait_for_function("() => [...document.querySelectorAll('.rinbox-card .rinbox-message')].some(m => /Confirmed/.test(m.textContent))", timeout=5000)
            check(True, f"@{width} the action's message is shown")

            # Later: hand the second card to someone else; it leaves the list.
            card1 = pg.locator(".rinbox-card").nth(1)
            card1.locator(".rinbox-later summary").click()
            card1.locator(".rinbox-later select[aria-label='Hand to']").select_option("2")
            card1.locator(".rinbox-later button", has_text="Set aside").click()
            pg.wait_for_function("() => [...document.querySelectorAll('.rinbox-card.rinbox-gone')].some(c => /Handed to Pat/.test(c.textContent))")
            check(any(c[0] == "hold" and c[1]["assign"] == 2 and c[1]["days"] == 7 for c in calls), f"@{width} Later sends the snooze and the person",
                  json.dumps([c[1] for c in calls if c[0] == "hold"]))

            wide = pg.evaluate("() => document.documentElement.scrollWidth")
            check(wide <= width, f"@{width} nothing is wider than the screen", f"{wide}px")
            pg.screenshot(path=str(shots / f"closure-{width}.png"), full_page=True)

            # A queue's own dropdowns, tab counts, sure matches ticked, optional values in bulk, names looked up as typed.
            pg.locator(".rinbox-tab", has_text="Drive Docs").click()
            pg.wait_for_selector(".rinbox-card[data-key='a2']")
            check("Added (7)" in pg.locator(".rinbox-views").inner_text(), f"@{width} the view tabs show their counts", pg.locator(".rinbox-views").inner_text())
            ticked = pg.evaluate("() => [...document.querySelectorAll('.rinbox-card .rinbox-select')].map(c => c.checked)")
            check(ticked == [True, False, False], f"@{width} a sure match starts ticked", json.dumps(ticked))
            check(pg.locator(".rinbox-bulk button", has_text="Add: News queue (1)").count() == 1,
                  f"@{width} an action whose values may stay empty is offered in bulk")
            pg.locator(".rinbox-filter").select_option("news")
            pg.wait_for_timeout(300)
            check(any(c[0] == "items" and "f_kind=news" in c[2] for c in calls), f"@{width} a queue's own filter reloads its list")
            pg.wait_for_selector(".rinbox-card[data-key='a2']")
            look = pg.locator(".rinbox-card[data-key='a2'] input[data-field='company']")
            look.fill("Aspen")
            pg.wait_for_function("() => [...document.querySelectorAll('datalist option')].some(o => o.value === 'c45')")
            check(any(c[0] == "lookup" and "name=company" in c[2] and "q=Aspen" in c[2] for c in calls), f"@{width} a lookup field suggests values as it is typed")
            pg.locator(".rinbox-bulk button", has_text="Add: News queue (1)").click()
            pg.wait_for_function("() => /Add: News queue: 1 done/.test(document.querySelector('.rinbox-status').textContent)")
            check(any(c[0] == "act" and c[1]["source"] == "drive" and c[1]["key"] == "a1" and c[1]["params"] == {"facility": ""} for c in calls),
                  f"@{width} bulk sends each card's own values", json.dumps([c[1] for c in calls if c[0] == "act" and c[1]["source"] == "drive"]))

            # One card per facility: tick boxes, a website's links folded together, Add sends only the ticked ones.
            group = pg.locator(".rinbox-card[data-key='g:f9::']")
            check(group.locator(".rinbox-check-count").inner_text() == "4 of 4 links ticked", f"@{width} the card counts its ticked links",
                  group.locator(".rinbox-check-count").inner_text())
            group.locator(".rinbox-check-more summary").click()
            check(group.locator(".rinbox-check-items a").count() == 3, f"@{width} a folded website row opens to its links")
            group.locator(".rinbox-check-bundle input[type=checkbox]").uncheck()
            check(group.locator(".rinbox-check-count").inner_text() == "1 of 4 links ticked", f"@{width} unticking a folded row unticks all its links")
            wide = pg.evaluate("() => document.documentElement.scrollWidth")
            check(wide <= width, f"@{width} the facility card fits the screen", f"{wide}px")
            row = group.locator(".rinbox-check").first
            row.locator(".rinbox-rename-toggle").click()
            row.locator(".rinbox-rename-name").fill("Ranch cited by the state")
            row.locator(".rinbox-rename button[type='submit']").click()
            pg.wait_for_function("() => /Ranch cited by the state/.test(document.querySelector(\"[data-key='g:f9::'] .rinbox-check a\").textContent)")
            check(any(c[0] == "save" and c[1]["key"] == "k1" and c[1]["fields"] == {"label": "Ranch cited by the state"} for c in calls),
                  f"@{width} a link in the facility card is renamed from its row")
            check(group.locator(".rinbox-check-count").inner_text() == "1 of 4 links ticked", f"@{width} the card keeps its ticks after a row changes",
                  group.locator(".rinbox-check-count").inner_text())
            check(group.locator(".rinbox-check-more").first.get_attribute("open") is not None, f"@{width} an open website row stays open after a change")
            row.locator("select.rinbox-row-kind").select_option("court")
            pg.wait_for_function("() => document.querySelector(\"[data-key='g:f9::'] .rinbox-check select.rinbox-row-kind\").value === 'court'")
            check(any(c[0] == "save" and c[1]["key"] == "k1" and c[1]["fields"] == {"kind": "court"} for c in calls), f"@{width} a link's kind changes from its row")
            inner = group.locator(".rinbox-check-items li").first
            inner.locator("button", has_text="Name it with AI").click()
            pg.wait_for_function("() => /AI title for k2/.test(document.querySelector(\"[data-key='g:f9::'] .rinbox-check-items\").textContent)")
            check(any(c[0] == "ai" and c[1]["source"] == "drive" and c[1]["key"] == "k2" for c in calls), f"@{width} Name it with AI runs on one link inside a folded row")
            DRIVE_ROWS.clear()
            pg.screenshot(path=str(shots / f"drive-card-{width}.png"), full_page=True)
            group.locator("button", has_text="Add ticked links").click()
            pg.wait_for_function("() => /Added 1 link/.test(document.querySelector(\"[data-key='g:f9::']\").textContent)")
            sent = [c[1] for c in calls if c[0] == "act" and c[1]["key"] == "g:f9::"]
            check(len(sent) == 1 and sent[0]["action"] == "add_picked" and sent[0]["params"]["picked"] == ["k1"] and str(sent[0]["params"]["facility"]) == "9",
                  f"@{width} Add ticked sends only the ticked links", json.dumps(sent))
            check(group.locator(".rinbox-check").count() == 1, f"@{width} the card then shows what is left")

            # Your inbox: assigned to you, recently done with Undo.
            pg.locator(".rinbox-tab", has_text="Assigned to you").click()
            pg.wait_for_selector(".rinbox-card[data-key='40']")
            check("Closure reports" in pg.locator(".rinbox-card[data-key='40'] .rinbox-queue-badge").inner_text()
                  and "check dates" in pg.locator(".rinbox-card[data-key='40'] .rinbox-hold").inner_text(), f"@{width} Assigned to you shows the queue and the note")
            pg.locator(".rinbox-tab", has_text="Recently done").click()
            pg.wait_for_selector(".rinbox-done-row")
            pg.locator(".rinbox-done-row button", has_text="Undo").click()
            pg.wait_for_selector(".rinbox-done-row.is-undone")
            check(any(c[0] == "undo" and c[1] == {"id": 9} for c in calls), f"@{width} Undo from Recently done runs once")
            check("type=_done" in pg.url, f"@{width} Recently done is in the address", pg.url)
            wide = pg.evaluate("() => document.documentElement.scrollWidth")
            check(wide <= width, f"@{width} Recently done fits the screen", f"{wide}px")
            if width == 1280:
                pg.screenshot(path=str(shots / f"done-{width}.png"), full_page=True)

            pg.locator(".type-tabs [data-type='news']").click()
            check(pg.locator(".rinbox-panel").is_hidden() and pg.locator(".submissions-list-container").is_visible(), f"@{width} the page's own tab brings its list back")
            check(not errors, f"@{width} no script errors", "; ".join(errors))
            pg.close()
        b.close()
    print("\n" + (f"{len(fails)} FAILED" if fails else "All passed") + f"; screenshots in {shots}")
    sys.exit(1 if fails else 0)


if __name__ == "__main__":
    main()
