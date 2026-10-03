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


def closure_item(status="pending", stage="closed", name="Sunrise Ranch", message=None):
    acts = ([{"id": "apply", "label": "Confirm closure", "style": "approve",
              "params": [{"name": "end_year", "label": "End year", "type": "number", "value": "2024"}]},
             {"id": "dismiss", "label": "Dismiss", "style": "reject"}]
            if status == "pending" else [{"id": "undo", "label": "Undo", "style": "undo"}])
    return {
        "key": "36", "title": name, "subtitle": "Closed, 2024-05-01 · Utah", "created": "2026-09-30 10:00:00",
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
    }


NEWS = {
    "key": "546", "title": "Teen dies at ranch", "status": "submitted", "status_label": "submitted", "url": "https://example.com/a",
    "fields": [{"name": "article_title", "label": "Title", "type": "text", "value": "Teen dies at ranch"},
               {"name": "article_type", "label": "Category", "type": "select", "category": True, "value": "general",
                "options": {"general": "General", "closure": "Closure", "lawsuit": "Lawsuit"}}],
    "moves": [{"id": "lawsuit", "label": "Move to Lawsuits"},
              {"id": "website", "label": "Move to Facility website", "params": [{"name": "facility_id", "label": "Facility", "type": "facility", "value": 0}]}],
    "actions": [], "tags": ["Neglect"], "links": [],
}

SOURCES = {"sources": [
    {"key": "news", "label": "News", "group": "Submissions", "views": {"pending": "Pending"}, "count": 2, "native": True,
     "tool_url": "", "help": "", "can_save": True, "can_ai": True, "has_origins": True},
    {"key": "closure", "label": "Closure reports", "group": "Found by the news scans",
     "views": {"pending": "To review", "applied": "Confirmed"}, "count": 6, "native": False,
     "tool_url": "https://inbox.test/wp-admin/admin.php?page=kop-closure-reports",
     "help": "Articles that say a facility closed.", "can_save": True, "can_ai": True, "has_origins": True},
    {"key": "drive", "label": "Drive Docs", "group": "Imports to review", "views": {"pending": "Waiting"}, "count": 0,
     "native": False, "tool_url": "", "help": "", "can_save": False, "can_ai": False},
], "tags": ["follow up", "needs source", "Neglect"]}


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
            elif path == "items":
                if "source=news" in url:
                    out = {"items": [NEWS], "total": 1}
                elif "view=applied" in url:
                    out = {"items": [closure_item("applied")], "total": 1}
                else:
                    out = {"items": [closure_item()], "total": 1}
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
            elif path == "act":
                if body["action"] == "apply":
                    out = {"message": "Confirmed. Sunrise Ranch is marked closed.", "item": closure_item("applied")}
                elif body["action"] == "move":
                    out = {"message": "Moved. It is waiting in Lawsuits as #67.", "item": dict(NEWS, moves=[], actions=[{"id": "unmove", "label": "Undo move to Lawsuits", "style": "undo"}])}
                else:
                    out = {"message": "Done.", "item": closure_item()}
            else:
                out = {"error": "unknown"}
            return r.fulfill(status=200, content_type="application/json", body=json.dumps(out))
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
            pg.wait_for_function("() => document.querySelector('.rinbox-origin') && document.querySelector('.rinbox-origin').options.length === 3")
            pg.locator(".rinbox-origin").select_option("sciad")
            pg.wait_for_timeout(300)
            check(any(c[0] == "items" and "origin=sciad" in c[2] for c in calls), f"@{width} a queue filters by where items came from")
            pg.locator(".rinbox-origin").select_option("")
            pg.wait_for_selector(".rinbox-card")
            check(pg.locator(".submissions-list-container").is_hidden(), f"@{width} the page's own list steps aside")
            check("type=closure" in pg.url, f"@{width} the open queue is in the address", pg.url)
            link = pg.locator(".rinbox-links a").nth(1)
            check("ksl.com" in link.inner_text() and "http" not in link.inner_text(), f"@{width} the article link reads as words", link.inner_text())

            pg.locator(".rinbox-card .rinbox-quick select").first.select_option("suspended")
            pg.wait_for_function("() => /Saved/.test(document.querySelector('.rinbox-card .rinbox-message').textContent)")
            check(any(c[0] == "save" and c[1]["fields"] == {"stage": "suspended"} for c in calls), f"@{width} category saves from the card")

            tag = pg.locator(".rinbox-card .rinbox-tag-input")
            tag.fill("Needs Source")
            tag.press("Enter")
            pg.wait_for_function("() => [...document.querySelectorAll('.rinbox-card .rinbox-tag')].some(t => /Needs Source/.test(t.textContent))")
            check(any(c[0] == "tags" and "Needs Source" in c[1]["tags"] and "follow up" in c[1]["tags"] for c in calls), f"@{width} a tag is added and saved")
            pg.locator(".rinbox-card .rinbox-tag-x").first.click()
            pg.wait_for_timeout(200)
            check(calls[-1][0] == "tags" and len(calls[-1][1]["tags"]) == 1, f"@{width} a tag is removed", json.dumps(calls[-1][1]))

            pg.locator(".rinbox-edit-toggle").click()
            pg.locator(".rinbox-editor input[data-field='program_name']").fill("Sunrise Ranch for Girls")
            pg.locator(".rinbox-editor button[type='submit']").click()
            pg.wait_for_function("() => /Sunrise Ranch for Girls/.test(document.querySelector('.rinbox-title').textContent)")
            check(any(c[0] == "save" and c[1]["fields"].get("program_name") == "Sunrise Ranch for Girls" for c in calls), f"@{width} Edit details saves every field")
            pg.locator(".rinbox-edit-toggle").click()
            pg.locator(".rinbox-card .rinbox-ai").click()
            pg.wait_for_function("() => /Filled/.test(document.querySelector('.rinbox-card .rinbox-message').textContent)")
            check(any(c[0] == "ai" for c in calls), f"@{width} Fill empty fields with AI runs")
            if width == 1280:
                pg.locator(".rinbox-edit-toggle").click()
                pg.screenshot(path=str(shots / f"closure-edit-{width}.png"), full_page=True)
                pg.locator(".rinbox-edit-toggle").click()

            pg.locator(".rinbox-card .rinbox-param input").fill("2023")
            pg.locator(".rinbox-card .rinbox-btn-approve").click()
            pg.wait_for_selector(".rinbox-card .rinbox-btn-undo")
            check(any(c[0] == "act" and c[1]["action"] == "apply" and c[1]["params"] == {"end_year": "2023"} for c in calls),
                  f"@{width} an action sends its parameters and shows Undo")
            check("Confirmed" in pg.locator(".rinbox-card .rinbox-message").inner_text(), f"@{width} the action's message is shown")

            wide = pg.evaluate("() => document.documentElement.scrollWidth")
            check(wide <= width, f"@{width} nothing is wider than the screen", f"{wide}px")
            pg.screenshot(path=str(shots / f"closure-{width}.png"), full_page=True)

            pg.locator(".type-tabs [data-type='news']").click()
            check(pg.locator(".rinbox-panel").is_hidden() and pg.locator(".submissions-list-container").is_visible(), f"@{width} the page's own tab brings its list back")
            check(not errors, f"@{width} no script errors", "; ".join(errors))
            pg.close()
        b.close()
    print("\n" + (f"{len(fails)} FAILED" if fails else "All passed") + f"; screenshots in {shots}")
    sys.exit(1 if fails else 0)


if __name__ == "__main__":
    main()
