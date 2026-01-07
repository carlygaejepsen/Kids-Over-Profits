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

### All Folders with Search
```
[kop_document_library]
```

### All Folders Without Search
```
[kop_document_library show_search="no"]
```

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

### Document Library Page
```
# Document Center

Search all our documents below:

[kop_document_library]
```

---

## Finding Your IDs

### Document ID:
1. Go to Media Library in WordPress
2. Click on your document
3. Look at the browser URL
4. Find `post=123` - the number is your ID

### Folder ID:
1. Go to FileBird folders
2. Click on a folder
3. Look at the browser URL
4. Find `folder=5` - the number is your ID

---

**Still confused?** Just use the simple link style:
```
[kop_document id="123" style="link"]
```

Change `123` to your document's ID and you're done!
