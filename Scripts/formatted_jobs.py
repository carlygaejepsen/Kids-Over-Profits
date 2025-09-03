import csv
import re
from collections import defaultdict

INPUT = r"parsed_facility_person.csv"  # your CSV path
OUTPUT = r"people_jobs.csv"

YEAR_RE = re.compile(r"\b(19|20)\d{2}\b")

def clean(s: str) -> str:
    return (s or "").strip()

def first_year_key(s: str) -> int:
    years = [int(m.group(0)) for m in re.finditer(YEAR_RE, s or "")]
    return min(years) if years else 9999

def build_job(role: str, facility: str, dates: str) -> str:
    role, facility, dates = clean(role), clean(facility), clean(dates)
    if role and facility and dates:
        return f"{role} at {facility} {dates}"
    if role and facility:
        return f"{role} at {facility}"
    if facility and dates:
        return f"{facility} {dates}"
    return " ".join(x for x in (role, facility, dates) if x)

# Aggregate across facilities
people_jobs = defaultdict(list)
display_names = {}

with open(INPUT, "r", encoding="utf-8-sig", newline="") as f:
    reader = csv.DictReader(f)  # default delimiter="," for CSV
    for row in reader:
        facility = row.get("facility", "")
        first = row.get("first name", "")
        last = row.get("last name", "")
        role = row.get("role", "")
        dates = row.get("dates", "")

        first_c, last_c = clean(first), clean(last)
        if not first_c and not last_c:
            continue

        pid = (last_c.lower(), first_c.lower())
        display_names.setdefault(pid, (first_c, last_c))

        job = build_job(role, facility, dates)
        if job and job not in people_jobs[pid]:
            people_jobs[pid].append(job)

# Build output
final_rows = []
for pid, jobs in people_jobs.items():
    jobs_sorted = sorted(jobs, key=first_year_key)
    first_disp, last_disp = display_names[pid]
    final_rows.append({
        "Last Name": last_disp,
        "First Name": first_disp,
        "Jobs": "\n".join(jobs_sorted)
    })

final_rows.sort(key=lambda r: (r["Last Name"].lower(), r["First Name"].lower()))

with open(OUTPUT, "w", encoding="utf-8", newline="") as f:
    writer = csv.DictWriter(
        f,
        fieldnames=["Last Name", "First Name", "Jobs"],
        quoting=csv.QUOTE_ALL
    )
    writer.writeheader()
    writer.writerows(final_rows)

print(f"Wrote {len(final_rows)} people to {OUTPUT}")