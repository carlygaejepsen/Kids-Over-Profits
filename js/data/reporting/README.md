# Reporting directory data

Where somebody reports an abusive therapist or an abusive program, state by
state. Every record here is something a survivor or a parent is meant to act
on, so a wrong number is worse than no number: nothing goes in without a
source and a `verified_on` date.

## Files

```
national.json        Bodies that are the same wherever the program is:
                     crisis lines, federal channels, accreditors, and the
                     ethics boards of the professional associations.
states/<abbr>.json   One file per state, lower-case two-letter abbreviation
                     ("ut.json", "dc.json"). A state with no file yet is
                     reported as missing by the build; it is never rendered
                     as an empty state.
directory.json       Generated. Do not edit. Built from the files above by
                     scripts/build-reporting-directory.js.
```

## Commands

```bash
# After editing national.json or anything in states/
node scripts/build-reporting-directory.js

# Check every link and flag what has gone stale (slow, hits the network)
node scripts/verify-reporting-links.js
node scripts/verify-reporting-links.js --state ut     # one state
```

The build validates every record and fails on a bad one rather than shipping
it. `tmp/reporting-qa.md` (gitignored) is rewritten each build with the
coverage table and every warning.

## A state file

```json
{
  "state": "Utah",
  "abbr": "UT",
  "updated": "2026-09-22",
  "note": "Optional. One paragraph on anything peculiar to this state.",
  "channels": [ ... ]
}
```

## A channel

Only `id`, `category`, `name`, `what_it_can_do`, `verified_on` and `sources`
are required. Everything else is included when it is known and true; an empty
string is not a value, so leave the key out instead.

| Field | What it holds |
| --- | --- |
| `id` | Stable slug, prefixed with the state: `ut-dopl-psychology`. Used in URLs and anchors, so it must not change once published. |
| `category` | One of `professional-board`, `facility-licensing`, `legal`, `oversight`. |
| `profession` | For `professional-board` only: which licence this board holds. Use the labels in `PROFESSIONS` in the build script. |
| `name` | The body's own name for itself, not an abbreviation nobody uses. |
| `what_it_can_do` | The point of the whole file. What power this body actually has - revoke a licence, open a criminal case, close a facility. One or two sentences, plain. |
| `what_it_cannot_do` | Where it is worth saying so. "It cannot award you damages" saves somebody a month. |
| `who_to_report` | Who this channel is the right one for: a named licensed clinician, an unlicensed staff member, the facility itself. |
| `how` | The steps, briefly. Online form, downloadable PDF, phone intake, notarised affidavit. |
| `complaint_url` | The page that starts a complaint. Prefer the form over the department home page. |
| `info_url` | Background reading, when the complaint URL is a bare form. |
| `phone` | Digits as the agency prints them: `801-530-6628`, `1-855-323-3237`. |
| `phone_note` | Hours, or which option to press. |
| `email` | Only if the agency publishes it for complaints. |
| `mail` | Postal address, for the boards that still require paper. |
| `anonymous` | `allowed`, `discouraged`, `not-allowed`, or `unknown`. Many boards will not act on an anonymous complaint; people deserve to know that before they file one. |
| `mandatory_reporter` | `true` where this is the channel a mandatory reporter is required by law to use. |
| `deadline` | Any filing deadline or statute of limitation the body itself states. Never inferred. |
| `verified_on` | `YYYY-MM-DD`, the day a human last confirmed the number and link. |
| `sources` | One or more URLs the details came from. The agency's own page wherever possible. |
| `note` | Anything else worth one line. |

## Rules

- **Source everything.** A channel with no `sources` fails the build.
- **Never infer a phone number** from a pattern or an old copy of a page. If
  the agency's current page does not print it, leave `phone` out.
- **Do not paraphrase legal advice.** `what_it_can_do` describes the body's
  authority, not what somebody should do about their case.
- **Re-verify yearly.** The build warns on anything older than 365 days and
  the page prints the date, so a reader can judge it for themselves.
- **Check the domain is still the agency's.** A link checker cannot catch
  this: `ncswboard.org` lapsed and now serves gambling spam, while the real
  North Carolina social work board is at `ncswboard.gov`. Prefer `.gov`, and
  when a board uses something else, confirm the domain from a `.gov` page
  that links to it.

## What the link checker's categories mean

`verify-reporting-links.js` separates four kinds of trouble, because only the
first two need a record changed:

- **Broken** - a 404 or no answer. Find where the form went, or drop it.
- **Bounced to a site root** - answers 200, but the agency redirected it to
  its homepage, which normally means the page is gone.
- **Refused by a bot filter** - a WAF turned the checker away (403, 429).
  The page is almost certainly fine; open it in a browser rather than
  deleting the record.
- **TLS chain misconfigured** - the agency's server omits its intermediate
  certificate. Browsers fetch the missing one themselves so readers are
  unaffected, but strict clients refuse it. Worth reporting to the agency;
  not a reason to remove the link.
