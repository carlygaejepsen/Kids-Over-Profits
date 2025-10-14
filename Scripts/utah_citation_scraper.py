<<<<<<< ours
import requests
import json
=======
import csv
>>>>>>> theirs
import time
import os
from datetime import datetime
<<<<<<< ours
import pdfplumber
import re
from io import BytesIO

# EasyOCR imports
try:
    import easyocr
    from pdf2image import convert_from_bytes
    EASYOCR_AVAILABLE = True
except ImportError:
    print("EasyOCR not available. Install with: pip install easyocr pdf2image")
    EASYOCR_AVAILABLE = False

def extract_data_from_text(text, method="text"):
    """Extract census, contact person, and licensor from text using multiple pattern sets"""
    if not text or len(text.strip()) == 0:
        return {'census': None, 'contact_person': None, 'licensor': None}
    
    census = None
    contact_person = None
    licensor = None
    
    if method == "easyocr":
        # OCR-specific pattern for table format
        pattern = r'Present.*?(\d+).*?Capacity'
        match = re.search(pattern, text, re.IGNORECASE | re.DOTALL)
        if match:
            census = int(match.group(1))
            
    else:
        # Original text extraction patterns
        census_pattern1 = re.search(r'Approved # of Present\s*\n\s*(\d+)', text)
        if census_pattern1:
            census = int(census_pattern1.group(1))
        else:
            census_pattern2 = re.search(r'Approved # of Present\s+(\d+)', text)
            if census_pattern2:
                census = int(census_pattern2.group(1))
            else:
                census_pattern3 = re.search(r'Approved # of Present\s+\d+\s+(\d+)', text)
                if census_pattern3:
                    census = int(census_pattern3.group(1))

    # Contact person patterns (work for both methods)
    contact_patterns = [
        r'Name of Individual Informed.*?Inspection:?\s*([^\n\r]+)',
        r'Individual Informed.*?:?\s*([A-Za-z][^\n\r]*)',
    ]
    
    for pattern in contact_patterns:
        match = re.search(pattern, text, re.IGNORECASE)
        if match:
            contact_person = match.group(1).strip()
            contact_person = re.sub(r'\s+', ' ', contact_person)  # Clean up spaces
            break
    
    # Licensor patterns
    licensor_patterns = [
        r'Licensor\(?s?\)?\s*Conducting.*?Inspection:?\s*([^\n\r]+?)(?:\s+OL Staff|$)',
        r'Licensor.*?:?\s*([A-Za-z][^\n\r]*?)(?:\s+OL Staff|$)',
    ]
    
    for pattern in licensor_patterns:
        match = re.search(pattern, text, re.IGNORECASE)
        if match:
            licensor = match.group(1).strip()
            licensor = re.sub(r'\s+', ' ', licensor)  # Clean up spaces
            break
    
    return {
        'census': census,
        'contact_person': contact_person,
        'licensor': licensor
    }

