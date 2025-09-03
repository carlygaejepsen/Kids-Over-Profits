import csv
import json
import os

def generate_html_from_csv(csv_filepath, output_file):
    """
    Final script to generate a clean HTML SNIPPET from a multi-row CSV,
    designed to be styled by external CSS classes in WordPress.
    """
    
    # --- STEP 1: Process the CSV into a structured format ---
    
    inspections = {}
    try:
        with open(csv_filepath, mode='r', encoding='latin-1') as file:
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
                no_violation_placeholders = ["n/a", "none", "no deficiencies"]
                
                if rule and rule.lower() not in no_violation_placeholders:
                    inspections[insp_num]["deficiencies"].append({
                        "rule": rule,
                        "evidence": row.get("deficiency_evidence", ""),
                        "findings": row.get("deficiency_findings", "")
                    })

    except FileNotFoundError:
        print(f"ERROR: The file was not found at the path specified: {csv_filepath}")
        return
    except Exception as e:
        print(f"ERROR: An error occurred while processing the file: {e}")
        return

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

    # --- STEP 2: Generate and Write the HTML Snippet ---

    # The output now starts directly with the container div. No <style> or <head>.
    html_snippet_start = '<div class="facility-report-container">'
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
        <h2 class="facility-name">{facility.get('name', 'N/A')}</h2>
        <div class="facility-address">{facility.get('address', 'N/A')}</div>
        <div class="facility-details">Chief Administrative Officer: {facility.get('officer', 'N/A')} | Max Licensed Capacity: {facility.get('capacity', 'N/A')}</div>
      </div>
      <div class="indicator indicator-main">
        <span class="closed-text">+ Click for detailed reports</span>
        <span class="open-text">− Hide detailed reports</span>
      </div>
    </summary>
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
                            evidence = f"""
              <details class="deficiency-box">
                <summary class="deficiency-header">
                  <span class="indicator"><span class="closed-text">+</span><span class="open-text">−</span></span> Evidence
                </summary>
                <div class="deficiency-content">{deficiency.get('evidence','')}</div>
              </details>"""
                            findings = f"""
              <details class="deficiency-box">
                <summary class="deficiency-header">
                  <span class="indicator"><span class="closed-text">+</span><span class="open-text">−</span></span> Findings
                </summary>
                <div class="deficiency-content">{deficiency.get('findings','')}</div>
              </details>"""
                            
                            violation_container += f"{rule}{evidence}{findings}</div></details>"
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
csv_filepath = r"C:\Scripts\az_inspections.csv"
output_file = r"C:\Scripts\AZ_Reports\arizona_reports.html"

# --- Run the Script ---
generate_html_from_csv(csv_filepath, output_file)