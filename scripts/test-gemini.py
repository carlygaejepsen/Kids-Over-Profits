"""
Is a Gemini API key usable by this repo? Checks, in order: the key, which
models it can call, and one request shaped exactly as api/ai-providers.php
sends it (JSON reply, the model in GEMINI_MODEL or gemini-3.5-flash-lite).
Says in plain words what is wrong and what to do.

    python scripts/test-gemini.py                 # asks for the key (typing is hidden)
    python scripts/test-gemini.py --model gemini-3.1-flash-lite

The key is read from GEMINI_API_KEY if set, else asked for. It is never
printed or written anywhere.
"""

import argparse
import getpass
import json
import os
import sys
import urllib.error
import urllib.request

API = 'https://generativelanguage.googleapis.com/v1beta'


def call(url, payload=None):
    data = json.dumps(payload).encode() if payload is not None else None
    req = urllib.request.Request(url, data=data, headers={'Content-Type': 'application/json'})
    try:
        with urllib.request.urlopen(req, timeout=90) as r:
            return r.status, json.loads(r.read().decode('utf-8'))
    except urllib.error.HTTPError as e:
        body = e.read().decode('utf-8', errors='replace')
        try:
            return e.code, json.loads(body)
        except ValueError:
            return e.code, {'error': {'message': body[:500]}}


def explain(code, body):
    err = body.get('error', {}) if isinstance(body, dict) else {}
    msg = err.get('message', '')
    status = err.get('status', '')
    reasons = ' '.join(d.get('reason', '') for d in err.get('details', []) if isinstance(d, dict))
    print(f'  HTTP {code} {status}: {msg[:400]}')
    text = (msg + ' ' + reasons).lower()
    if 'api_key_invalid' in text or 'api key not valid' in text:
        print('  -> The key is wrong or was deleted. Make a new one at https://aistudio.google.com/app/apikey '
              'and copy it with no spaces or quotes.')
    elif 'location is not supported' in text:
        print('  -> Google does not offer the Gemini API free tier where this request came from. '
              'A VPN set to another country can cause this too: turn it off and try again.')
    elif code == 403 or 'permission' in text or 'service_disabled' in text or 'has not been used' in text:
        print('  -> The key\'s Google Cloud project cannot use the Gemini API. Easiest fix: make the key in '
              'AI Studio (https://aistudio.google.com/app/apikey), which turns the API on for you. If the key '
              'has "API restrictions" in Google Cloud Console, add "Generative Language API" to them.')
    elif code == 404:
        print('  -> That model name does not exist for this key. Pick one from the list above and set '
              'GEMINI_MODEL=<name> in .env (or run this with --model <name>).')
    elif code == 429:
        if 'limit: 0' in text or 'limit":0' in text:
            print('  -> This model has no free quota on this project (limit 0). Try a Flash-Lite model from the list, '
                  'or the project needs billing turned on for this model.')
        else:
            print('  -> Rate limit or daily quota reached. Wait (per-minute limits clear in a minute, daily ones at '
                  'midnight Pacific) or use another free model.')
    elif code == 400 and 'json' in text:
        print('  -> The model refused JSON mode. Try another model with --model.')


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument('--model', default=os.environ.get('GEMINI_MODEL') or 'gemini-3.5-flash-lite')
    a = ap.parse_args()
    key = (os.environ.get('GEMINI_API_KEY') or '').strip()
    if not key:
        key = getpass.getpass('Gemini API key (typing is hidden): ').strip().strip('"\'')
    if not key:
        print('No key given.')
        return 2

    print('1. Is the key accepted? (listing models)')
    code, body = call(f'{API}/models?pageSize=200&key={key}')
    if code != 200:
        explain(code, body)
        return 1
    names = [m['name'].split('/', 1)[1] for m in body.get('models', [])
             if 'generateContent' in m.get('supportedGenerationMethods', [])]
    flash = [n for n in names if 'flash' in n]
    print(f'  OK. The key can call {len(names)} models. Flash ones (the free tier):')
    for n in sorted(flash):
        print('   ', n)

    print(f'\n2. One request to {a.model}, shaped as api/ai-providers.php sends it')
    if a.model not in names:
        print(f'  "{a.model}" is not in the list above.')
    code, body = call(f'{API}/models/{a.model}:generateContent?key={key}', {
        'contents': [{'parts': [{'text': 'Return JSON only: {"ok": true, "facility": "<the name>"} for this sentence: '
                                          'I was sent to Thayer Learning Center in 2004.'}]}],
        'generationConfig': {'maxOutputTokens': 16384, 'responseMimeType': 'application/json'},
    })
    if code != 200:
        explain(code, body)
        return 1
    parts = (body.get('candidates') or [{}])[0].get('content', {}).get('parts', [])
    text = ''.join(p.get('text', '') for p in parts if not p.get('thought'))
    print(f'  Reply: {text.strip()[:300]}')
    if not text.strip():
        print('  -> Empty reply (finishReason: %s).' % (body.get('candidates') or [{}])[0].get('finishReason'))
        return 1
    print(f'\nWorks. On the server, .env needs:\n  GEMINI_API_KEY=<this key>\n  GEMINI_MODEL={a.model}   (only if not gemini-3.5-flash-lite)')
    return 0


if __name__ == '__main__':
    sys.exit(main())
