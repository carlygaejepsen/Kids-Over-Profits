from pathlib import Path
from PyPDF2 import PdfReader
import re
import shutil

# === CONFIG ===
INPUT_DIR = Path(r"C:\Users\daniu\My Drive\Kids Over Profits\NATSAP\Directories\Single Profiles\Extract natsap 2020-2021_Directory_FINAL\split_output")  # set this
OUTPUT_DIR = INPUT_DIR / "renamed"
OUTPUT_DIR.mkdir(exist_ok=True)

# Words that often appear in facility names
FACILITY_KEYWORDS = {
    "school","academy","center","centre","residential","treatment","therapeutic","program",
    "institute","home","house","ranch","boys","girls","youth","services","care","boarding"
}

# Lines to ignore as section headers/labels
SECTION_PREFIXES = (
    "contact", "program outline", "accreditation", "licensure", "professional affiliations",
    "website:", "email:", "phone:", "fax:", "address:", "airport:", "gender:", "ages:",
    "grades:", "enrollment:", "duration:", "founded:", "natsap member since:"
)

INVALID_FS_CHARS = re.compile(r'[<>:"/\\|?*]+')

def sanitize_filename(text: str) -> str:
    text = INVALID_FS_CHARS.sub("", text)
    text = re.sub(r"\s+", " ", text).strip()
    return text

def clean_lines(text: str) -> list[str]:
    """Remove page numbers and boilerplate; keep meaningful lines."""
    lines = (text or "").splitlines()
    out = []
    for line in lines:
        raw = line.rstrip()
        if not raw.strip():
            out.append("")  # preserve blank markers for relative position
            continue
        # Drop bare page numbers like "177" or "Page x of y"
        if re.fullmatch(r"\s*\d+\s*", raw):
            continue
        if re.search(r"\bPage\s*\d+\s*of\s*\d+\b", raw, flags=re.IGNORECASE):
            continue
        if re.search(r"Guiding the Way", raw, flags=re.IGNORECASE):
            continue
        out.append(raw)
    return out

def find_header_index(lines: list[str]) -> int:
    for i, l in enumerate(lines[:40]):  # only near the top
        if re.search(r"\bNATSAP\b", l, re.IGNORECASE) and re.search(r"\bDIRECTORY\b", l, re.IGNORECASE):
            return i
    return -1

def extract_year(lines: list[str]) -> str:
    top = "\n".join(lines[:40])
    # Prefer a range like 2020-2021 (allow optional spaces around hyphen)
    m = re.search(r"\b(20\d{2})\s*-\s*(20\d{2})\b", top)
    if m:
        return f"{m.group(1)}-{m.group(2)}"
    # Otherwise a single 20xx near the header
    m = re.search(r"\b(20\d{2})\b", top)
    return m.group(1) if m else "NOYEAR"

def titlecase_score(s: str) -> int:
    # Count words that look Title‑cased
    words = [w for w in re.findall(r"[A-Za-z][A-Za-z'\-]+", s)]
    if not words:
        return 0
    titled = sum(1 for w in words if w[0].isupper())
    return int(6 * (titled / max(1, len(words))))  # 0..6

def has_facility_keyword(s: str) -> bool:
    low = s.lower()
    return any(k in low for k in FACILITY_KEYWORDS)

def looks_like_city_state(s: str) -> bool:
    # e.g., "Windsor, NH 03244" or "Windsor, NH"
    return bool(re.search(r"\b[A-Z][a-z]+,\s*[A-Z]{2}\b", s)) or bool(re.search(r"\b\d{5}(?:-\d{4})?\b", s))

def disqualify_prefix(s: str) -> bool:
    low = s.lower().lstrip()
    return any(low.startswith(p) for p in SECTION_PREFIXES)

def line_alpha_ratio(s: str) -> float:
    a = sum(c.isalpha() for c in s)
    t = sum(c.isalnum() for c in s)
    return (a / t) if t else 0.0

def score_candidate(s: str, distance_from_header: int) -> int:
    if disqualify_prefix(s):
        return -10
    if looks_like_city_state(s):
        return -5
    if re.search(r"\d", s):  # names rarely include digits
        return -3
    score = 0
    score += max(0, 6 - distance_from_header)  # closer to header is better
    score += titlecase_score(s)
    if has_facility_keyword(s):
        score += 5
    # Reasonable length
    words = len(re.findall(r"[A-Za-z][A-Za-z'\-]+", s))
    if 1 <= words <= 8:
        score += 2
    elif words > 14:
        score -= 2
    # Penalize shouty ALLCAPS unless it's an acronym with spaces
    if s.isupper() and words > 1:
        score -= 3
    return score

def pick_program_name(lines: list[str], header_idx: int) -> str:
    # Search window: next ~15 visible lines after header until a section header appears
    start = header_idx + 1 if header_idx >= 0 else 0
    window = []
    for i, s in enumerate(lines[start:start+20], start=start):
        if not s.strip():
            continue
        if disqualify_prefix(s):
            break
        window.append((i, s))

    # Score candidates
    best = ("", float("-inf"))
    for i, s in window:
        # strip parenthetical descriptor for scoring, but keep original to trim later
        display = re.sub(r"\s*\(.*?\)\s*$", "", s).strip()
        # Skip lines with low alpha ratio (protects against stray numerics)
        if line_alpha_ratio(display) < 0.6:
            continue
        score = score_candidate(display, i - (header_idx if header_idx >= 0 else 0))
        if score > best[1]:
            best = (display, score)

    if best[1] == float("-inf"):
        # Fallback: first non-empty, non‑disqualified line near top
        for s in lines[:30]:
            display = re.sub(r"\s*\(.*?\)\s*$", "", s).strip()
            if not display or disqualify_prefix(display):
                continue
            if line_alpha_ratio(display) < 0.6:
                continue
            return display
        return "UNKNOWN"
    return best[0]

# === MAIN ===
for pdf_path in INPUT_DIR.glob("*.pdf"):
    reader = PdfReader(str(pdf_path))
    # Merge and clean text from all pages
    full_lines = []
    for page in reader.pages:
        full_lines.extend(clean_lines(page.extract_text() or ""))

    header_idx = find_header_index(full_lines)
    year = extract_year(full_lines)
    program = pick_program_name(full_lines, header_idx)

    filename = sanitize_filename(f"{program} {year}.pdf")
    shutil.copy2(pdf_path, OUTPUT_DIR / filename)
    print(f"{pdf_path.name} → {filename}")

print("Rename complete.")