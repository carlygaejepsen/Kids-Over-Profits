import csv
import json
import os

def generate_utah_html_report(csv_filepath, output_file):
    """
    Reads a 'wide' format Utah CSV, processes it into a nested structure,
    and generates a self-contained HTML report snippet suitable for WordPress,
    matching the Arizona report's style and functionality.
    """
    
    # --- STEP 1: Process the 'wide' CSV into a structured format ---
    
    facilities = {}
    try:
        with open(csv_filepath, mode='r', encoding='latin-1') as file:
            reader = csv.DictReader(file)
            for row in reader:
                address = row.get("Address", "").strip()
                if not address:
                    continue

                address_key = address.lower()

                # Create the main facility record if it's the first time we've seen this address
                if address_key not in facilities:
                    facilities[address_key] = {
                        "name": row.get("Name", "N/A"),
                        "address": address,
                        # Utah data does not have officer or capacity, so they are omitted.
                        "inspections": []
                    }
                
                # Loop through the 20 possible inspection columns for this facility
                for i in range(1, 21):
                    inspection_date = row.get(f"Inspection {i} Date")
                    inspection_findings_raw = row.get(f"Inspection {i} Findings", "").strip()

                    # Process this inspection only if it has a date
                    if inspection_date:
                        new_inspection = {
                            "date": inspection_date,
                            "type": row.get(f"Inspection {i} Type", "Inspection"),
                            "deficiencies": []
                        }

                        # Check if there are actual findings (not just "None")
                        if inspection_findings_raw and inspection_findings_raw.lower() != 'none':
                            rule = ""
                            finding_text = inspection_findings_raw
                            
                            # Split the rule from the finding text
                            if '|' in inspection_findings_raw:
                                parts = inspection_findings_raw.split('|', 1)
                                rule = parts[0].strip()
                                finding_text = parts[1].replace("Finding:", "").strip()

                            # Add this as a single deficiency for this inspection
                            new_inspection["deficiencies"].append({
                                "rule": rule,
                                "evidence": "", # Utah data does not have an evidence field
                                "findings": finding_text
                            })
                        
                        facilities[address_key]["inspections"].append(new_inspection)

    except FileNotFoundError:
        print(f"ERROR: The file was not found at the path specified: {csv_filepath}")
        return
    except Exception as e:
        print(f"ERROR: An error occurred while processing the file: {e}")
        return

    # --- STEP 2: Generate the HTML Snippet for WordPress ---

    html_snippet_start = """
<style>
    .facility-report-container { font-family: sans-serif; font-size: 16px; }
    .facility-report-container summary::-webkit-details-marker { display: none; }
    .facility-report-container summary { list-style-type: none; cursor: pointer; }
    .facility-report-container .indicator .open-text { display: none; }
    .facility-report-container .indicator .closed-text { display: inline; }
    .facility-report-container details[open] > summary .indicator .open-text { display: inline; }
    .facility-report-container details[open] > summary .indicator .closed-text { display: none; }
    .facility-report-container .facility-box { background-color:#00004d; color:#ffffff; padding:20px; border:1px solid #ccc; border-radius:8px; margin-bottom:20px; }
    .facility-report-container .facility-header { padding:10px 0; }
    .facility-report-container .facility-name { font-size:1.5em; font-weight:bold; }
    .facility-report-container .facility-address { font-size:1.0em; font-weight:normal; padding-top:5px; }
    .facility-report-container .facility-content-header { margin-bottom: 15px; }
    .facility-report-container .facility-content-header h2 { color: #ffffff; margin: 0 0 5px 0; font-size: 1.5em; }
    .facility-report-container .facility-content-header p { color: #ffffff; margin: 0; font-size: 1.0em; }
    .facility-report-container .indicator-main { font-size:0.8em; margin-top:10px; font-weight:normal; }
    .facility-report-container .inspections-container { padding-top:15px; border-top:2px solid #ddd; margin-top:15px; }
    .facility-report-container .inspection-box { padding:10px 15px; border:1px solid #ddd; margin-top:10px; border-radius:5px; }
    .facility-report-container .inspection-box-violation { background-color:#ff9933; color:#ffffff; }
    .facility-report-container .inspection-box-clean { background-color:#ffffff; color:#000000; }
    .facility-report-container .inspection-header { font-weight:bold; font-size:1em; }
    .facility-report-container .indicator-inspection { font-size:0.8em; margin-top:5px; font-weight:normal; }
    .facility-report-container .inspection-content { padding-top:10px; border-top:1px solid #eee; margin-top:10px; font-size:0.9em; }
    .facility-report-container .violation-box { margin-bottom:10px; background-color:#ffffff; color:#000; padding:5px; border:1px solid #ddd; border-radius:4px; }
    .facility-report-container .deficiency-box { margin-left:20px; margin-bottom:5px; background-color:#f7f7f7; color:#000; padding:5px; border:1px solid #ddd; border-radius:4px; }
    .facility-report-container .deficiency-header { font-weight:bold; }
    .facility-report-container .deficiency-content { padding:10px; border-top:1px solid #eee; margin-top:5px; }
</style>
<div class="facility-report-container">
"""
    html_snippet_end = "\n</div>"

    try:
        output_dir = os.path.dirname(output_file)
        if output_dir:
            os.makedirs(output_dir, exist_ok=True)
            
        with open(output_file, 'w', encoding='utf-8') as f:
            f.write(html_snippet_start)

            sorted_facilities = sorted(facilities.values(), key=lambda x: x.get('name', '').strip().lower())

            for facility in sorted_facilities:
                
                facility_html = f"""
<div class="facility-box">
  <details>
    <summary class="facility-header">
      <div>
        <div class="facility-name">{facility.get('name', 'N/A')}</div>
        <div class="facility-address">{facility.get('address', 'N/A')}</div>
      </div>
      <div class="indicator indicator-main">
        <span class="closed-text">+ Click for detailed reports</span>
        <span class="open-text">− Hide detailed reports</span>
      </div>
    </summary>
    <div class="facility-content-header">
      <h2>{facility.get('name', 'N/A')}</h2>
      <p>{facility.get('address', 'N/A')}</p>
    </div>
    <div class="inspections-container">"""

                reports = sorted(facility['inspections'], key=lambda x: x.get("date", "0"), reverse=True)
                for inspection in reports:
                    has_violations = bool(inspection["deficiencies"])
                    inspection_class = "inspection-box-violation" if has_violations else "inspection-box-clean"
                    
                    inspection_html = f"""
      <details class="inspection-box {inspection_class}">
        <summary class="inspection-header">
          {inspection.get('type', 'N/A')} on {inspection.get('date', 'N/A')}
          <div class="indicator indicator-inspection">
            <span class="closed-text">+ Show Details</span>
            <span class="open-text">− Hide Details</span>
          </div>
        </summary>
        <div class="inspection-content">"""

                    if has_violations:
                        deficiency_parts = []
                        for i, deficiency in enumerate(inspection["deficiencies"], 1):
                            violation_number = i
                            
                            violation_container = f"""
          <details class="violation-box">
            <summary class="deficiency-header">
              <span class="indicator"><span class="closed-text">+</span><span class="open-text">−</span></span> Violation {violation_number}
            </summary>
            <div class="deficiency-content">"""

                            rule = f"""
              <details class="deficiency-box">
                <summary class="deficiency-header">
                  <span class="indicator"><span class="closed-text">+</span><span class="open-text">−</span></span> Rule
                </summary>
                <div class="deficiency-content">{deficiency.get('rule','')}</div>
              </details>"""
                            # Evidence box is omitted as there is no evidence data
                            findings = f"""
              <details class="deficiency-box">
                <summary class="deficiency-header">
                  <span class="indicator"><span class="closed-text">+</span><span class="open-text">−</span></span> Findings
                </summary>
                <div class="deficiency-content">{deficiency.get('findings','')}</div>
              </details>"""
                            
                            violation_container += f"{rule}{findings}</div></details>"
                            deficiency_parts.append(violation_container)

                        inspection_html += "".join(deficiency_parts)
                    else:
                        inspection_html += "No violations noted."

                    inspection_html += "</div></details>"
                    facility_html += inspection_html

                facility_html += "</div></details></div>"
                f.write(facility_html)

            f.write(html_snippet_end)

        print(f"✅ HTML snippet generation complete. Check file '{output_file}'")

    except Exception as e:
        print(f"ERROR: An error occurred during HTML generation or file writing: {e}")
        return


# --- USER CONFIGURATION ---
csv_filepath = r"C:\Users\daniu\OneDrive\Documents\GitHub\Kids-Over-Profits\Scripts\middle\utah_citations_8-24-2025.csv"
output_file = r"C:\Users\daniu\OneDrive\Documents\GitHub\Kids-Over-Profits\Scripts\deploy\utah_citations.html"

# --- Run the Script ---
generate_utah_html_report(csv_filepath, output_file)