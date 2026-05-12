import json, glob

for f in glob.glob('js/data/**/*.json', recursive=True):
    try:
        raw = open(f, encoding='utf-8').read()
        if 'Prandi' in raw:
            print('FOUND: ' + f)
    except Exception as e:
        pass

# Also check programs-array.json and search-index.json which may be at root of js/data
for f in ['js/data/programs-array.json', 'js/data/search-index.json', 'js/data/index.json']:
    try:
        raw = open(f, encoding='utf-8').read()
        if 'Prandi' in raw:
            print('FOUND: ' + f)
        if '94903' in raw:
            print('ZIP 94903 FOUND: ' + f)
    except: pass
