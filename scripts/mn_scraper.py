"""
MN DHS Children's Residential Facilities Scraper
=================================================
Scrapes facility details and inspection/compliance documents from:
    https://licensinglookup.dhs.state.mn.us/

Usage:
    pip install playwright python-dotenv requests
    playwright install chromium
    python mn_scraper.py

Output:
    js/data/mn_reports.json  — static fallback consumed by mn_reports.js
    (also POSTs to inspections-write.php if API base/key are set)

Environment variables:
    INSPECTIONS_API_BASE     e.g. https://kids-over-profits.local/wp-content/themes/child
    INSPECTIONS_API_KEY      value of INSPECTIONS_API_KEY on the server

Legacy aliases supported for compatibility:
    KOP_API_BASE
    KOP_API_KEY
"""

import asyncio
import csv
import json
import re
import os
import sys
import requests
from datetime import datetime, timezone
from pathlib import Path
from urllib.parse import urljoin, urlparse
from playwright.async_api import async_playwright

# Load .env from the repo root if present
try:
    from dotenv import load_dotenv
    env_path = Path(__file__).parent.parent / ".env"
    if env_path.exists():
        load_dotenv(env_path)
except ImportError:
    pass  # dotenv optional — fall back to os.environ only

# ── CONFIG ────────────────────────────────────────────────────────────────────

RESULTS_URL = (
    "https://licensinglookup.dhs.state.mn.us/Results.aspx"
    "?a=False&cdtrt=False&crfcc=False&crfmhc=False&e=0&dsfpv=False&hcbsbss=False"
    "&ppy40=False&crfss=False&irts=False&qrtp61=False&crfsc=False&con=All"
    "&afcfads=False&hcbsses=False&rsfsls=False&crsrc=False&ppy62=False"
    "&ppy61=False&dsfeds=False&sn=All&irtsrcs=False&co=All&sils62=False"
    "&hcbsihss=False&crssls=False&hcbsics=False&locked=False&adcrem29=False"
    "&sils40=False&ci=All&hcbsds=False&crfgrs=False&crfts=False&rsfrs=false"
    "&hcbsrss=False&cdtsamht=False&hcbsiss=False&crfrt=False&crscr=False"
    "&crfprtf=False&cdtidat=False&stcse40=False&qrtp40=False&crsaost=False"
    "&cdtat=False&rcs40=False&dsfess=False&crfcdc=False&rcs=False&stcse62=False"
    "&stcse61=False&qrtp62=False&crfmhlock=False&dsfees=False"
    "&tn=Children%27s%20Residential%20Facilities&z=&mhc=False&crfd=False"
    "&cdtnrt=False&sils61=False&s=All&afcaost=False&t=40&cdtcwct=False"
    "&dsfdth=False&n=&l="
)

# Set these as env vars or in your .env file
API_BASE = os.environ.get("INSPECTIONS_API_BASE", "")
API_KEY  = os.environ.get("INSPECTIONS_API_KEY", "")
if not API_BASE:
    API_BASE = os.environ.get("KOP_API_BASE", "")
if not API_KEY:
    API_KEY = os.environ.get("KOP_API_KEY", "")

OUTPUT_JSON = str(Path(__file__).resolve().parent.parent / "js" / "data" / "mn_reports.json")

# Delay between facility detail requests (seconds) — be polite
REQUEST_DELAY = 1.5

# Optional: limit number of facilities for quick validation runs.
# Example (PowerShell): $env:MN_LIMIT_IDS='5'; python scripts/mn_scraper.py
MN_LIMIT_IDS = int(os.environ.get("MN_LIMIT_IDS", "0") or 0)

# Optional: override detected licenses when Results page is blocked.
# Example (PowerShell): $env:MN_LICENSE_IDS='1074484,1074485'
MANUAL_LICENSE_IDS = [
    v.strip()
    for v in os.environ.get("MN_LICENSE_IDS", "").split(",")
    if v.strip()
]

# Optional: load license IDs from a CSV export (e.g., DHS Licensing Lookup CSV).
# Expected column names include "License Number" and optionally "Name of Program".
# Example (PowerShell): $env:MN_LICENSE_CSV='C:\Users\daniu\Downloads\Licensing_Lookup_Results_ Apr.15.2026.csv'
MN_LICENSE_CSV = os.environ.get("MN_LICENSE_CSV", "").strip()

# Optional: skip hitting Results.aspx when using CSV/manual IDs.
MN_SKIP_RESULTS_LOOKUP = os.environ.get("MN_SKIP_RESULTS_LOOKUP", "1").strip().lower() not in {"0", "false", "no"}

