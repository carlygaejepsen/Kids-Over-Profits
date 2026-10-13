# Document Library - Simple Usage Guide

Quick reference for displaying documents and folders without the complexity.

## Display a Single Document

### Option 1: Card Style (Default)
Shows the document as a nice card with a preview (or file type badge) and file info.

```
[kop_document id="123"]
```

### Option 2: Simple Link
Just a text link with the document name.

```
[kop_document id="123" style="link"]
```

### Option 3: Button Style
Displays as a clickable button.

```
[kop_document id="123" style="button"]
```

### Custom Options

```
[kop_document id="123" title="My Custom Title" show_size="no" show_icon="no"]
```

**Available options:**
- `id` - **Required** - The WordPress media library ID of your document
- `style` - `card` (default), `link`, or `button`
- `title` - Custom title (defaults to the document's name)
- `show_size` - `yes` (default) or `no` - Show file size
- `show_icon` - `yes` (default) or `no` - Show the file type badge (PDF, DOCX...) on links and buttons

## Display a Single Folder

Shows all documents from one FileBird folder, including the documents in its
subfolders, as one set of tiles.

```
[kop_folder folder_id="5"]
```

`[filebird_folder ...]` is the same shortcode under its older name.

### With Custom Title

```
[kop_folder folder_id="5" title="Important Resources"]
```

### Grid or List Layout

```
[kop_folder folder_id="5" layout="grid"]
[kop_folder folder_id="5" layout="list"]
```

### Hide Document Count

```
[kop_folder folder_id="5" show_count="no"]
```

### Keep the Subfolders (Like a Facility Page)

```
[kop_folder folder_id="5" merge="name"]
```

This is how facility and company pages show their documents: subfolders stay
as sections you can open and close, and other folders with the same name
elsewhere in the media library are pulled in too.

### What Every Tile Shows

- Each document tile carries a small label for its type ("Student or resident
  handbook", "Legal filing"...), once it has one.
- When a folder holds two or more types, a row of buttons appears above it so
  a reader can show one type at a time, with a link to the same type from
  other programs.

## Display the Full Library

For a whole library, link to the Document Archive:
`https://kidsoverprofits.org/document-archive/`. It has documents to read
first, collections by type and by network, every program A to Z, and the
newest additions.

The all-folders shortcode still exists:

```
[kop_document_library]
```

It lists every folder in the media library, which is thousands, so it is slow
and hard to use. Use the Document Archive link or a `[kop_folder]` instead.
If you do use it, these options work: `show_search="no"`, `show_count="no"`,
`layout="list"`.

## Documents by Type

Every document in the media library has a type, so one kind of paper can be
read side by side across programs.

- `https://kidsoverprofits.org/documents/` lists every type with its count.
- `https://kidsoverprofits.org/documents/<type>/` lists every document of that
  type, grouped by program, with a filter by name, state and decade.

Link to one from any page, for example:

```
Read [every student handbook we hold](https://kidsoverprofits.org/documents/student-handbook/), program by program.
```

**The type names:**
- The programs' own papers: `student-handbook`, `parent-handbook`,
  `staff-manual`, `enrollment`, `marketing`, `newsletter`, `website-capture`
- Oversight: `inspection-report`, `incident-report`, `government-report`,
  `legislation`
- Courts: `legal-filing`, `deposition`
- Press, research and survivors: `news-clipping`, `woodbury`, `research`,
  `survivor-account`
- Business and industry: `business-record`, `tax-filing`, `natsap-profile`,
  `directory`, `staff-profile`
- `other`

### How a Document Gets Its Type

You usually do not have to do anything:
- Rules type most documents on their own every hour, from the folder they
  are filed in and plain titles ("Deposition of ...", "NATSAP Profile").
- The AI reads the rest; its guesses wait at KOP Tools > Review inbox >
  Document types until you approve them.

To set or change one yourself:
- On the page, click the pencil on a document's tile and tick every type it
  is (most are one).
- Or go to KOP Tools > Document Types to see the counts, tag many documents
  at once, and undo recent changes.

## How to Find IDs

### For Documents:
1. Go to WordPress Media Library
2. Click on a document, then "Edit more details"
3. Look at the URL in your browser: `post=123` - that's your ID

### For Folders:
1. Go to KOP Tools > Sort Media
2. Click on a folder in the list on the left
3. Look at the URL: `...?folder=5` - that's your folder ID

Or open the Document Archive, click a collection, and read the number after
`?collection=` in the URL.

## Real Examples

### Link to a PDF Report
```
[kop_document id="456" style="link"]
```

### Download Button for Facility Data
```
[kop_document id="789" style="button" title="Download Facility List"]
```

### Show All Legal Documents Folder
```
[kop_folder folder_id="12" title="Legal Documents"]
```

### Multiple Documents in a Row
```
Download our resources:
[kop_document id="101" style="link"]
[kop_document id="102" style="link"]
[kop_document id="103" style="link"]
```

## Tips

- **Can't remember IDs?** Write them down in a note when you upload documents
- **Testing?** Try `style="link"` first - it's the simplest
- **Want it fancy?** Use `style="card"` with images or PDFs
- **Need a button?** Use `style="button"` for calls-to-action
- **Want readers to compare?** Link to `/documents/<type>/` instead of
  listing the files one by one

That's it! No complicated syntax - just copy, paste, and change the ID number.
