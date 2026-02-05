#!/bin/bash
# Run this via SSH to clean up the Git deployment

# Remove the .git folder from the deployed theme
rm -rf /home/kidsover/public_html/wp-content/themes/child/.git

# Remove any git objects that got copied
find /home/kidsover/public_html/wp-content/themes/child -type d -name ".git" -exec rm -rf {} + 2>/dev/null

echo "Git cleanup complete"