# Log discovered document links for each facility (useful while validating parsers)
MN_LOG_DOC_LINKS = os.environ.get("MN_LOG_DOC_LINKS", "1").strip().lower() not in {"0", "false", "no"}
MN_LOG_DOC_LINKS_MAX = int(os.environ.get("MN_LOG_DOC_LINKS_MAX", "50") or 50)

# Set to True to run browser visibly (useful for debugging)
HEADLESS = os.environ.get("MN_HEADLESS", "1").strip().lower() not in {"0", "false", "no"}

# Max seconds to wait for manual challenge completion when HEADLESS=0
MN_CHALLENGE_WAIT_SECONDS = int(os.environ.get("MN_CHALLENGE_WAIT_SECONDS", "180") or 180)

# Prefer persistent browser context for interactive/manual challenge runs.
MN_USE_PERSISTENT_CONTEXT = os.environ.get("MN_USE_PERSISTENT_CONTEXT", "1").strip().lower() not in {"0", "false", "no"}
MN_BROWSER_CHANNEL = os.environ.get("MN_BROWSER_CHANNEL", "chrome").strip() or "chrome"
MN_USER_DATA_DIR = os.environ.get(
    "MN_USER_DATA_DIR",
    str((Path(__file__).resolve().parent.parent / ".tmp" / "mn-playwright-profile")),
)

# ── HELPERS ───────────────────────────────────────────────────────────────────

def clean(text):
    """Strip and collapse whitespace."""
    if not text:
        return ""
    return re.sub(r"\s+", " ", str(text).replace("\xa0", " ")).strip()


def parse_license_ids_from_page(html):
    """
    Extract all license IDs from the Results page.
    The page uses onclick handlers like: getIT('Active', '1074484', '1', '40', '0')
    """
    ids = re.findall(r"getIT\([^)]*'(\d+)'[^)]*\)", html)
    # deduplicate while preserving order
    seen = set()
    unique = []
    for i in ids:
        if i not in seen:
            seen.add(i)
            unique.append(i)
    return unique


def extract_table_rows(page_text):
    """Pull all <td> text from a table row for quick field extraction."""
    return re.findall(r"<td[^>]*>(.*?)</td>", page_text, re.IGNORECASE | re.DOTALL)


def strip_html(text):
    """Remove HTML tags."""
    return re.sub(r"<[^>]+>", " ", text or "").strip()


def build_label_value_map_from_text(body_text):
    """Build a loose key/value map from body text lines to improve fallback extraction."""
    pairs = {}
    if not body_text:
        return pairs

    raw_lines = [clean(line) for line in re.split(r"\r?\n", body_text)]
    lines = [line for line in raw_lines if line and line != "|"]

    for line in lines:
        if ":" in line:
            left, right = line.split(":", 1)
            key = clean(left).lower().rstrip("-")
            val = clean(right)
            if key and val and key not in pairs:
                pairs[key] = val

    for idx in range(len(lines) - 1):
        left = lines[idx]
        right = lines[idx + 1]
        if ":" in left:
            continue
        if len(left) > 60 or len(right) > 140:
            continue
        if re.fullmatch(r"[A-Za-z][A-Za-z\s()/#&\-]{2,}", left):
            key = clean(left).lower().rstrip(":-")
            if key and key not in pairs:
                pairs[key] = clean(right)

    return pairs


def page_is_bot_challenge(page_url, html):
    """Detect common Radware challenge pages that block scraping."""
    host = urlparse(page_url or "").netloc.lower()
    if "validate.perfdrive.com" in host:
        return True
    sample = (html or "")[:5000].lower()
    return "radware captcha page" in sample or "captcha.perfdrive.com" in sample


def extract_doc_url_from_onclick(onclick_text):
    """Extract likely document URLs from inline onclick handlers."""
    if not onclick_text:
        return ""

    # Direct absolute URL in onclick JS
    abs_match = re.search(r"(https?://[^'\"\s)]+)", onclick_text, re.IGNORECASE)
    if abs_match:
        return abs_match.group(1)

    # Relative DHS content service path in onclick JS
    rel_match = re.search(r"(/main/idcplg\?[^'\"\s)]+)", onclick_text, re.IGNORECASE)
    if rel_match:
        return urljoin("https://www.dhs.state.mn.us", rel_match.group(1))

    # dDocName token only (common in script wrappers)
    doc_match = re.search(r"\b(LLO_[A-Za-z0-9_-]+)\b", onclick_text)
    if doc_match:
        return (
            "https://www.dhs.state.mn.us/main/idcplg?"
            "IdcService=GET_DYNAMIC_CONVERSION&RevisionSelectionMethod=LatestReleased"
            f"&dDocName={doc_match.group(1)}"
        )

    return ""