def extract_checklist_data(pdf_content):
    """Extract data with EasyOCR fallback ONLY when regular extraction completely fails"""
    try:
        with pdfplumber.open(BytesIO(pdf_content)) as pdf:
            if len(pdf.pages) == 0:
                return {'census': None, 'contact_person': None, 'licensor': None, 'extraction_method': 'no_pages'}
            
            # Try regular text extraction first
            first_page = pdf.pages[0]
            text = first_page.extract_text()
            
            if text and len(text.strip()) > 0:
                result = extract_data_from_text(text, method="text")
                
                # If we got ANY useful data from regular extraction, use it and don't try OCR
                if result['census'] is not None or result['contact_person'] is not None or result['licensor'] is not None:
                    result['extraction_method'] = 'text'
                    return result
            
            # ONLY try OCR if regular extraction got absolutely nothing useful
            print("      Regular extraction failed completely, trying OCR...")
            
            if EASYOCR_AVAILABLE:
                try:
                    # Initialize EasyOCR reader once
                    if not hasattr(extract_checklist_data, '_reader'):
                        print("      Initializing EasyOCR...")
                        extract_checklist_data._reader = easyocr.Reader(['en'])
                    
                    # Convert first page to image
                    images = convert_from_bytes(pdf_content, first_page=1, last_page=1, dpi=300)
                    if images:
                        import numpy as np
                        from PIL import Image
                        
                        pil_image = images[0]
                        
                        # Try different rotations
                        rotations = [0, 90, 180, 270]
                        best_result = None
                        best_text = ""
                        best_angle = 0
                        
                        for angle in rotations:
                            rotated_img = pil_image.rotate(angle, expand=True)
                            img_array = np.array(rotated_img)
                            
                            results = extract_checklist_data._reader.readtext(img_array)
                            ocr_text = ' '.join([result[1] for result in results])
                            
                            if len(ocr_text) > len(best_text):
                                best_text = ocr_text
                                best_angle = angle
                                
                                test_result = extract_data_from_text(ocr_text, method="easyocr")
                                if (test_result['census'] is not None or 
                                    test_result['contact_person'] is not None or 
                                    test_result['licensor'] is not None):
                                    best_result = test_result
                                    break
                        
                        if best_text and len(best_text.strip()) > 0:
                            print(f"      EasyOCR extracted {len(best_text)} characters (rotation: {best_angle}°)")
                            
                            if best_result:
                                result = best_result
                            else:
                                result = extract_data_from_text(best_text, method="easyocr")
                            
                            result['extraction_method'] = f'easyocr_rotated_{best_angle}'
                            return result
                        else:
                            print("      EasyOCR found no text at any rotation")
                            
                except Exception as ocr_error:
                    print(f"      EasyOCR failed: {ocr_error}")
            
            # Return empty result if everything failed
            return {'census': None, 'contact_person': None, 'licensor': None, 'extraction_method': 'all_failed'}
                
    except Exception as e:
        print(f"      Error parsing PDF: {e}")
        return {'census': None, 'contact_person': None, 'licensor': None, 'extraction_method': 'error'}

def download_with_retry(url, max_attempts=3, timeout=30):
    """Download with retry logic and longer timeout"""
    for attempt in range(max_attempts):
        try:
            response = requests.get(url, timeout=timeout)
            response.raise_for_status()
            return response
        except requests.exceptions.ReadTimeout:
            print(f"      Timeout on attempt {attempt + 1}/{max_attempts}")
            if attempt == max_attempts - 1:
                return None
            time.sleep(5)  # Wait before retry
        except requests.exceptions.RequestException as e:
            print(f"      Request failed on attempt {attempt + 1}/{max_attempts}: {e}")
            if attempt == max_attempts - 1:
                return None
            time.sleep(2)
=======
from pathlib import Path

import requests
>>>>>>> theirs

# Configuration
FACILITY_IDS = [
    96697, 93201, 93220, 93242, 93243, 93266,93245, 99864, 94203, 94202,
    93281, 112281, 93761, 93248, 93247, 93323, 93321, 99192,
    93341, 93342, 99846, 93343, 94407, 93403,
    93420, 93421, 93408, 93244, 93860,
    93412,  93413, 93443, 93981, 93636, 93416, 93415, 93414, 
    93501, 93484, 95545, 110140, 93487,
    117274, 117277, 93488, 94923, 93490,
    99843, 93491, 98769, 105460, 94205,
    98822, 93493, 93494, 93503, 93496, 93521, 93522, 93524, 99506,
    98834, 101496, 93527, 93528, 93529, 93530, 93533, 93531, 93532, 93534,
    93541, 99011, 98019, 97996, 97576, 95546, 95960, 93711, 93712, 95041,
    93542, 93537, 93823, 95810, 119530, 119535, 119533, 93560, 93640, 
    93623, 93624, 94216, 93625,  93635, 93661, 93662, 93637, 93639,
    93660, 98533, 99272, 99058, 98507, 98194, 106078, 93666,
    107485, 98883, 93687, 93686, 93688, 94380, 96994, 93692,
    93694, 94206, 93695, 93696, 93697, 98254, 93700, 98250, 93698, 93699,
    110301, 93701, 93703,
    93702, 93241, 105000, 93262, 93264, 93261, 93263, 93704, 93708,
    95883, 93715, 93940, 111725, 93717,
    93721, 104399, 93762, 93724, 93728, 93727, 93725, 93726, 93763]  

