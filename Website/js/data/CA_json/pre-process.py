# File: pre-process.py (Corrected Version)
import json
import os
from collections import defaultdict

def aggregate_data_to_json(folder_path, output_filepath):
    """
    Reads all standard JSON files in a folder, aggregates the data by facility,
    and saves it to a single, structured JSON file for a JavaScript front-end.
    """
    facilities = defaultdict(lambda: {
        "name": "N/A",
        "number": "",
        "officer": "N/A",
        "facility_type": "N/A",
        "capacity": "N/A",
        "inspections": []
    })
    
    total_files_processed = 0
    total_records_processed = 0

    print(f"🔍 Reading JSON files from: {folder_path}")
    for filename in os.listdir(folder_path):
        if filename.endswith('.json'):
            json_filepath = os.path.join(folder_path, filename)
            try:
                with open(json_filepath, 'r', encoding='utf-8') as file:
                    # ** THE FIX IS HERE **
                    # Read the entire file content, not line-by-line
                    content = file.read()
                    if not content.strip():
                        print(f"⚪ Skipping empty file: {filename}")
                        continue
                        
                    data = json.loads(content)
                    
                    # Ensure we are always working with a list of records
                    json_records = data if isinstance(data, list) else [data]

                for record in json_records:
                    # Skip records that are just status updates
                    if 'status' in record and record['status'] == 'No reports found':
                        continue

                    facility_num = record.get("facility_number", "").strip()
                    if not facility_num:
                        continue

                    # Update facility info using the latest data found
                    facilities[facility_num]["name"] = record.get("facility_name", facilities[facility_num].get("name", "N/A"))
                    facilities[facility_num]["number"] = facility_num
                    facilities[facility_num]["officer"] = record.get("administrator", facilities[facility_num].get("officer", "N/A"))
                    facilities[facility_num]["facility_type"] = record.get("facility_type_name", facilities[facility_num].get("facility_type", "N/A"))
                    facilities[facility_num]["capacity"] = record.get("capacity", facilities[facility_num].get("capacity", "N/A"))

                    # Create and append the inspection record
                    facilities[facility_num]["inspections"].append(record)
                    total_records_processed += 1
                
                total_files_processed += 1

            except json.JSONDecodeError as e:
                print(f"❌ ERROR: Invalid JSON in file {filename}: {e}")
            except Exception as e:
                print(f"❌ ERROR: Could not process file {filename}: {e}")

    if not facilities:
        print("🔴 No facility data was successfully processed. The output file will be empty.")
        return

    print(f"\n✅ Successfully processed {total_records_processed} records from {total_files_processed} files.")
    print("🔠 Grouping facilities by first letter...")
    
    grouped_by_letter = defaultdict(list)
    for facility_data in facilities.values():
        name = facility_data.get('name', '').strip().upper()
        if name:
            first_letter = name[0]
            group_key = first_letter if 'A' <= first_letter <= 'Z' else '#'
            grouped_by_letter[group_key].append(facility_data)

    sorted_grouped_data = {}
    for letter, fac_list in sorted(grouped_by_letter.items()):
        sorted_grouped_data[letter] = sorted(fac_list, key=lambda x: x.get('name', '').lower())
    
    for letter in sorted_grouped_data:
        for facility in sorted_grouped_data[letter]:
            facility['inspections'].sort(key=lambda x: x.get("visit_date", "0"), reverse=True)

    try:
        with open(output_filepath, 'w', encoding='utf-8') as f:
            json.dump(sorted_grouped_data, f, indent=2)
        print(f"💾 Success! Aggregated data saved to: {output_filepath}")
    except Exception as e:
        print(f"❌ ERROR: Could not write output file: {e}")


# --- USER CONFIGURATION ---
json_folder_path = r"C:\Users\daniu\OneDrive\Documents\GitHub\Kids-Over-Profits\Scripts\middle\CA_json"
output_json_path = r"C:\Users\daniu\OneDrive\Documents\GitHub\Kids-Over-Profits\Scripts\output_folder\ca_report_data.json"

# --- Run the Script ---
if __name__ == "__main__":
    aggregate_data_to_json(json_folder_path, output_json_path)