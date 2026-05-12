import json, glob

for f in glob.glob('js/data/ccl*.json'):
    for r in json.load(open(f, encoding='utf-8')):
        for k in list(r.keys()):
            v = r[k]
            if isinstance(v, str) and 'Jeannette' in v:
                fid = r.get('facility_id') or r.get('facility_number') or '?'
                print(k + ' | fid=' + str(fid) + ' | ' + v[:300])
