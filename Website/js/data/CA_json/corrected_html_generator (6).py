import json
import os
from collections import defaultdict

def generate_california_html_report_from_json(json_filepath, output_file):
    """
    Generates multiple, alphabetized HTML SNIPPETS from California JSON data.
    FIXED: Now reads JSON input instead of CSV and preserves complete text.
    """
    
    facilities = defaultdict(lambda: {
        "name": "N/A",
        "number": "",
        "officer": "N/A", 
        "facility_type": "N/A",
        "capacity": "N/A",
        "inspections": []
    })
    
    try:
        with open(json_filepath, 'r', encoding='utf-8') as file:
            # Handle both single JSON object and JSON Lines format
            content = file.read().strip()
            
            if content.startswith('['):
                # JSON array format
                data = json.loads(content)
                json_records = data if isinstance(data, list) else [data]
            else:
                # JSON Lines format (one JSON object per line)
                json_records = []
                for line in content.split('\n'):
                    line = line.strip()
                    if line:
                        json_records.append(json.loads(line))
        
        for record in json_records:
            facility_num = record.get("facility_number", "").strip()
            if not facility_num:
                continue

            # Update facility info (using most recent data)
            facilities[facility_num]["name"] = record.get("facility_name", "N/A")
            facilities[facility_num]["number"] = facility_num
            facilities[facility_num]["officer"] = record.get("administrator", "N/A")
            facilities[facility_num]["facility_type"] = record.get("facility_type_name", "N/A")
            facilities[facility_num]["capacity"] = record.get("capacity", "N/A")

            # Create inspection record - FIXED: Direct JSON field mapping
            inspection = {
                "visit_date": record.get("visit_date", "N/A"),
                "report_type": record.get("report_type", "N/A"),
                "form_number": record.get("form_number", "N/A"),
                "report_date": record.get("report_date", "N/A"),
                "date_signed": record.get("date_signed", "N/A"),
                "announced_status": record.get("announced_status", "N/A"),
                "time_began": record.get("time_began", "N/A"),
                "time_completed": record.get("time_completed", "N/A"),
                "met_with": record.get("met_with", "N/A"),
                "supervisor_name": record.get("supervisor_name", "N/A"),
                "evaluator_name": record.get("evaluator_name", "N/A"),
                "census": record.get("census", "N/A"),
                "visit_type": record.get("visit_type", "N/A"),
                
                # Complaint-specific fields
                "complaint_control_number": record.get("complaint_control_number", "N/A"),
                "complaint_status": record.get("complaint_status", "N/A"),
                "complaint_received_date": record.get("complaint_received_date", "N/A"),
                
                # FIXED: Complete text fields preserved
                "narrative": record.get("narrative", "").strip(),
                "investigation_findings": record.get("investigation_findings", "").strip(),
                
                # FIXED: Direct deficiencies array from JSON
                "deficiencies": record.get("deficiencies", [])
            }
            
            facilities[facility_num]["inspections"].append(inspection)

    except FileNotFoundError:
        print(f"ERROR: The file was not found at the path specified: {json_filepath}")
        return
    except json.JSONDecodeError as e:
        print(f"ERROR: Invalid JSON format: {e}")
        return
    except Exception as e:
        print(f"ERROR: An error occurred while processing the file: {e}")
        return

    # Group facilities by the first letter of their name for alphabetized output
    grouped_by_letter = {}
    for facility_data in facilities.values():
        name = facility_data.get('name', '').strip().upper()
        if name:
            first_letter = name[0]
            if 'A' <= first_letter <= 'Z':
                if first_letter not in grouped_by_letter:
                    grouped_by_letter[first_letter] = []
                grouped_by_letter[first_letter].append(facility_data)

    # --- HTML Generation Stage ---
    html_snippet_start = '<div class="facility-report-container">'
    html_snippet_end = "\n</div>"

    try:
        base_filename, file_extension = os.path.splitext(output_file)
        
        # Loop through each letter group to create separate files
        for letter, facilities_in_group in sorted(grouped_by_letter.items()):
            
            alphabetical_output_file = f"{base_filename}_{letter}{file_extension}"
            output_dir = os.path.dirname(alphabetical_output_file)
            if output_dir:
                os.makedirs(output_dir, exist_ok=True)
            
            with open(alphabetical_output_file, 'w', encoding='utf-8') as f:
                f.write(html_snippet_start)

                sorted_facilities = sorted(facilities_in_group, key=lambda x: x.get('name', '').strip().lower())

                for facility in sorted_facilities:
                    facility_html = f"""
<div class="facility-box">
  <details>
    <summary class="facility-header">
      <h1>{facility.get('name', 'N/A')}</h1>
      <h2>Facility Number: {facility.get('number', 'N/A')}</h2>
      <p class="facility-details">Type: {facility.get('facility_type', 'N/A')} | Administrator: {facility.get('officer', 'N/A')} | Licensed Capacity: {facility.get('capacity', 'N/A')}</p>
      <div class="indicator indicator-main">
        <span class="closed-text">+ Click for detailed reports</span>
        <span class="open-text">− Hide detailed reports</span>
      </div>
    </summary>
    <div class="inspections-container">"""

                    # Sort by visit_date, most recent first
                    reports = sorted(facility['inspections'], key=lambda x: x.get("visit_date", "0"), reverse=True)
                    
                    for inspection in reports:
                        has_violations = bool(inspection["deficiencies"])
                        inspection_class = "inspection-box-violation" if has_violations else "inspection-box-clean"
                        
                        # Build inspection details HTML
                        inspection_details_html = """
        <div class="inspection-details-block">
            <div class="basic-info">"""
                        
                        # Basic inspection info
                        inspection_details_html += f"""
                <strong>Report Type:</strong> {inspection.get('report_type', 'N/A')}<br>
                <strong>Form Number:</strong> {inspection.get('form_number', 'N/A')}<br>
                <strong>Visit Date:</strong> {inspection.get('visit_date', 'N/A')}<br>
                <strong>Report Date:</strong> {inspection.get('report_date', 'N/A')}<br>
                <strong>Date Signed:</strong> {inspection.get('date_signed', 'N/A')}<br>"""
                        
                        if inspection.get('visit_type', 'N/A') != 'N/A':
                            inspection_details_html += f"""
                <strong>Visit Type:</strong> {inspection.get('visit_type', 'N/A')}<br>"""
                        
                        inspection_details_html += f"""
                <strong>Announced Status:</strong> {inspection.get('announced_status', 'N/A')}<br>
                <strong>Time:</strong> {inspection.get('time_began', 'N/A')} - {inspection.get('time_completed', 'N/A')}<br>
                <strong>Census at Visit:</strong> {inspection.get('census', 'N/A')}<br>
                <strong>Met With:</strong> {inspection.get('met_with', 'N/A')}<br>
                <strong>Evaluator:</strong> {inspection.get('evaluator_name', 'N/A')}<br>
                <strong>Supervisor:</strong> {inspection.get('supervisor_name', 'N/A')}
            </div>"""
                        
                        # Complaint-specific info (only show if it exists)
                        if inspection.get('complaint_control_number', 'N/A') != 'N/A':
                            inspection_details_html += f"""
            <div class="complaint-info">
                <h4>Complaint Information:</h4>
                <strong>Control Number:</strong> {inspection.get('complaint_control_number', 'N/A')}<br>
                <strong>Complaint Status:</strong> {inspection.get('complaint_status', 'N/A')}<br>
                <strong>Complaint Received:</strong> {inspection.get('complaint_received_date', 'N/A')}
            </div>"""
                        
                        # FIXED: Complete narrative and findings preserved
                        if inspection.get('narrative'):
                            # Replace newlines with HTML breaks for proper display
                            narrative_formatted = inspection.get('narrative', '').replace('\n', '<br>')
                            inspection_details_html += f"""
            <div class="narrative-section">
                <h4>Narrative:</h4>
                <p>{narrative_formatted}</p>
            </div>"""
                        
                        if inspection.get('investigation_findings'):
                            findings_formatted = inspection.get('investigation_findings', '').replace('\n', '<br>')
                            inspection_details_html += f"""
            <div class="findings-section">
                <h4>Investigation Findings:</h4>
                <p>{findings_formatted}</p>
            </div>"""
                        
                        inspection_details_html += "        </div>"
                        
                        inspection_html = f"""
      <details class="inspection-box {inspection_class}">
        <summary class="inspection-header">
          {inspection.get('report_type', 'Inspection')} - {inspection.get('visit_date', 'N/A')}
          <div class="indicator indicator-inspection">
            <span class="closed-text">+ Show Details</span>
            <span class="open-text">− Hide Details</span>
          </div>
        </summary>
        <div class="inspection-content">
          {inspection_details_html}"""

                        if has_violations:
                            deficiency_parts = []
                            # FIXED: Use deficiencies array directly from JSON
                            for i, deficiency in enumerate(inspection["deficiencies"], 1):
                                violation_container = f"""
          <details class="violation-box">
            <summary class="deficiency-header">
              <span class="indicator"><span class="closed-text">+</span><span class="open-text">−</span></span> Violation {i}
              {f" ({deficiency.get('deficiency_type', '')})" if deficiency.get('deficiency_type') else ""}
            </summary>
            <div class="deficiency-content">"""

                                # FIXED: Use correct JSON field names
                                rule = f"""
              <details class="deficiency-box">
                <summary class="deficiency-header">
                  <span class="indicator"><span class="closed-text">+</span><span class="open-text">−</span></span> Regulation Cited
                </summary>
                <div class="deficiency-content">{deficiency.get('section_cited', 'N/A')}</div>
              </details>"""
                                
                                # FIXED: Complete plan of correction text preserved
                                poc_formatted = deficiency.get('plan_of_correction', 'N/A').replace('\n', '<br>')
                                poc = f"""
              <details class="deficiency-box">
                <summary class="deficiency-header">
                  <span class="indicator"><span class="closed-text">+</span><span class="open-text">−</span></span> Plan of Correction
                  {f" (Due: {deficiency.get('poc_due_date', 'N/A')})" if deficiency.get('poc_due_date', 'N/A') != 'N/A' else ""}
                </summary>
                <div class="deficiency-content">{poc_formatted}</div>
              </details>"""

                                # Add description if it exists
                                description_html = ""
                                if deficiency.get('description'):
                                    desc_formatted = deficiency.get('description', '').replace('\n', '<br>')
                                    description_html = f"""
              <details class="deficiency-box">
                <summary class="deficiency-header">
                  <span class="indicator"><span class="closed-text">+</span><span class="open-text">−</span></span> Description
                </summary>
                <div class="deficiency-content">{desc_formatted}</div>
              </details>"""
                                
                                violation_container += f"{rule}{poc}{description_html}</div></details>"
                                deficiency_parts.append(violation_container)

                            inspection_html += "".join(deficiency_parts)
                        else:
                            inspection_html += """
          <div class="no-violations">
            <p><strong>✓ No violations noted in this inspection.</strong></p>
          </div>"""

                        inspection_html += "        </div>\n      </details>"
                        facility_html += inspection_html

                    facility_html += "    </div>\n  </details>\n</div>"
                    f.write(facility_html)

                f.write(html_snippet_end)
            
            print(f"✅ Generated file for letter '{letter}': {alphabetical_output_file}")

    except Exception as e:
        print(f"ERROR: An error occurred during HTML generation or file writing: {e}")
        return

# --- USER CONFIGURATION ---
# CHANGED: Now expects folder of JSON files, not single file
json_folder_path_ca = r"C:\Users\daniu\OneDrive\Documents\GitHub\Kids-Over-Profits\Scripts\middle\CA_json"  # Folder containing JSON files
output_file_ca = r"C:\Users\daniu\OneDrive\Documents\GitHub\Kids-Over-Profits\Scripts\output_folder\ca_inspections.html"

# --- Run the Script ---
generate_california_html_report_from_json(json_folder_path_ca, output_file_ca)