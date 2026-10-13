# Document Library - Visual Examples

Here's what each shortcode looks like and when to use it.

---

## Single Document - Link Style
**Use when:** You want a simple text link to a document

**Code:**
```
[kop_document id="123" style="link"]
```

**Looks like:**
```
[PDF] My Important Report (2.3 MB)
```
*(navy link with a small file type badge and the size; highlighted on hover)*

---

## Single Document - Button Style
**Use when:** You want to emphasize a download or important document

**Code:**
```
[kop_document id="456" style="button" title="Download Now"]
```

**Looks like:**
```
┌──────────────────────────────┐
│  [PDF]  Download Now  (2 MB) │  ← Teal button
└──────────────────────────────┘
```
*(turns navy and lifts slightly on hover)*

---

## Single Document - Card Style
**Use when:** You want a nice visual display (default style)

**Code:**
```
[kop_document id="789"]
```

**Looks like:**
```
┌──────────────────────────────────┐
│ [preview]   Document Title       │
│             1.5 MB               │
└──────────────────────────────────┘
```
*(bordered card: a preview of the first page for PDFs and images, otherwise a
file type badge, then the title and size)*

---

## Single Folder
**Use when:** You want to show all documents from one category

**Code:**
```
[kop_folder folder_id="12" title="Legal Documents"]
```

**Looks like:**
```
Legal Documents (5)
─────────────────────────────────────────────
[All (5)] [Legal filings (4)] [Depositions and discovery (1)]

┌──────────┐ ┌──────────┐ ┌──────────┐
│[preview] │ │[preview] │ │[preview] │
│Complaint │ │Order     │ │Deposition│
│Legal     │ │Legal     │ │Deposition│
│filing    │ │filing    │ │or discov.│
│2.1 MB    │ │1.8 MB    │ │3.2 MB    │
└──────────┘ └──────────┘ └──────────┘
...
```
*(a grid of tiles, documents in subfolders included; each tile has a small
label for its document type. The row of type buttons only appears when the
folder holds two or more types. Add `layout="list"` for one document per row.)*

---

## Documents by Type
**Use when:** You want readers to compare one kind of document across programs

**Link:**
```
https://kidsoverprofits.org/documents/inspection-report/
```

**Looks like:**
```
Inspection and licensing reports
State inspection, licensing, monitoring and complaint investigation reports.
212 documents from 40 programs.

Legal filings · News clippings · Student and resident handbooks · ...  ← other types

Program or title [Name a program, company or word]  State [Every state]  Decade [Any year]

Program A  UT  (3)
   [doc] [doc] [doc]
Program B  MT  (2)
   [doc] [doc]
...
```
*(every document of that type, grouped by program; `/documents/` lists every type)*

---

## Full Library
**Use when:** You want a complete document library

**Link (recommended):**
```
https://kidsoverprofits.org/document-archive/
```

**Looks like:**
```
Start here           a few documents to read first
By type              government reports, legislation, research, handbooks...
Networks             the chains and trade groups with the largest archives
Every program        each program's folder A to Z, with a filter
Recently added       the newest documents filed anywhere
```

The `[kop_document_library]` shortcode (a search box over every folder,
each one opening and closing) still exists, but it lists thousands of folders,
so use the link above instead.

---

## Pull Quote
**Use when:** One line of an article deserves to be read twice

**How:** Logged in as an admin, select the words on the page and press the
**Pull quote** button under them. Or type:
```
[kop_pullquote credit="Name, role"]The words you want set large.[/kop_pullquote]
```

**Looks like:**
```
═══════════════════════════════════════  ← orange rule
                   "
     The words you want set large,
       in a big serif typeface.
          — NAME, ROLE
───────────────────────────────────────  ← teal rule
```
*(across the text column by default; "right" or "left" puts it beside the text
on a computer screen, and on a phone every pull quote runs across)*

---

## Real-World Examples

### Example 1: Resources Page
```markdown
## Download Our Resources

**Annual Reports:**
[kop_document id="101" style="link"]
[kop_document id="102" style="link"]
[kop_document id="103" style="link"]

**Important Form:**
[kop_document id="200" style="button" title="Download Application Form"]
```

### Example 2: Legal Documents Section
```markdown
## Legal Documents

View all our legal documents below:

[kop_folder folder_id="15" title="Legal Documents"]
```

### Example 3: Pointing to the Whole Archive
```markdown
## Read the Documents Yourself

Browse every handbook, inspection report and court filing we hold in the
[Document Archive](https://kidsoverprofits.org/document-archive/), or compare
[every student handbook](https://kidsoverprofits.org/documents/student-handbook/)
program by program.
```

---

## Quick Decision Guide

**"I just need a link to one PDF"**
→ `[kop_document id="X" style="link"]`

**"I want a download button"**
→ `[kop_document id="X" style="button"]`

**"Show everything in the Legal folder"**
→ `[kop_folder folder_id="X"]`

**"Show every handbook from every program"**
→ link to `/documents/student-handbook/`

**"I want a full library"**
→ link to `/document-archive/`

**"Make this sentence stand out"**
→ select it and press **Pull quote**

---

That's it! Pick the style that fits your need, copy the code, change the ID, done!