def is_document_like_url(href):
    """Detect URLs that likely point to documents, not just .pdf links."""
    if not href:
        return False
    low = href.lower()
    return any(
        token in low
        for token in [
            ".pdf",
            "pdf",
            "idcplg",
            "ddocname=",
            "get_dynamic_conversion",
        ]
    )


def canonicalize_dhs_doc_url(url):
    """Normalize DHS document URLs so duplicate links collapse reliably."""
    if not url:
        return ""
    normalized = clean(url)

    if normalized.startswith("/main/idcplg"):
        normalized = urljoin("https://www.dhs.state.mn.us", normalized)

    parsed = urlparse(normalized)
    if "dhs.state.mn.us" in parsed.netloc.lower() and "/main/idcplg" in parsed.path.lower() and parsed.query:
        # Keep stable key query params first; preserve others after.
        from urllib.parse import parse_qsl, urlencode

        pairs = parse_qsl(parsed.query, keep_blank_values=True)
        key_order = ["IdcService", "RevisionSelectionMethod", "dDocName"]
        keyed = {k: v for k, v in pairs}
        ordered = []
        for k in key_order:
            if k in keyed:
                ordered.append((k, keyed[k]))
        for k, v in pairs:
            if k not in key_order:
                ordered.append((k, v))
        normalized = f"{parsed.scheme}://{parsed.netloc}{parsed.path}?{urlencode(ordered)}"

    return normalized


def parse_dhs_doc_links_from_html(html):
    """Extract DHS document links from raw HTML/script text."""
    found = []
    if not html:
        return found

    # Absolute URLs already present in markup/script text
    abs_matches = re.findall(
        r"https?://www\.dhs\.state\.mn\.us/main/idcplg\?[^\"'\s<>]+",
        html,
        re.IGNORECASE,
    )
    found.extend(abs_matches)

    # Relative paths found in JS/attributes
    rel_matches = re.findall(r"/main/idcplg\?[^\"'\s<>]+", html, re.IGNORECASE)
    found.extend(urljoin("https://www.dhs.state.mn.us", m) for m in rel_matches)

    # Bare LLO token references in JS payloads
    token_matches = re.findall(r"\b(LLO_[A-Za-z0-9_-]+)\b", html)
    for token in token_matches:
        found.append(
            "https://www.dhs.state.mn.us/main/idcplg?"
            "IdcService=GET_DYNAMIC_CONVERSION&RevisionSelectionMethod=LatestReleased"
            f"&dDocName={token}"
        )

    dedup = []
    seen = set()
    for url in found:
        canon = canonicalize_dhs_doc_url(url)
        if canon and canon not in seen:
            seen.add(canon)
            dedup.append(canon)
    return dedup


def load_license_ids_from_csv(csv_path):
    """Load unique license IDs (and optional name map) from CSV export file."""
    ids = []
    name_map = {}

    if not csv_path:
        return ids, name_map

    try:
        with open(csv_path, "r", encoding="utf-8-sig", newline="") as f:
            reader = csv.DictReader(f)
            for row in reader:
                raw_license = clean(row.get("License Number") or row.get("LicenseNumber") or "")
                if not raw_license:
                    continue

                lid = re.sub(r"\D", "", raw_license)
                if not lid:
                    continue

                if lid not in name_map:
                    program_name = clean(row.get("Name of Program") or row.get("Program Name") or "")
                    if program_name:
                        name_map[lid] = program_name

                ids.append(lid)
    except FileNotFoundError:
        print(f"  ⚠️  CSV file not found: {csv_path}")
    except Exception as e:
        print(f"  ⚠️  Failed reading MN_LICENSE_CSV '{csv_path}': {e}")

    seen = set()
    unique = []
    for lid in ids:
        if lid not in seen:
            seen.add(lid)
            unique.append(lid)

    return unique, name_map


