import pdfplumber
import os
import re
from datetime import datetime
import csv

def extract_licensors_october_2024(folder_path):
    results = []
    
    # Check for PDF files first
    pdf_files = [f for f in os.listdir(folder_path) if f.endswith('.pdf')]
    print(f"Found {len(pdf_files)} PDF files in current folder")
    
    for filename in pdf_files:
        print(f"\nProcessing: {filename}")
        pdf_path = os.path.join(folder_path, filename)
        
        try:
            with pdfplumber.open(pdf_path) as pdf:
                # Extract text from first page
                first_page_text = pdf.pages[0].extract_text()
                
                # Extract the date
                date_match = re.search(r'Date:\s*(\d{1,2}/\d{1,2}/\d{4})', first_page_text)
                
                if date_match:
                    date_str = date_match.group(1)
                    print(f"  Found date: {date_str}")
                    inspection_date = datetime.strptime(date_str, '%m/%d/%Y')
                    
                    # Check if it's October 2024
                    if inspection_date.month == 10 and inspection_date.year == 2024:
                        print(f"  ✓ Date is in October 2024!")
                        
                        # Extract licensor name(s)
                        licensor_match = re.search(
                            r'Licensor\(s\) Conducting this Inspection:\s*([^\n]+)',
                            first_page_text
                        )
                        
                        if licensor_match:
                            licensor_name = licensor_match.group(1).strip()
                            print(f"  Found licensor: {licensor_name}")
                            results.append({
                                'file': filename,
                                'date': date_str,
                                'licensor': licensor_name
                            })
                        else:
                            print(f"  Could not find licensor field")
                    else:
                        print(f"  Date is {inspection_date.strftime('%B %Y')} (skipping)")
                else:
                    print(f"  Could not find date in document")
        
        except Exception as e:
            print(f"  ERROR: {e}")
    
    return results

# Run in current folder
print("Starting extraction...\n")
folder_path = '.'
licensors = extract_licensors_october_2024(folder_path)

# Print results
print(f"\n{'='*50}")
print(f"RESULTS: Found {len(licensors)} inspections from October 2024")
print(f"{'='*50}\n")

if licensors:
    for item in licensors:
        print(f"{item['file']} ({item['date']}): {item['licensor']}")
    
    # Save to CSV
    with open('licensors_october_2024.csv', 'w', newline='') as f:
        writer = csv.DictWriter(f, fieldnames=['file', 'date', 'licensor'])
        writer.writeheader()
        writer.writerows(licensors)
    
    print(f"\nResults saved to licensors_october_2024.csv")
else:
    print("No matching records found.")