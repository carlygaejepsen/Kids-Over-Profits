import os
import json
import re

# Configuration
TTI_EXPORT_PATH = os.path.join('js', 'data', 'facility-projects-export.json')
WIKI_MD_DIR = 'markdown_output'
OUTPUT_PATH = os.path.join('js', 'data', 'combined_index.json')

def load_tti_data(filepath):
    """Loads TTI Program Index data from JSON export."""
    projects = []
    if not os.path.exists(filepath):
        print(f"Warning: TTI export file not found: {filepath}")
        return projects
        
    try:
        with open(filepath, 'r', encoding='utf-8') as f:
            data = json.load(f)
            
        if isinstance(data, dict):
            if 'projects' in data:
                # Format: { "projects": { "id": { ... } } }
                if isinstance(data['projects'], dict):
                    projects = list(data['projects'].values())
                elif isinstance(data['projects'], list):
                    projects = data['projects']
            else:
                # Format: { "id": { ... } }
                projects = list(data.values())
        elif isinstance(data, list):
            projects = data
            
    except Exception as e:
        print(f"Error loading TTI data: {e}")
        
    print(f"Loaded {len(projects)} projects from TTI Index.")
    return projects

def parse_wiki_markdown(content, filename):
    """Extracts key fields from Wiki Markdown files."""
    data = {
        'name': '',
        'location': '',
        'type': '',
        'years_active': '',
        'operator': '',
        'wiki_filename': filename
    }
    
    # Title: # **Title**
    title_match = re.search(r'^# \*\*(.+?)\*\*', content, re.MULTILINE)
    if title_match:
        data['name'] = title_match.group(1).strip()
    else:
        # Fallback to filename slug if header is missing
        data['name'] = filename.replace('.md', '').replace('index_', '').replace('-', ' ').title()

    # Location & Years: ## **Name** (Years) Location
    header_match = re.search(r'^## \*\*.+?\*\*\s*(?:\(([^)]+)\))?\s*([^\r\n]+)?', content, re.MULTILINE)
    if header_match:
        if header_match.group(1):
            data['years_active'] = header_match.group(1).strip()
        if header_match.group(2):
            data['location'] = header_match.group(2).strip()

    # Program Type: *Type*
    type_match = re.search(r'^\*([^\*]+)\*$', content, re.MULTILINE)
    if type_match:
        data['type'] = type_match.group(1).strip()
        
    # Operator: Owned/operated by [Name](Link) or similar
    # Simple check for "Owned by"
    operator_match = re.search(r'(?:owned|operated) by\s+(?:\(([^\)]+)\)|([^\.,\n]+))', content, re.IGNORECASE)
    if operator_match:
        data['operator'] = operator_match.group(1) or operator_match.group(2)

    return data

def normalize_name(name):
    """Normalizes names for matching (lowercase, alphanumeric only)."""
    if not name: return ""
    return re.sub(r'[^a-z0-9]', '', name.lower())

def main():
    print("Starting alignment and merge process...")
    
    # 1. Load Data
    tti_projects = load_tti_data(TTI_EXPORT_PATH)
    
    wiki_entries = []
    if os.path.exists(WIKI_MD_DIR):
        for f in os.listdir(WIKI_MD_DIR):
            if f.endswith('.md'):
                path = os.path.join(WIKI_MD_DIR, f)
                try:
                    with open(path, 'r', encoding='utf-8') as file:
                        wiki_entries.append(parse_wiki_markdown(file.read(), f))
                except Exception as e:
                    print(f"Failed to read {f}: {e}")
    print(f"Loaded {len(wiki_entries)} entries from Wiki Markdown.")

    # 2. Align & Merge
    combined_index = []
    seen_ids = set() # Track normalized names to prevent duplicates
    
    # Processing TTI Index
    for proj in tti_projects:
        # Handle various TTI JSON structures
        facilities = []
        if isinstance(proj, dict):
            if 'data' in proj and 'facilities' in proj['data']:
                facilities = proj['data']['facilities']
            elif 'facilities' in proj:
                facilities = proj['facilities']
            
            # Fallback if flat structure
            if not facilities and ('identification' in proj or 'name' in proj):
                facilities = [proj]
        
        for fac in facilities:
            # key alignment
            name = fac.get('identification', {}).get('name') or fac.get('name') or proj.get('name')
            if not name: continue
            
            entry = {
                'name': name,
                'source': ['tti'],
                'location': fac.get('location') or fac.get('address') or '',
                'type': fac.get('facilityDetails', {}).get('type') or '',
                'operator': proj.get('data', {}).get('operator', {}).get('name') or proj.get('name') or '',
                'years_active': fac.get('operatingPeriod', {}).get('text') or '',
                'website': fac.get('identification', {}).get('website') or '',
                'wiki_file': None
            }
            
            nid = normalize_name(name)
            if nid not in seen_ids:
                combined_index.append(entry)
                seen_ids.add(nid)
    
    # Processing Wiki Data
    for wiki in wiki_entries:
        name = wiki['name']
        if not name: continue
        
        nid = normalize_name(name)
        
        # Check for match in existing index
        matched = False
        for entry in combined_index:
            if normalize_name(entry['name']) == nid:
                matched = True
                entry['source'].append('wiki')
                entry['wiki_file'] = wiki['wiki_filename']
                # Enrich missing data from Wiki
                if not entry['location'] and wiki['location']: entry['location'] = wiki['location']
                if not entry['type'] and wiki['type']: entry['type'] = wiki['type']
                if not entry['years_active'] and wiki['years_active']: entry['years_active'] = wiki['years_active']
                if not entry['operator'] and wiki['operator']: entry['operator'] = wiki['operator']
                break
        
        if not matched:
            entry = {
                'name': name,
                'source': ['wiki'],
                'location': wiki['location'],
                'type': wiki['type'],
                'operator': wiki['operator'],
                'years_active': wiki['years_active'],
                'website': '',
                'wiki_file': wiki['wiki_filename']
            }
            combined_index.append(entry)
            seen_ids.add(nid)

    # 3. Output
    with open(OUTPUT_PATH, 'w', encoding='utf-8') as f:
        json.dump(combined_index, f, indent=2)
        
    print(f"Merged index saved to {OUTPUT_PATH}")
    print(f"Total Combined Entries: {len(combined_index)}")

if __name__ == '__main__':
    main()
