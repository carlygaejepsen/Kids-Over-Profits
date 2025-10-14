import csv
import os

def generate_california_html_report(csv_filepath, output_file):
    """
    Generates multiple, alphabetized HTML SNIPPETS from the large California CSV,
    including a much larger set of specified columns.
    """
    
    facilities = {}
    try:
        with open(csv_filepath, mode='r', encoding='latin-1') as file:
            reader = csv.DictReader(file)
            for row in reader:
                facility_num = row.get("facility_number", "").strip()
                if not facility_num:
                    continue

                # Create the main facility record if it's the first time we see this number
                if facility_num not in facilities:
                    facilities[facility_num] = {
                        "name": row.get("facility_name", "N/A"),
                        "number": facility_num,
                        "officer": row.get("administrator", "N/A"),
                        "capacity": row.get("capacity", "N/A"),
                        "census": row.get("census", "N/A"), # Added census
                        "inspections": []
                    }

                # Create the inspection record for the current row
                new_inspection = {
                    "date": row.get("visit_date", "N/A"),
                    "type": row.get("visit_type", "Inspection"),
                    # Added all new inspection-level fields
                    "report_date": row.get("report_date", "N/A"),
                    "announced_status": row.get("announced_status", "N/A"),
                    "complaint_status": row.get("complaint_status", "N/A"),
                    "met_with": row.get("met_with", "N/A"),
                    "narrative": row.get("narrative", ""),
                    "investigation_findings": row.get("investigation_findings", ""),
                    "deficiencies": []
                }

                # Loop through the 8 possible deficiency columns
                for i in range(1, 9):
                    rule = row.get(f"deficiency_{i}_section_cited", "").strip()
                    if rule:
                        new_inspection["deficiencies"].append({
                            "rule": rule,
                            "evidence": row.get(f"deficiency_{i}_plan_of_correction", ""),
                            "findings": row.get(f"deficiency_{i}_description", ""),
                            # Added new deficiency-level fields
                            "deficiency_type": row.get(f"deficiency_{i}_deficiency_type", ""),
                            "poc_due_date": row.get(f"deficiency_{i}_poc_due_date", "N/A")
                        })
                
                facilities[facility_num]["inspections"].append(new_inspection)

    except FileNotFoundError:
        print(f"ERROR: The file was not found at the path specified: {csv_filepath}")
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
                    # Updated facility header to include census
                    facility_html = f"""
<div class="facility-box">
  <details>
    <summary class="facility-header">
      <h1>{facility.get('name', 'N/A')}</h1>
      <h2>Facility Number: {facility.get('number', 'N/A')}</h2>
      <p class="facility-details">Administrator: {facility.get('officer', 'N/A')} | Capacity: {facility.get('capacity', 'N/A')} | Census: {facility.get('census', 'N/A')}</p>
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
                        
                        # Added new block for general inspection details
                        inspection_details_html = f"""
        <div class="inspection-details-block">
            <strong>Report Date:</strong> {inspection.get('report_date', 'N/A')}<br>
            <strong>Announced Status:</strong> {inspection.get('announced_status', 'N/A')}<br>
            <strong>Complaint Status:</strong> {inspection.get('complaint_status', 'N/A')}<br>
            <strong>Met With:</strong> {inspection.get('met_with', 'N/A')}
            <p><strong>Narrative:</strong> {inspection.get('narrative', '')}</p>
            <p><strong>Investigation Findings:</strong> {inspection.get('investigation_findings', '')}</p>
        </div>
        """
                        
                        inspection_html = f"""
      <details class="inspection-box {inspection_class}">
        <summary class="inspection-header">
          {inspection.get('type', 'N/A')} on {inspection.get('date', 'N/A')}
          <div class="indicator indicator-inspection">
            <span class="closed-text">+ Show Details</span>
            <span class="open-text">− Hide Details</span>
          </div>
        </summary>
        <div class="inspection-content">
          {inspection_details_html}
"""

                        if has_violations:
                            deficiency_parts = []
                            for i, deficiency in enumerate(inspection["deficiencies"], 1):
                                # Updated violation header to include deficiency type
                                violation_container = f"""
          <details class="violation-box">
            <summary class="deficiency-header">
              <span class="indicator"><span class="closed-text">+</span><span class="open-text">−</span></span> Violation {i} ({deficiency.get('deficiency_type', '')})
            </summary>
            <div class="deficiency-content">"""

                                rule = f"""
              <details class="deficiency-box">
                <summary class="deficiency-header">
                  <span class="indicator"><span class="closed-text">+</span><span class="open-text">−</span></span> Rule
                </summary>
                <div class="deficiency-content">{deficiency.get('rule','')}</div>
              </details>"""
                                # Updated evidence header to include POC due date
                                evidence = f"""
              <details class="deficiency-box">
                <summary class="deficiency-header">
                  <span class="indicator"><span class="closed-text">+</span><span class="open-text">−</span></span> Evidence (POC Due: {deficiency.get('poc_due_date', 'N/A')})
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
            
            print(f"✅ Generated file for letter '{letter}': {alphabetical_output_file}")

    except Exception as e:
        print(f"ERROR: An error occurred during HTML generation or file writing: {e}")
        return

# --- USER CONFIGURATION ---
csv_filepath_ca = r"C:\Users\daniu\OneDrive\Documents\GitHub\Kids-Over-Profits\Scripts\middle\ca_inspections_sub.csv"
output_file_ca = r"C:\Users\daniu\OneDrive\Documents\GitHub\Kids-Over-Profits\Scripts\output_folder\ca_inspections.html"

# --- Run the Script ---
generate_california_html_report(csv_filepath_ca, output_file_ca)

