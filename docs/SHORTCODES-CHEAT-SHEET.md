# Document Shortcodes - Ultra Simple Guide

## The Shortcuts You Need

### 1. Show ONE document as a link
```
[kop_document id="123" style="link"]
```
**Most common use** - Change `123` to your document ID

---

### 2. Show ONE folder of documents
```
[kop_folder folder_id="5"]
```
**Second most common** - Change `5` to your folder ID. Documents in its
subfolders are included.

---

### 3. Send readers to the whole library
```
https://kidsoverprofits.org/document-archive/
```
**For a full document page** - link to the Document Archive. (The old
`[kop_document_library]` shortcode lists every folder on the site, thousands
of them, so leave it out.)

---

### 4. Send readers to every document of one kind
```
https://kidsoverprofits.org/documents/student-handbook/
```
**To compare across programs** - every handbook, inspection report, legal
filing and so on, side by side. `https://kidsoverprofits.org/documents/`
lists every type.

---

## Finding IDs is Easy:

**For a document:**
- Media Library → Click document → "Edit more details" → Look at URL → `post=123`

**For a folder:**
- KOP Tools > Sort Media → Click folder → Look at URL → `folder=5`

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

## Pull Quotes (No Code Needed)

To set a line from an article large, like a magazine:
1. Log in as an admin and open the page (pencils on).
2. Select the words.
3. Press the **Pull quote** button that appears under them.
4. Trim the words, add who said it, pick where it sits (across the column,
   or beside the text on the right or left; before the paragraph you picked
   from unless you choose after), and Save. Undo is offered right after.

Works in articles and pages made in the block editor and in a company's
written history on its /operator/ page. To change or remove one later, click
its pencil.

Other ways, all drawn the same:
- In the WordPress editor, add a Pullquote block (type `/pullquote`).
- Type it in: `[kop_pullquote credit="Name"]The words.[/kop_pullquote]`
  (add `align="right"` or `align="left"` to put it beside the text).
- In KOP Tools > Page Text, start each line with `> `; a last line
  `> -- Name` is the credit.

---

**That's really all you need to know!**

Copy, paste, change the numbers, done.