<<<<<<< ours
OUTPUT_FILE = rf".\ut_reports_with_ocr.json"
REQUEST_DELAY = 1  # Seconds between requests
MAX_INSPECTIONS = 100  # Maximum number of inspections per facility to include
=======
BASE_DIR = Path(__file__).resolve().parent
OUTPUT_DIR = BASE_DIR / "middle"
OUTPUT_DIR.mkdir(exist_ok=True)
OUTPUT_FILE = OUTPUT_DIR / f"utah_citations_{datetime.now().strftime('%m-%d-%Y')}.csv"
REQUEST_DELAY = 1  # Seconds between requests
MAX_INSPECTIONS = 20  # Maximum number of inspections per facility to include
>>>>>>> theirs

def fetch_facility_data(facility_id):
    """Fetch JSON data for a single facility with robust error handling"""
    url = f"https://ccl.utah.gov/ccl/public/facilities/{facility_id}.json"
    
    response = download_with_retry(url)
    if response:
        print(f"🔍 Fetching {facility_id}... ✅ Success")
        return response.json()
    else:
        print(f"🔍 Fetching {facility_id}... ❌ Failed after retries")
        return None

def format_address(address):
    """Convert address dict to single string"""
    parts = [
        address.get('addressOne', ''),
        address.get('city', ''),
        address.get('state', ''),
        address.get('zipCode', '')
    ]
    return ', '.join(filter(None, parts))

def main():
    print(f"🚀 Starting data export with OCR fallback ({len(FACILITY_IDS)} facilities)")
    
    facilities_data = []
    
    for facility_id in FACILITY_IDS:
        data = fetch_facility_data(facility_id)
        if not data:
            continue
        
        # Build facility record
        facility_record = {
            'facility_id': facility_id,
            'name': data.get('name', ''),
            'address': format_address(data.get('address', {})),
            'regulation_date': data.get('initialRegulationDate', ''),
            'expiration_date': data.get('expirationDate', ''),
            'conditional': data.get('conditional', False),
            'inspections': []
        }
        
        # Add inspections (up to MAX_INSPECTIONS)
        inspections = data.get('inspections', [])[:MAX_INSPECTIONS]
        for inspection in inspections:
            findings = []
            for finding in inspection.get('findings', []):
                findings.append({
                    'rule_number': finding.get('ruleNumber', ''),
                    'rule_description': finding.get('ruleDescription', ''),
                    'finding_text': finding.get('findingText', '')
                })
            
            inspection_record = {
                'inspection_date': inspection.get('inspectionDate', ''),
                'inspection_types': inspection.get('inspectionTypes', ''),
                'findings': findings,
                'checklists': []
            }
            
            # Process checklists for this inspection
            checklist_ids = inspection.get('checklistIds', [])
            print(f"  Found {len(checklist_ids)} checklists to process")
            
            for checklist_id in checklist_ids:
                try:
                    pdf_url = f"https://ccl.utah.gov/ccl/public/checklist/{checklist_id}?dl=1"
                    pdf_response = download_with_retry(pdf_url)
                    
                    if pdf_response:
                        # Extract data from PDF
                        checklist_data = extract_checklist_data(pdf_response.content)
                        checklist_data['checklist_id'] = checklist_id
                        
                        # Save PDF file for records
                        os.makedirs("checklists", exist_ok=True)
                        pdf_filename = f"checklists/facility_{facility_id}_checklist_{checklist_id}.pdf"
                        with open(pdf_filename, "wb") as f:
                            f.write(pdf_response.content)
                        checklist_data['pdf_file'] = pdf_filename
                        
                        inspection_record['checklists'].append(checklist_data)
                        print(f"    📋 Checklist {checklist_id}: Census={checklist_data['census']}, Method={checklist_data.get('extraction_method', 'unknown')}")
                    else:
                        print(f"    ❌ Failed to download checklist {checklist_id} after retries")
                        
                except Exception as e:
                    print(f"    ❌ Error with checklist {checklist_id}: {e}")
            
            facility_record['inspections'].append(inspection_record)
        
        facilities_data.append(facility_record)
        time.sleep(REQUEST_DELAY)
    
    # Write JSON file
    with open(OUTPUT_FILE, 'w', encoding='utf-8') as jsonfile:
        json.dump(facilities_data, jsonfile, indent=2, ensure_ascii=False)
    
    print(f"\n🎉 Done! Data saved to {OUTPUT_FILE}")
    print(f"📊 Exported {len(facilities_data)} facilities")
    print("💡 Pro tip: Use a JSON viewer or 'python -m json.tool' for pretty printing")

if __name__ == "__main__":
    main()