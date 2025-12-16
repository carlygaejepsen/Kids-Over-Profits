#!/bin/bash

echo "=========================================="
echo "DUPLICATE FILE REMOVAL SCRIPT"
echo "=========================================="

# Count files to remove
total_removed=0
empty_count=0
dup_count=0

echo ""
echo "Phase 1: Removing empty files..."
echo "------------------------------------------"

# Find and remove empty files
while IFS= read -r file; do
    if [ -f "$file" ]; then
        echo "  Deleting empty file: $file"
        rm "$file"
        ((empty_count++))
        ((total_removed++))
    fi
done < <(find . -maxdepth 1 -type f -name "*.md" -size 0)

echo "Removed $empty_count empty files"

echo ""
echo "Phase 2: Removing duplicate files..."
echo "------------------------------------------"

# Create temp file for duplicates
temp_file=$(mktemp)

# Find duplicates and group them
find . -maxdepth 1 -type f -name "*.md" ! -size 0 -exec md5sum {} + 2>/dev/null | sort > "$temp_file"

current_hash=""
files_in_group=()

while IFS= read -r line; do
    hash=$(echo "$line" | awk '{print $1}')
    file=$(echo "$line" | awk '{print $2}')

    if [ "$hash" = "$current_hash" ]; then
        # Same hash, add to group
        files_in_group+=("$file")
    else
        # New hash, process previous group
        if [ ${#files_in_group[@]} -gt 1 ]; then
            # We have duplicates!
            echo ""
            echo "Duplicate group (${#files_in_group[@]} files):"

            # Sort files by name length (shortest first)
            IFS=$'\n' sorted=($(for f in "${files_in_group[@]}"; do
                echo "${#f} $f"
            done | sort -n | awk '{print $2}'))

            # Keep the first (shortest name), delete the rest
            keeper="${sorted[0]}"
            echo "  KEEPING: $keeper"

            for ((i=1; i<${#sorted[@]}; i++)); do
                echo "  Deleting: ${sorted[$i]}"
                rm "${sorted[$i]}"
                ((dup_count++))
                ((total_removed++))
            done
        fi

        # Start new group
        current_hash="$hash"
        files_in_group=("$file")
    fi
done < "$temp_file"

# Process last group
if [ ${#files_in_group[@]} -gt 1 ]; then
    echo ""
    echo "Duplicate group (${#files_in_group[@]} files):"

    IFS=$'\n' sorted=($(for f in "${files_in_group[@]}"; do
        echo "${#f} $f"
    done | sort -n | awk '{print $2}'))

    keeper="${sorted[0]}"
    echo "  KEEPING: $keeper"

    for ((i=1; i<${#sorted[@]}; i++)); do
        echo "  Deleting: ${sorted[$i]}"
        rm "${sorted[$i]}"
        ((dup_count++))
        ((total_removed++))
    done
fi

rm "$temp_file"

echo ""
echo "=========================================="
echo "SUMMARY"
echo "=========================================="
echo "Empty files removed:     $empty_count"
echo "Duplicate files removed: $dup_count"
echo "Total files removed:     $total_removed"
echo "=========================================="
