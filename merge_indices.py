import os
import json
import re

# Source Paths	ti_index_path = os.path.join('js', 'data', 'facility-projects-export.json')
wiki_dir = 'markdown_output'
output_path = os.path.join('js', 'data', 'combined_index.json')

# 1. Load TTI Program Index
try:
    with open(tti_index_path, 'r', encoding='utf-8') as f:
        tti_data = json.load(f)
        # Handle different export structures
        if isinstance(tti_data, dict) and 'projects' in tti_data:
            tti_projects = list(tti_data['projects'].values())
        elif isinstance(tti_data, dict):
            tti_projects = list(tti_data.values())
        else:
            tti_projects = tti_data
except FileNotFoundError:
    print(f"Warning: {tti_index_path} not found. Starting with empty TTI index.")
    tti_projects = []

# 2. Load Wiki Data from Markdown (parsing front matter/structure)
wiki_programs = []

# Simple markdown parser to extract key fields
def parse_wiki_file(content, filename):
    data = {'source': 'wiki', 'filename': filename}
    
    # Extract Title
    title_match = re.search(r'^# \*\*(.+?)\*\*', content, re.MULTILINE)
    if title_match:
        data['name'] = title_match.group(1).strip()
    else:
        # Fallback to filename slug
        data['name'] = filename.replace('.md', '').replace('index_', '').replace('-', ' ').title()

    # Extract Location
    # Pattern: ## **Name** (Years) Location
    header_match = re.search(r'^# \*\*.+?\*\*\s*(?:\(([^)]+)\))?\s*([^\r\n]+)', content, re.MULTILINE)
    if header_match:
        if header_match.group(1): data['years_active'] = header_match.group(1).strip()
        if header_match.group(2): data['location'] = header_match.group(2).strip()

    # Extract Program Type
    type_match = re.search(r'^\*([^*]+)\*$', content, re.MULTILINE)
    if type_match:
        data['type'] = type_match.group(1).strip()

    return data

for filename in os.listdir(wiki_dir):
    if filename.endswith('.md'):
        try:
            with open(os.path.join(wiki_dir, filename), 'r', encoding='utf-8') as f:
                content = f.read()
                wiki_entry = parse_wiki_file(content, filename)
                wiki_programs.append(wiki_entry)
        except Exception as e:
            print(f"Error parsing {filename}: {e}")

# 3. Align and Merge
combined_index = []
seen_names = set()

# Helper to normalize names for matching
def normalize_name(name):
    if not name: return ""
    return re.sub(r'[^a-z0-9]', '', name.lower())

# Process TTI Projects first
for project in tti_projects:
    # TTI data structure varies, try to normalize to a standard flat structure
    # Expected structure: { data: { facilities: [...] } } or flat object
    
    facilities = []
    if 'data' in project and 'facilities' in project['data']:
        facilities = project['data']['facilities']
    elif 'facilities' in project:
        facilities = project['facilities']
    
    # If no facilities found, maybe the project itself is the facility (flat format)
    if not facilities and 'identification' in project:
        facilities = [project]

    for fac in facilities:
        # Extract name
        name = fac.get('identification', {}).get('name') or fac.get('name') or project.get('name')
        if not name: continue

        # Align fields
        entry = {
            'name': name,
            'source': 'tti_index',
            'location': fac.get('location') or fac.get('address') or '',
            'type': fac.get('facilityDetails', {}).get('type') or '',
            'operator': project.get('data', {}).get('operator', {}).get('name') or project.get('name') or '',
            'years_active': fac.get('operatingPeriod', {}).get('text') or ''
        }
        
        norm_name = normalize_name(name)
        if norm_name not in seen_names:
            combined_index.append(entry)
            seen_names.add(norm_name)

# Merge Wiki Projects
for wiki in wiki_programs:
    name = wiki.get('name')
    if not name: continue
    
    norm_name = normalize_name(name)
    
    if norm_name in seen_names:
        # Update existing entry with wiki link/data if needed
        for entry in combined_index:
            if normalize_name(entry['name']) == norm_name:
                entry['has_wiki'] = True
                entry['wiki_filename'] = wiki['filename']
                if not entry['location'] and wiki.get('location'):
                    entry['location'] = wiki['location']
                break
    else:
        # Add new entry
        entry = {
            'name': name,
            'source': 'wiki',
            'location': wiki.get('location', ''),
            'type': wiki.get('type', ''),
            'years_active': wiki.get('years_active', ''),
            'has_wiki': True,
            'wiki_filename': wiki['filename']
        }
        combined_index.append(entry)
        seen_names.add(norm_name)

# 4. Save Combined Index
with open(output_path, 'w', encoding='utf-8') as f:
    json.dump(combined_index, f, indent=2)

print(f"Successfully merged indices.")
print(f"TTI Projects processed: {len(tti_projects)}")
print(f"Wiki entries processed: {len(wiki_programs)}")
print(f"Total entries in combined index: {len(combined_index)}")
print(f"Saved to: {output_path}")