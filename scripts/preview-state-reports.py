"""Preview a new state's report page before it exists on the site.

Loads a live tracker page (default /ga-reports/) in Playwright and swaps in
the working tree's report-page.js and the new state's adapter, and answers
inspections-read.php from a scraper's --out file (lite list, text on open,
the way the live API does). Takes screenshots at 390, 768 and 1440 px with
the first facility and its first flagged report opened, and prints console
errors and what the page shows.

    python scripts/preview-state-reports.py --state MI --file <out.json> --shots tmp/mi-preview
    [--host ga-reports] [--facility "Name to open"]
"""

import argparse
import json
import re
import sys
from pathlib import Path
from urllib.parse import parse_qs, urlparse

from playwright.sync_api import sync_playwright

REPO = Path(__file__).resolve().parent.parent
SITE = "https://kidsoverprofits.org"


def archive_key(name: str) -> str:
    name = re.sub(r"[?#].*$", "", name or "")
    name = name[name.rfind("/") + 1:]
    return re.sub(r"[^A-Za-z0-9._-]", "_", name).lower()


def main() -> int:
    ap = argparse.ArgumentParser()
    ap.add_argument("--state", required=True, help="Two-letter code, e.g. MI")
    ap.add_argument("--file", required=True, type=Path, help="The scraper's --out JSON")
    ap.add_argument("--host", default="ga-reports", help="Live tracker page to borrow")
    ap.add_argument("--shots", type=Path, default=REPO / "tmp" / "state-report-preview")
    ap.add_argument("--facility", default="", help="Facility to open (default: the first)")
    args = ap.parse_args()

    state = args.state.lower()
    adapter = REPO / "js" / "inspections" / "states" / f"{state}.js"
    data = json.loads(args.file.read_text(encoding="utf-8"))
    texts = {}
    lite = {k: v for k, v in data.items() if k != "facilities"}
    lite["facilities"] = []
    row = 0
    archive = {}
    for facility in data.get("facilities", []):
        reports = []
        for report in facility.get("reports", []):
            row += 1
            texts[str(row)] = report.get("raw_content", "")
            slim = {k: v for k, v in report.items() if k != "raw_content"}
            slim["row_id"] = row
            slim["has_text"] = bool(report.get("raw_content"))
            reports.append(slim)
            name = (report.get("categories") or {}).get("archive_name")
            if name:
                archive[archive_key(name)] = name
        lite["facilities"].append({**facility, "reports": reports})
    lite_body = json.dumps(lite)
    args.shots.mkdir(parents=True, exist_ok=True)

    def read_api(route, request):
        query = parse_qs(urlparse(request.url).query)
        if "text" in query:
            body = json.dumps({"raw_content": texts.get(query["text"][0], "")})
        else:
            body = lite_body
        route.fulfill(status=200, content_type="application/json", body=body)

    host_script = None
    errors = []
    with sync_playwright() as p:
        browser = p.chromium.launch()
        for width in (390, 768, 1440):
            page = browser.new_page(viewport={"width": width, "height": 900})
            page.on("console", lambda msg: msg.type == "error" and errors.append(f"{width}px: {msg.text}"))
            page.on("pageerror", lambda exc: errors.append(f"{width}px: {exc}"))
            page.route(re.compile(r"/api/inspections-read\.php"), read_api)
            page.route(re.compile(r"/themes/child/js/inspections/report-page\.js"),
                       lambda route, request: route.fulfill(path=str(REPO / "js/inspections/report-page.js"),
                                                            content_type="application/javascript"))
            page.route(re.compile(r"/themes/child/css/report-page\.css"),
                       lambda route, request: route.fulfill(path=str(REPO / "css/report-page.css"),
                                                            content_type="text/css"))
            host_code = args.host.split("-")[0]
            page.route(re.compile(rf"/themes/child/js/inspections/states/{host_code}\.js"),
                       lambda route, request: route.fulfill(path=str(adapter), content_type="application/javascript"))
            page.route(re.compile(r"/inspection-reports/[a-z]{2}/index\.json"),
                       lambda route, request: route.fulfill(status=200, content_type="application/json",
                                                            body=json.dumps({"files": archive} if f"/{state}/" in request.url else {"files": {}})))
            page.goto(f"{SITE}/{args.host}/", wait_until="networkidle", timeout=90000)
            page.wait_for_selector(".kop-rp-facility", timeout=60000)

            facilities = page.locator(".kop-rp-facility")
            target = facilities.first
            if args.facility:
                target = page.locator(".kop-rp-facility", has_text=args.facility).first
            target.locator(".kop-rp-facility-summary").first.click()
            flagged = target.locator(".kop-rp-report.is-flagged .kop-rp-report-summary")
            opener = flagged.first if flagged.count() else target.locator(".kop-rp-report-summary").first
            opener.click()
            page.wait_for_timeout(1500)
            target.scroll_into_view_if_needed()
            shot = args.shots / f"{state}-{width}.png"
            page.screenshot(path=str(shot), full_page=False)
            opener.scroll_into_view_if_needed()
            page.wait_for_timeout(800)
            page.screenshot(path=str(args.shots / f"{state}-{width}-report.png"), full_page=False)
            colours = page.evaluate("""() => [...document.querySelectorAll('.kop-rp-report[open] .kop-rp-official')]
                .slice(0, 2).map(a => a.textContent.trim() + ': ' + getComputedStyle(a).color + ' opacity ' + getComputedStyle(a).opacity)""")
            print(f"{width}px source links: {colours}")
            if width == 1440:
                print(f"facilities listed: {facilities.count()}")
                body = target.inner_text()
                print("opened facility:\n" + "\n".join(body.splitlines()[:60]))
                wide = page.evaluate("document.documentElement.scrollWidth > document.documentElement.clientWidth")
                print(f"horizontal scroll at 1440: {wide}")
            else:
                wide = page.evaluate("document.documentElement.scrollWidth > document.documentElement.clientWidth")
                print(f"horizontal scroll at {width}: {wide}")
            page.close()
        browser.close()

    print(f"screenshots in {args.shots}")
    if errors:
        print("console errors:")
        for line in errors:
            print("  " + line)
    return 1 if errors else 0


if __name__ == "__main__":
    sys.exit(main())
