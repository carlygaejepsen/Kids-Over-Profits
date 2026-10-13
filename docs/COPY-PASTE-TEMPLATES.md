# Quick Copy-Paste Templates

Just copy one of these, paste into your WordPress page, and change the ID number!

---

## Single Document Templates

### Simple Link (Recommended for Most Cases)
```
[kop_document id="YOUR_ID_HERE" style="link"]
```
**Example:**
```
[kop_document id="123" style="link"]
```

---

### Download Button (Good for Downloads)
```
[kop_document id="YOUR_ID_HERE" style="button" title="Download Now"]
```
**Example:**
```
[kop_document id="456" style="button" title="Download PDF Report"]
```

---

### Card Style (Pretty Display)
```
[kop_document id="YOUR_ID_HERE"]
```
**Example:**
```
[kop_document id="789"]
```

---

## Folder Templates

### Basic Folder
```
[kop_folder folder_id="YOUR_FOLDER_ID"]
```
**Example:**
```
[kop_folder folder_id="5"]
```

The folder shows every document in it and in its subfolders.

---

### Folder with Custom Title
```
[kop_folder folder_id="YOUR_FOLDER_ID" title="Your Custom Title"]
```
**Example:**
```
[kop_folder folder_id="12" title="Legal Documents"]
```

---

### Folder as a List
```
[kop_folder folder_id="YOUR_FOLDER_ID" layout="list"]
```

---

## Full Library Template

For a whole library, link to the Document Archive instead of building one:
`https://kidsoverprofits.org/document-archive/`. It already has "Start here",
documents by type, every program A to Z and the newest additions.

The old all-folders shortcode still works, but it lists every folder in the
media library (thousands of them), so keep it off public pages:
```
[kop_document_library]
```

---

## Documents of One Type

Every document has a type (student handbook, inspection report, legal filing,
news clipping...). To send readers to every document of one kind, side by side
across programs, link to its page:
```
https://kidsoverprofits.org/documents/student-handbook/
```
`https://kidsoverprofits.org/documents/` lists every type. See
DOCUMENT-LIBRARY-SIMPLE-GUIDE.md for the full list of type names.

---

## Pull Quote

The easy way needs no code at all: while logged in as an admin, open the page,
select the words, and press the **Pull quote** button that appears under them.
Save offers Undo; the pencil on the pull quote changes or removes it later.
In the WordPress editor, the Pullquote block (type `/pullquote`) looks the same.

If you would rather type it into the page, use this:
```
[kop_pullquote credit="Name, role"]The words you want set large.[/kop_pullquote]
```
**Beside the text, on the right:**
```
[kop_pullquote credit="Name, role" align="right"]The words you want set large.[/kop_pullquote]
```
Use `align="left"` for the left side. Beside the text only on a wide screen;
on a phone it runs across the column.

---

## Common Combinations

### Resources Section
```
## Our Resources

Download helpful documents:

[kop_document id="101" style="link"]
[kop_document id="102" style="link"]
[kop_document id="103" style="link"]
```

### Featured Document with Button
```
## Important Notice

[kop_document id="555" style="button" title="Read the Full Report"]
```

### One Folder on Its Own Page
```
# Legal Documents

Every filing we hold in this case:

[kop_folder folder_id="12" title="Court Filings"]
```

---

## Finding Your IDs

### Document ID:
1. Go to Media Library in WordPress
2. Click on your document, then "Edit more details"
3. Look at the browser URL
4. Find `post=123` - the number is your ID

### Folder ID:
1. Go to KOP Tools > Sort Media
2. Click your folder in the list on the left
3. Look at the browser URL
4. Find `folder=5` - the number is your ID

Or open the Document Archive, click a collection, and read the number after
`?collection=` in the browser URL.

---

**Still confused?** Just use the simple link style:
```
[kop_document id="123" style="link"]
```

Change `123` to your document's ID and you're done!