async def wait_for_challenge_clear(page, context_label="page"):
    """Wait for manual challenge completion when running non-headless."""
    if HEADLESS:
        return False

    checks = max(1, MN_CHALLENGE_WAIT_SECONDS // 5)
    print(f"  Waiting up to {MN_CHALLENGE_WAIT_SECONDS} seconds for manual challenge completion on {context_label}...")
    for _ in range(checks):
        await page.wait_for_timeout(5000)
        content = await page.content()
        title = (await page.title()).lower()
        if "captcha" not in title and not page_is_bot_challenge(page.url, content):
            print(f"  ✅ Challenge appears cleared on {context_label}.")
            return True
    return False

# ── MAIN SCRAPER ──────────────────────────────────────────────────────────────

async def scrape_facility_detail(page, license_id):
    """
    Visit a detail page and extract:
      - Facility info (name, address, license status, capacity, dates, etc.)
      - Documents / inspection records (PDFs, report dates, compliance docs)
    """
    url = f"https://licensinglookup.dhs.state.mn.us/Details.aspx?l={license_id}"
    try:
        await page.goto(url, wait_until="domcontentloaded", timeout=30000)
        await page.wait_for_timeout(1000)
    except Exception as e:
        print(f"  ⚠️  Initial load failed for {url}: {e}")
        try:
            # Some anti-bot responses abort full DOM-ready navigation; capture
            # partial/challenge pages so manual solve + parsing can continue.
            await page.goto(url, wait_until="commit", timeout=30000)
            await page.wait_for_timeout(1500)
            print(f"  ↪️  Continued with commit-level navigation for license {license_id}")
        except Exception as e2:
            print(f"  ❌ Failed to load {url} after retry: {e2}")
            return None

    # Check for captcha / challenge and allow manual solve when non-headless
    title = await page.title()
    content = await page.content()
    is_captcha = "captcha" in title.lower() or "apologize" in content.lower()[:500]

    if is_captcha or page_is_bot_challenge(page.url, content):
        print(f"  🚫 Challenge hit on license {license_id}")
        cleared = await wait_for_challenge_clear(page, f"detail page {license_id}")
        if not cleared:
            blocked_links = parse_dhs_doc_links_from_html(content)
            if MN_LOG_DOC_LINKS:
                print(f"  🔗 Parsed {len(blocked_links)} document links from challenge page for license {license_id}")
                for idx, parsed_url in enumerate(blocked_links[:MN_LOG_DOC_LINKS_MAX], 1):
                    print(f"     [{idx}] {parsed_url}")
            print(f"  ⚠️  Challenge not cleared in time for license {license_id}")
            return None
        content = await page.content()
        if page_is_bot_challenge(page.url, content):
            blocked_links = parse_dhs_doc_links_from_html(content)
            if MN_LOG_DOC_LINKS:
                print(f"  🔗 Parsed {len(blocked_links)} document links while challenge remained active for license {license_id}")
                for idx, parsed_url in enumerate(blocked_links[:MN_LOG_DOC_LINKS_MAX], 1):
                    print(f"     [{idx}] {parsed_url}")
            print(f"  ⚠️  Bot protection still active for license {license_id}")
            return None

    # ── Facility Info ──────────────────────────────────────────────────────────

    # Facility name — usually in an h1 or prominent header
    name = ""
    for sel in ["h1", "h2", ".facilityName", "#facilityName", ".pageHeader"]:
        try:
            el = page.locator(sel).first
            if await el.count() > 0:
                txt = clean(await el.inner_text())
                if txt and "search" not in txt.lower():
                    name = txt
                    break
        except Exception:
            pass

    # Address block
    address = ""
    for sel in [".address", "#address", "span[id*='ddress']", "span[id*='ocation']"]:
        try:
            el = page.locator(sel).first
            if await el.count() > 0:
                address = clean(await el.inner_text())
                break
        except Exception:
            pass

    # Grab all visible text from the main content area for fallback parsing
    try:
        body_text = clean(await page.locator("body").inner_text())
    except Exception:
        body_text = ""

    # Build structured key/value hints from DOM table rows.
    dom_pairs = {}
    try:
        dom_kv = await page.eval_on_selector_all(
            "table tr",
            """rows => rows.map(row => {
                const cells = Array.from(row.querySelectorAll('th, td')).map(c => (c.innerText || '').trim()).filter(Boolean);
                if (cells.length < 2) return null;
                return [cells[0], cells[1]];
            }).filter(Boolean)"""
        )
        for pair in dom_kv:
            if not pair or len(pair) < 2:
                continue
            key = clean(pair[0]).lower().rstrip(":-")
            val = clean(pair[1])
            if key and val and key not in dom_pairs:
                dom_pairs[key] = val
    except Exception:
        pass

    text_pairs = build_label_value_map_from_text(body_text)

    def pick_from_pairs(patterns):
        for pattern in patterns:
            rx = re.compile(pattern, re.IGNORECASE)
            for key, val in dom_pairs.items():
                if rx.search(key) and val:
                    return val
            for key, val in text_pairs.items():
                if rx.search(key) and val:
                    return val
        return ""

    # Try to pull structured fields from label/value rows
    def scan_body(label):
        m = re.search(rf"{label}\s*[:\-]?\s*(.{{1,200}}?)(?:\n|$)", body_text, re.IGNORECASE)
        return clean(m.group(1)) if m else ""

    license_status = (
        pick_from_pairs([r"(?:license\s+)?status", r"current\s+status", r"status"])
        or scan_body(r"(?:License\s+)?Status")
    )
    license_number = (
        pick_from_pairs([r"license\s+number", r"license\s*#", r"number"])
        or scan_body(r"License\s+Number")
    )
    license_exp = (
        pick_from_pairs([r"(?:license\s+)?expir(?:ation|es?)", r"expir(?:ation|es?)\s+date"])
        or scan_body(r"(?:License\s+)?Expir(?:ation|es?)")
    )
    capacity = (
        pick_from_pairs([r"(?:licensed\s+)?capacity", r"bed\s+capacity", r"capacity"])
        or scan_body(r"(?:Licensed\s+)?Capacity")
    )
    phone = (
        pick_from_pairs([r"phone", r"telephone", r"contact\s+phone"])
        or scan_body(r"Phone")
    )
    county = (
        pick_from_pairs([r"county"])
        or scan_body(r"County")
    )
    program_type = (
        pick_from_pairs([r"(?:program|license)\s+type", r"service\s+type", r"facility\s+type"])
        or scan_body(r"(?:Program|License)\s+Type")
    )
    director = (
        pick_from_pairs([r"(?:executive\s+)?director", r"administrator", r"contact"])
        or scan_body(r"(?:Executive\s+)?Director|Administrator|Contact")
    )

    if not name:
        name = (
            pick_from_pairs([r"(?:facility|provider|program)\s+name", r"name"])
            or scan_body(r"(?:Facility|Provider|Program)\s+Name")
            or f"License {license_id}"
        )

    if not address:
        address = (
            pick_from_pairs([r"(?:physical\s+)?address", r"location", r"street"])
            or scan_body(r"(?:Physical\s+)?Address")
        )

    # ── Documents / Inspection Records ────────────────────────────────────────

    reports = []

    # Look for all links (PDFs, report pages)
    links = await page.eval_on_selector_all(
        "a[href], a[onclick], [onclick*='idcplg'], [onclick*='dDocName'], [onclick*='LLO_']",
        """els => els.map(e => {
            const rawHref = e.getAttribute('href') || '';
            let href = '';
            if (rawHref) {
                try {
                    href = new URL(rawHref, location.href).href;
                } catch {
                    href = rawHref;
                }
            }
            return {
                href,
                onclick: e.getAttribute('onclick') || '',
                text: (e.textContent || '').trim(),
            };
        })"""
    )

    # Capture document links that are embedded in licensing-actions table rows.
    # Some rows expose links via row/cell onclick handlers or nested format links.
    table_doc_candidates = await page.eval_on_selector_all(
        "table tr",
        """rows => {
            const dateRx = /\\b\\d{1,2}\\/\\d{1,2}\\/\\d{2,4}\\b/;
            const out = [];

            for (const row of rows) {
                const cells = Array.from(row.querySelectorAll('th, td'));
                if (!cells.length) continue;

                const cellTexts = cells.map(c => (c.innerText || '').trim()).filter(Boolean);
                if (!cellTexts.length) continue;

                const rowText = cellTexts.join(' | ');
                const rowLower = rowText.toLowerCase();

                // Skip obvious headers/labels
                if (rowLower.includes('document type') && rowLower.includes('date issued')) continue;
                if (rowLower.includes('licensing actions and maltreatment')) continue;

                const looksLikeDocRow = (
                    rowLower.includes('correction order') ||
                    rowLower.includes('maltreatment') ||
                    rowLower.includes('investigation memorandum') ||
                    rowLower.includes('notice') ||
                    rowLower.includes('order') ||
                    rowLower.includes('violation') ||
                    rowLower.includes('inspection') ||
                    rowLower.includes('compliance') ||
                    dateRx.test(rowText)
                );

                if (!looksLikeDocRow) continue;

                const anchors = Array.from(row.querySelectorAll('a'));
                const rowOnclick = row.getAttribute('onclick') || '';

                if (anchors.length) {
                    for (const a of anchors) {
                        const rawHref = a.getAttribute('href') || '';
                        let href = '';
                        if (rawHref) {
                            try {
                                href = new URL(rawHref, location.href).href;
                            } catch {
                                href = rawHref;
                            }
                        }

                        out.push({
                            href,
                            onclick: a.getAttribute('onclick') || rowOnclick,
                            text: (a.innerText || '').trim() || rowText,
                            rowText,
                        });
                    }
                } else {
                    out.push({
                        href: '',
                        onclick: rowOnclick,
                        text: rowText,
                        rowText,
                    });
                }
            }

            return out;
        }"""
    )
    for cand in table_doc_candidates:
        links.append({
            "href": clean(cand.get("href", "")),
            "onclick": cand.get("onclick", "") or "",
            "text": clean(cand.get("text", "") or cand.get("rowText", "")),
        })

    # Include document links only present in inline scripts/text.
    html_doc_links = parse_dhs_doc_links_from_html(content)
    for html_url in html_doc_links:
        links.append({
            "href": html_url,
            "onclick": "",
            "text": "DHS document link (parsed from HTML)",
        })

    doc_index = 0
    seen_report_urls = set()
    parsed_doc_urls = []
    for link in links:
        href = clean(link.get("href", ""))
        onclick = link.get("onclick", "") or ""
        label = clean(link.get("text", ""))

        if (not href or href.startswith("javascript") or href == "#") and onclick:
            onclick_url = extract_doc_url_from_onclick(onclick)
            if onclick_url:
                href = onclick_url

        # Skip navigation / irrelevant links
        if not href or href.startswith("javascript") or href == "#":
            continue
        if any(skip in href.lower() for skip in ["css", "images", "logout", "search", "home"]):
            continue

        href = canonicalize_dhs_doc_url(href)

        is_pdf = href.lower().endswith(".pdf") or "pdf" in href.lower()
        is_doc = any(kw in label.lower() for kw in [
            "report", "inspection", "compliance", "correction", "order",
            "notice", "violation", "maltreatment", "plan", "renewal"
        ])
        is_doc_url = is_document_like_url(href)

        if not (is_pdf or is_doc or is_doc_url):
            continue

        if href in seen_report_urls:
            continue
        seen_report_urls.add(href)
        parsed_doc_urls.append(href)

        doc_index += 1
        report_id = f"MN-{license_id}-{doc_index:03d}"

        # Try to pull a date from the link text or nearby text
        date_match = re.search(
            r"(\d{1,2}/\d{1,2}/\d{2,4}|\d{4}-\d{2}-\d{2}|"
            r"(?:Jan|Feb|Mar|Apr|May|Jun|Jul|Aug|Sep|Oct|Nov|Dec)[a-z]*\.?\s+\d{1,2},?\s+\d{4})",
            label, re.IGNORECASE
        )
        report_date = clean(date_match.group(1)) if date_match else ""

        # Classify document type from label
        doc_type = "Document"
        for kw, dt in [
            ("inspection", "Inspection Report"),
            ("compliance", "Compliance Report"),
            ("correction", "Correction Order"),
            ("maltreatment", "Maltreatment Finding"),
            ("violation", "Notice of Violation"),
            ("notice", "Notice"),
            ("plan", "Plan of Correction"),
            ("renewal", "License Renewal"),
            ("order", "Order"),
        ]:
            if kw in label.lower() or kw in href.lower():
                doc_type = dt
                break

        # Detect potential violations
        is_violation = any(kw in label.lower() or kw in href.lower() for kw in [
            "violation", "correction", "maltreatment", "complaint", "abuse",
            "neglect", "order", "citation"
        ])

        tags = []
        if is_violation:
            tags.append("violation")
        if "maltreatment" in label.lower():
            tags.append("maltreatment")
        if "correction" in label.lower():
            tags.append("correction order")

        reports.append({
            "report_id": report_id,
            "report_date": report_date,
            "report_url": href,
            "raw_content": "",
            "content_length": 0,
            "is_structured": False,
            "summary": label,
            "categories": {
                "doc_type": doc_type,
                "tags": tags,
                "pdf_url": href if is_pdf else "",
                "doc_page_url": href if not is_pdf else "",
            }
        })

    if MN_LOG_DOC_LINKS:
        print(f"  🔗 Parsed {len(parsed_doc_urls)} document links for license {license_id}")
        for idx, parsed_url in enumerate(parsed_doc_urls[:MN_LOG_DOC_LINKS_MAX], 1):
            print(f"     [{idx}] {parsed_url}")
        if len(parsed_doc_urls) > MN_LOG_DOC_LINKS_MAX:
            print(f"     ... {len(parsed_doc_urls) - MN_LOG_DOC_LINKS_MAX} more links omitted")

    # Also look for inline inspection records in tables (some MN pages show
    # dates/outcomes in HTML tables rather than as link text)
    try:
        rows = await page.eval_on_selector_all(
            "table tr",
            """rows => rows.map(row => {
                const cells = Array.from(row.querySelectorAll('td, th'));
                return cells.map(c => c.innerText.trim());
            })"""
        )
        for row in rows:
            if len(row) < 2:
                continue
            row_text = " | ".join(row).lower()
            if any(kw in row_text for kw in ["inspection", "compliance", "visit", "report", "order"]):
                # Try to extract date from first date-shaped cell
                date_val = ""
                for cell in row:
                    dm = re.search(r"\d{1,2}/\d{1,2}/\d{2,4}", cell)
                    if dm:
                        date_val = dm.group(0)
                        break

                # Skip if we already captured this as a link
                row_summary = " | ".join(row)
                if any(r["summary"] == row_summary for r in reports):
                    continue

                doc_index += 1
                reports.append({
                    "report_id": f"MN-{license_id}-{doc_index:03d}",
                    "report_date": date_val,
                    "report_url": "",
                    "raw_content": " | ".join(row),
                    "content_length": len(" | ".join(row)),
                    "is_structured": True,
                    "summary": row_summary,
                    "categories": {
                        "doc_type": "Inspection Record",
                        "tags": [],
                        "pdf_url": "",
                        "doc_page_url": url,
                    }
                })
    except Exception:
        pass

    csv_name_fallback = CSV_LICENSE_NAME_MAP.get(str(license_id), "")

    facility = {
        "facility_info": {
            "facility_name": name or csv_name_fallback or f"License {license_id}",
            "full_address": address,
            "phone": phone,
            "program_category": program_type or "Children's Residential Facility",
            "program_name": license_number or str(license_id),
            "executive_director": director,
            "bed_capacity": capacity,
            "license_exp_date": license_exp,
            "relicense_visit_date": "",
            "action": license_status,
        },
        "reports": reports,
        "_meta": {
            "license_id": license_id,
            "county": county,
            "license_number": license_number,
            "source_url": url,
            "parsed_doc_links": parsed_doc_urls,
        }
    }

    doc_count = len(reports)
    print(f"  ✅ {name or license_id} — {doc_count} document{'s' if doc_count != 1 else ''}")
    return facility


async def get_all_license_ids(page):
    """Load the Results page and extract all license IDs."""
    print("🔍 Loading Results page to collect license IDs...")
    try:
        await page.goto(RESULTS_URL, wait_until="domcontentloaded", timeout=30000)
        await page.wait_for_timeout(2000)
    except Exception as e:
        print(f"  ⚠️  Results page load failed: {e}")
        print("  Proceeding without auto-detected IDs (manual MN_LICENSE_IDS may still be used).")
        return []

    content = await page.content()
    if page_is_bot_challenge(page.url, content):
        print("  🚫 Bot protection challenge detected on Results page.")
        await wait_for_challenge_clear(page, "Results page")
        content = await page.content()

    ids = parse_license_ids_from_page(content)

    # Also try to get from the onclick attributes directly
    onclick_ids = await page.eval_on_selector_all(
        "tr[onclick], td[onclick]",
        "els => els.map(e => e.getAttribute('onclick'))"
    )
    for oc in onclick_ids:
        if oc:
            m = re.findall(r"'(\d{5,})'", oc)
            ids.extend(m)

    # Deduplicate
    seen = set()
    unique = []
    for i in ids:
        if i not in seen:
            seen.add(i)
            unique.append(i)

    print(f"  Found {len(unique)} facility license IDs")
    return unique


async def run():
    timestamp = datetime.now(timezone.utc).isoformat()
    all_facilities = []
    run_issue = ""

    output_dir = os.path.dirname(OUTPUT_JSON)
    os.makedirs(output_dir, exist_ok=True)
    print(f"💾 Output target: {OUTPUT_JSON}")

    if not API_BASE or not API_KEY:
        print("⚠️  API credentials are incomplete; scraper will still write local JSON fallback.")
        print("   Set INSPECTIONS_API_BASE and INSPECTIONS_API_KEY (or legacy KOP_API_BASE/KOP_API_KEY) to enable POST.")

    if MANUAL_LICENSE_IDS:
        print(f"🔧 Manual MN_LICENSE_IDS provided: {len(MANUAL_LICENSE_IDS)} IDs")

    if MN_LICENSE_CSV:
        print(f"📄 MN_LICENSE_CSV provided: {MN_LICENSE_CSV}")

    async with async_playwright() as p:
        browser = None
        if not HEADLESS and MN_USE_PERSISTENT_CONTEXT:
            os.makedirs(MN_USER_DATA_DIR, exist_ok=True)
            print(f"🌐 Launching persistent browser context: {MN_USER_DATA_DIR}")
            context = await p.chromium.launch_persistent_context(
                user_data_dir=MN_USER_DATA_DIR,
                channel=MN_BROWSER_CHANNEL,
                headless=False,
                args=[
                    "--disable-blink-features=AutomationControlled",
                    "--disable-dev-shm-usage",
                ],
                user_agent=(
                    "Mozilla/5.0 (Windows NT 10.0; Win64; x64) "
                    "AppleWebKit/537.36 (KHTML, like Gecko) "
                    "Chrome/124.0.0.0 Safari/537.36"
                ),
                viewport={"width": 1280, "height": 900},
            )
            page = context.pages[0] if context.pages else await context.new_page()
        else:
            browser = await p.chromium.launch(
                headless=HEADLESS,
                args=[
                    "--disable-blink-features=AutomationControlled",
                    "--disable-dev-shm-usage",
                ],
            )
            context = await browser.new_context(
                user_agent=(
                    "Mozilla/5.0 (Windows NT 10.0; Win64; x64) "
                    "AppleWebKit/537.36 (KHTML, like Gecko) "
                    "Chrome/124.0.0.0 Safari/537.36"
                ),
                viewport={"width": 1280, "height": 900},
            )
            page = await context.new_page()

        await page.add_init_script(
            "Object.defineProperty(navigator, 'webdriver', {get: () => undefined});"
        )

        # Step 1: Build license list from preferred sources.
        csv_ids, csv_name_map = load_license_ids_from_csv(MN_LICENSE_CSV)
        global CSV_LICENSE_NAME_MAP
        CSV_LICENSE_NAME_MAP = csv_name_map

        license_ids = []
        if csv_ids:
            print(f"  Loaded {len(csv_ids)} license IDs from CSV.")
            license_ids = csv_ids

        if not license_ids and MANUAL_LICENSE_IDS:
            print("  Using MN_LICENSE_IDS override.")
            license_ids = MANUAL_LICENSE_IDS

        if not license_ids or not MN_SKIP_RESULTS_LOOKUP:
            # Load results page to get session cookies + license IDs when needed.
            discovered_ids = await get_all_license_ids(page)
            if discovered_ids:
                if license_ids:
                    merged = license_ids + discovered_ids
                    dedup = []
                    seen = set()
                    for lid in merged:
                        if lid not in seen:
                            seen.add(lid)
                            dedup.append(lid)
                    license_ids = dedup
                else:
                    license_ids = discovered_ids

        if not license_ids and MANUAL_LICENSE_IDS:
            print("  Using MN_LICENSE_IDS override because no IDs were auto-detected.")
            license_ids = MANUAL_LICENSE_IDS

        if not license_ids:
            print("❌ No license IDs found — likely blocked by bot protection or source page changes.")
            print("   Try: set MN_HEADLESS=0 and complete challenge in-browser, or provide MN_LICENSE_IDS.")
            run_issue = "no_license_ids_detected"

        if MN_LIMIT_IDS > 0 and license_ids:
            license_ids = license_ids[:MN_LIMIT_IDS]
            print(f"  Limiting run to first {len(license_ids)} license IDs (MN_LIMIT_IDS={MN_LIMIT_IDS})")

        # Step 2: Scrape each facility
        print(f"\n🏥 Scraping {len(license_ids)} facilities...\n")
        failed = []

        for idx, lid in enumerate(license_ids, 1):
            print(f"[{idx}/{len(license_ids)}] License {lid}")
            facility = await scrape_facility_detail(page, lid)

            if facility:
                all_facilities.append(facility)
            else:
                failed.append(lid)

            await page.wait_for_timeout(int(REQUEST_DELAY * 1000))

        if browser is not None:
            await browser.close()
        else:
            await context.close()

    print(f"\n✅ Scraped {len(all_facilities)} facilities ({len(failed)} failed)")
    if failed:
        print(f"  Failed IDs: {', '.join(failed)}")

    # ── Output ────────────────────────────────────────────────────────────────

    output = {
        "facilities": all_facilities,
        "scraped_timestamp": timestamp,
        "scraping_notes": {
            "total_facilities": len(all_facilities),
            "failed_count": len(failed),
            "failed_ids": failed,
            "run_issue": run_issue,
            "source": "MN DHS Licensing Information Lookup",
            "source_url": RESULTS_URL,
        }
    }

    # Save static JSON fallback
    with open(OUTPUT_JSON, "w", encoding="utf-8") as f:
        json.dump(output, f, indent=2, ensure_ascii=False)
    print(f"\n💾 Saved {OUTPUT_JSON}")

    # POST to inspections-write.php if configured
    if API_BASE and API_KEY:
        write_url = f"{API_BASE}/api/inspections-write.php"
        payload = {
            "api_key": API_KEY,
            "state": "MN",
            "scraped_timestamp": timestamp,
            "facilities": all_facilities,
        }
        print(f"\n📡 POSTing to {write_url}...")
        try:
            resp = requests.post(write_url, json=payload, timeout=60, verify=False)
            print(f"  Response {resp.status_code}: {resp.text[:300]}")
        except Exception as e:
            print(f"  ❌ POST failed: {e}")
    else:
        print("\n⚠️  Skipping POST to inspections-write.php (missing API base or key).")
        print("   Local fallback JSON was written successfully.")
        print("   Set INSPECTIONS_API_BASE and INSPECTIONS_API_KEY, then re-run to publish to DB.")


if __name__ == "__main__":
    asyncio.run(run())
