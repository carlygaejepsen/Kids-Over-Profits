import csv
import json
import os

def generate_html_from_csv(csv_filepath, output_file):
    """
    Final script to generate an HTML report from a multi-row CSV, with all specified features.
    """
    
    # --- STEP 1: Process the CSV into a structured format ---
    
    inspections = {}
    try:
        with open(csv_filepath, mode='r', encoding='utf-8') as file:
            reader = csv.DictReader(file)
            for row in reader:
                insp_num = row.get("inspection_number", "").strip()
                if not insp_num:
                    continue

                if insp_num not in inspections:
                    inspections[insp_num] = {
                        "date": row.get("inspection_date"), "type": row.get("inspection_type"),
                        "name": row.get("legal_name"), "address": row.get("address"),
                        "officer": row.get("chief_administrative_officer"), "capacity": row.get("max_licensed_capacity"),
                        "deficiencies": []
                    }
                
                rule = row.get("deficiency_rule", "").strip()
                if rule:
                    inspections[insp_num]["deficiencies"].append({
                        "rule": rule, "evidence": row.get("deficiency_evidence", ""), "findings": row.get("deficiency_findings", "")
                    })

    except FileNotFoundError:
        print(f"ERROR: The file was not found at the path specified: {csv_filepath}")
        return
    except Exception as e:
        print(f"ERROR: An error occurred while processing the file: {e}")
        return

    # Group the processed inspections by facility address.
    facilities = {}
    for insp_num, insp_data in inspections.items():
        address_key = insp_data.get("address", "").strip().lower()
        if not address_key:
            continue
        
        if address_key not in facilities:
            facilities[address_key] = {
                "name": insp_data.get("name"), "address": insp_data.get("address"),
                "officer": insp_data.get("officer"), "capacity": insp_data.get("capacity"),
                "inspections": []
            }
        facilities[address_key]["inspections"].append(insp_data)

    # --- STEP 2: Generate the final HTML string ---

    html_head = """
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Facility Reports</title>
    <style>
        body { font-family: sans-serif; background-color: #f0f0f0; padding: 20px; }
        summary::-webkit-details-marker { display: none; }
        summary { list-style-type: none; }
        .indicator .open-text { display: none; }
        .indicator .closed-text { display: inline; }
        details[open] > summary .indicator .open-text { display: inline; }
        details[open] > summary .indicator .closed-text { display: none; }
    </style>
</head>
<body>
"""

    all_html_output = ""
    for address_key in sorted(facilities.keys()):
        facility = facilities[address_key]
        
        facility_html = f"""
<div style='background-color:#00004d;color:#ffffff;padding:20px;border:1px solid #ccc;border-radius:8px;margin-bottom:20px;'>
  <details>
    <summary style='cursor:pointer;padding:10px 0;'>
      <div>
        <div style="font-size:1.2em; font-weight:bold;">{facility.get('name', 'N/A')}</div>
        <div style="font-size:1.0em; font-weight:normal; padding-top:5px;">{facility.get('address', 'N/A')}</div>
        <div style="font-size:0.9em; font-weight:normal; padding-top:5px;">Chief Administrative Officer: {facility.get('officer', 'N/A')} | Max Licensed Capacity: {facility.get('capacity', 'N/A')}</div>
      </div>
      <div class="indicator" style="font-size:0.8em; margin-top:10px; font-weight:normal;">
        <span class="closed-text">+ Click for detailed reports</span>
        <span class="open-text">− Hide detailed reports</span>
      </div>
    </summary>
    <div style='padding-top:15px;border-top:2px solid #ddd;margin-top:15px;'>"""

        reports = sorted(facility['inspections'], key=lambda x: x.get("date", "0"), reverse=True)
        for inspection in reports:
            has_violations = bool(inspection["deficiencies"])
            bg, font = ("#ff9933", "#ffffff") if has_violations else ("#ffffff", "#000000")
            
            inspection_html = f"""
      <details style='padding:10px 15px;border:1px solid #ddd;margin-top:10px;border-radius:5px;background-color:{bg};color:{font};'>
        <summary style='font-weight:bold;cursor:pointer;font-size:1em;'>
          {inspection.get('type', 'N/A')} on {inspection.get('date', 'N/A')}
          <div class="indicator" style="font-size:0.8em; margin-top:5px; font-weight:normal;">
            <span class="closed-text">+ Show Details</span>
            <span class="open-text">− Hide Details</span>
          </div>
        </summary>
        <div style='padding-top:10px;border-top:1px solid #eee;margin-top:10px;font-size:0.9em;'>"""

            if has_violations:
                deficiency_parts = []
                for deficiency in inspection["deficiencies"]:
                    rule = f"""
          <details style='margin-left:20px;margin-bottom:5px;background-color:#ffffff;color:#000;padding:5px;border:1px solid #ddd;border-radius:4px;'>
            <summary style='font-weight:bold;cursor:pointer;'>
              <span class="indicator"><span class="closed-text">+</span><span class="open-text">−</span></span> Rule
            </summary>
            <div style='padding:10px;border-top:1px solid #eee;margin-top:5px;'>{deficiency.get('rule','')}</div>
          </details>"""
                    evidence = f"""
          <details style='margin-left:20px;margin-bottom:5px;background-color:#ffffff;color:#000;padding:5px;border:1px solid #ddd;border-radius:4px;'>
            <summary style='font-weight:bold;cursor:pointer;'>
              <span class="indicator"><span class="closed-text">+</span><span class="open-text">−</span></span> Evidence
            </summary>
            <div style='padding:10px;border-top:1px solid #eee;margin-top:5px;'>{deficiency.get('evidence','')}</div>
          </details>"""
                    findings = f"""
          <details style='margin-left:20px;margin-bottom:5px;background-color:#ffffff;color:#000;padding:5px;border:1px solid #ddd;border-radius:4px;'>
            <summary style='font-weight:bold;cursor:pointer;'>
              <span class="indicator"><span class="closed-text">+</span><span class="open-text">−</span></span> Findings
            </summary>
            <div style='padding:10px;border-top:1px solid #eee;margin-top:5px;'>{deficiency.get('findings','')}</div>
          </details>"""
                    deficiency_parts.append(f"{rule}{evidence}{findings}")
                inspection_html += "".join(deficiency_parts)
            else:
                inspection_html += "No violations noted."

            inspection_html += "</div></details>"
            facility_html += inspection_html

        facility_html += "</div></details></div>"
        all_html_output += facility_html

    html_foot = "</body></html>"
    
    # --- STEP 3: Write the final HTML to a file ---
    # Ensure the output directory exists before writing the file
    output_dir = os.path.dirname(output_file)
    if output_dir:
        os.makedirs(output_dir, exist_ok=True)
        
    with open(output_file, 'w', encoding='utf-8') as f:
        f.write(html_head + all_html_output + html_foot)
    print(f"✅ HTML generation complete. Check file '{output_file}'")


# --- USER CONFIGURATION ---
csv_filepath = r"C:\Scripts\az_inspections.csv"
output_file = r"C:\Scripts\AZ_Reports\arizona_reports.html"

# --- Run the Script ---
generate_html_from_csv(csv_filepath, output_file)
