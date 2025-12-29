from pathlib import Path
import re

# === CONFIG ===
TARGET_DIR = Path(r"C:\Users\daniu\My Drive\Kids Over Profits\NATSAP\Directories\Single Profiles\Extract NATSAP 2009 Directory")  # point to your renamed folder

# Pattern to match and remove from start of filename
HEADER_PATTERN = re.compile(r"^(NATSAP\s*20\d{2}\s*-\s*20\d{2}\s*DIRECTORY[\s\-]*)", re.IGNORECASE)

for file in TARGET_DIR.glob("*.pdf"):
    original_name = file.stem
    cleaned_name = HEADER_PATTERN.sub("", original_name).strip()
    cleaned_name = re.sub(r"\s+", " ", cleaned_name)  # collapse extra spaces
    new_path = file.with_name(f"{cleaned_name}.pdf")

    if new_path != file:
        file.rename(new_path)
        print(f"{file.name} → {new_path.name}")

print("Header cleanup complete.")