# Document Library - Simple Usage Guide

Quick reference for displaying documents and folders without the complexity.

## Display a Single Document

### Option 1: Card Style (Default)
Shows the document as a nice card with icon/thumbnail and file info.

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
- `show_icon` - `yes` (default) or `no` - Show file type icon

## Display a Single Folder

Shows all documents from one FileBird folder.

```
[kop_folder folder_id="5"]
```

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

## Display Full Library

Shows all folders with search functionality.

```
[kop_document_library]
```

### Without Search

```
[kop_document_library show_search="no"]
```

### List Layout Instead of Grid

```
[kop_document_library layout="list"]
```

## How to Find IDs

### For Documents:
1. Go to WordPress Media Library
2. Click on a document
3. Look at the URL in your browser: `post=123` - that's your ID

### For Folders:
1. Install FileBird Pro plugin
2. Create/view your folders
3. Click on a folder in FileBird
4. Look at the URL: `...&folder=5` - that's your folder ID

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

That's it! No complicated syntax - just copy, paste, and change the ID number.
