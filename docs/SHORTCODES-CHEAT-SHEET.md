# Document Shortcodes - Ultra Simple Guide

## The Three Shortcuts You Need

### 1. Show ONE document as a link
```
[kop_document id="123" style="link"]
```
👆 **Most common use** - Change `123` to your document ID

---

### 2. Show ONE folder of documents
```
[kop_folder folder_id="5"]
```
👆 **Second most common** - Change `5` to your folder ID

---

### 3. Show ALL folders with search
```
[kop_document_library]
```
👆 **For a full document page**

---

## That's It!

### Finding IDs is Easy:

**For a document:**
- Media Library → Click document → Look at URL → `post=123`

**For a folder:**
- FileBird → Click folder → Look at URL → `folder=5`

---

## Want Buttons Instead?

Just add `style="button"`:
```
[kop_document id="123" style="button"]
```

## Want Custom Text?

Add `title="whatever"`:
```
[kop_document id="123" style="button" title="Download Report"]
```

---

**That's literally all you need to know!** 🎉

Copy, paste, change the numbers, done.
