# Document Library - Visual Examples

Here's what each shortcode looks like and when to use it.

---

## 🔗 Single Document - Link Style
**Use when:** You want a simple text link to a document

**Code:**
```
[kop_document id="123" style="link"]
```

**Looks like:**
```
📄 PDF My Important Report (2.3 MB)
```
*(clickable link with file icon and size)*

---

## 🔘 Single Document - Button Style
**Use when:** You want to emphasize a download or important document

**Code:**
```
[kop_document id="456" style="button" title="Download Now"]
```

**Looks like:**
```
┌────────────────────────┐
│  PDF  Download Now     │  ← Blue button
└────────────────────────┘
```
*(clickable button with hover effect)*

---

## 📦 Single Document - Card Style
**Use when:** You want a nice visual display (default style)

**Code:**
```
[kop_document id="789"]
```

**Looks like:**
```
┌─────────────────────────┐
│   [PDF Icon/Thumbnail]  │
│                         │
│   Document Title        │
│   1.5 MB               │
└─────────────────────────┘
```
*(card with border, icon, title, and size)*

---

## 📁 Single Folder
**Use when:** You want to show all documents from one category

**Code:**
```
[kop_folder folder_id="12" title="Legal Documents"]
```

**Looks like:**
```
Legal Documents (5)

📄 Document 1.pdf      2.1 MB
📄 Document 2.pdf      1.8 MB  
📄 Document 3.docx     450 KB
📄 Document 4.pdf      3.2 MB
📄 Document 5.pdf      1.1 MB
```
*(expandable folder with all documents listed)*

---

## 📚 Full Library
**Use when:** You want a complete searchable document library

**Code:**
```
[kop_document_library]
```

**Looks like:**
```
┌────────────────────────────────┐
│ Search folders and documents...|  ← Search box
└────────────────────────────────┘

📁 Legal Documents (5)        ▼
📁 Reports (12)               ▼
📁 Forms (8)                  ▼
📁 Media (23)                 ▼
```
*(searchable, collapsible folders)*

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

### Example 3: Full Document Center
```markdown
# Document Library

Search and browse all available documents:

[kop_document_library]
```

---

## Quick Decision Guide

**"I just need a link to one PDF"**  
→ `[kop_document id="X" style="link"]`

**"I want a download button"**  
→ `[kop_document id="X" style="button"]`

**"Show everything in the Legal folder"**  
→ `[kop_folder folder_id="X"]`

**"I want a full searchable library"**  
→ `[kop_document_library]`

---

That's it! Pick the style that fits your need, copy the code, change the ID, done! 🎉
