#!/usr/bin/env python3
import os
import hashlib
from collections import defaultdict

print("Starting duplicate detection...")
print("=" * 60)

# Dictionary to store hash -> list of files
file_hashes = defaultdict(list)
total_files = 0
empty_files = []

print("Scanning files and calculating checksums...")

for filename in os.listdir('.'):
    if filename.endswith('.md'):
        total_files += 1
        if total_files % 100 == 0:
            print(f"  Processed {total_files} files...")

        filepath = os.path.join('.', filename)

        # Check if file is empty
        if os.path.getsize(filepath) == 0:
            empty_files.append(filename)
            continue

        # Calculate MD5 hash
        with open(filepath, 'rb') as f:
            file_hash = hashlib.md5(f.read()).hexdigest()

        file_hashes[file_hash].append(filename)

print(f"\n✓ Scanned {total_files} files")
print("=" * 60)

# Find duplicates
duplicates = {hash_val: files for hash_val, files in file_hashes.items() if len(files) > 1}

print(f"\nFound {len(duplicates)} groups of duplicate files")
print(f"Found {len(empty_files)} empty files")
print("=" * 60)

if empty_files:
    print("\n--- EMPTY FILES ---")
    for i, f in enumerate(empty_files, 1):
        print(f"  {i}. {f}")

if duplicates:
    print(f"\n--- DUPLICATE GROUPS ---")
    total_duplicates = 0
    for i, (hash_val, files) in enumerate(duplicates.items(), 1):
        total_duplicates += len(files) - 1  # -1 because we keep one
        print(f"\nGroup {i} ({len(files)} copies):")
        for j, f in enumerate(sorted(files), 1):
            size = os.path.getsize(f)
            print(f"  {j}. {f} ({size} bytes)")

    print("\n" + "=" * 60)
    print(f"Total files that can be removed: {total_duplicates + len(empty_files)}")
    print(f"  - {total_duplicates} duplicate files")
    print(f"  - {len(empty_files)} empty files")
    print("=" * 60)
