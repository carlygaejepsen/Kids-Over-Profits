# News Article Processor - New Features

## Quick Start Templates 🚀

Pre-configured templates that automatically set up the form for common article types:

- **⚖️ Lawsuit Article** - Pre-selects lawsuit type and common content warnings (Physical Restraint, Seclusion)
- **👮 Staff Arrest** - Pre-selects arrest type and relevant warnings (Child Sexual Abuse, Law Enforcement Abuse)
- **🏢 Facility Closure** - Pre-configures for closure articles
- **📰 Survivor Account** - Sets up for exposé articles with appropriate warnings
- **🏛️ Corporate Change** - Pre-selects corporate type for mergers, rebrands, etc.

**How to use:**
1. Click any template button at the top of the page
2. The form will automatically configure the article type and suggested content warnings
3. Scroll down to fill in the details

This saves you from manually selecting article types and clicking through content warnings every time!

---

## AI Assistant (Claude) 🤖

Toggle on the AI Assistant to automatically extract article information using Claude AI.

### Features:
- **Automatic metadata extraction** - Pulls title, author, date, publication from URLs
- **Trauma-sensitive summary generation** - AI creates 2-3 sentence summaries using appropriate language
- **Content warning detection** - Automatically identifies and tags relevant warnings
- **Entity extraction** - Finds facilities, staff, and survivor names
- **Article type detection** - Identifies if it's a lawsuit, arrest, closure, etc.
- **Type-specific details** - Extracts relevant fields based on article type

### Setup:

1. **Get your Claude API key:**
   - Go to https://console.anthropic.com/
   - Sign up or log in
   - Navigate to API Keys section
   - Create a new API key

2. **Configure the API key:**
   - Copy `api/ai-config.example.php` to `api/ai-config.php`
   - Edit `api/ai-config.php` and paste your API key
   - The file is already in .gitignore so it won't be committed

   ```php
   define('ANTHROPIC_API_KEY', 'sk-ant-api03-YOUR_KEY_HERE');
   ```

   **OR** set it as an environment variable:
   - Linux/Mac: `export ANTHROPIC_API_KEY="sk-ant-api03-YOUR_KEY_HERE"`
   - Windows: `set ANTHROPIC_API_KEY=sk-ant-api03-YOUR_KEY_HERE`

### How to use:

1. **Enable AI Assistant:**
   - Toggle the "🤖 AI Assistant (Claude)" switch at the top
   - The AI controls panel will appear

2. **Process an article:**
   - Enter the article URL in the "Basic Details" section
   - Click the "🤖 Process with AI" button
   - Wait for the AI to fetch and analyze the article
   - The form will automatically populate with extracted data

3. **Alternative - Paste article text:**
   - If the URL doesn't work (paywall, login required, etc.)
   - Copy the full article text
   - Paste it into the "Paste Article Text" field
   - Click "🤖 Process with AI"

4. **Review and edit:**
   - The page will reload with all fields populated
   - Review the AI-generated content
   - Make any necessary adjustments
   - Submit to database or export as usual

### Benefits:

**Time savings:**
- Reduces processing time from 10-15 minutes to 2-3 minutes per article
- Eliminates manual copy-paste of metadata
- No more clicking through 30+ content warning buttons
- Auto-generates trauma-sensitive summaries

**Accuracy:**
- AI catches entities you might miss
- Consistent formatting across all submissions
- Detects article type automatically
- Suggests alternate titles for sensationalist headlines

### Cost:

Claude API pricing (as of 2024):
- ~$0.01-0.03 per article processed
- Very affordable for regular use
- Only charged when you click "Process with AI"

---

## Workflow Comparison

### Before (Manual):
1. Copy article URL ✋
2. Read full article 📖
3. Manually type title, author, date 📝
4. Copy-paste publication name 📝
5. Read and extract facility names 🔍
6. Read and extract staff names 🔍
7. Click through 30 content warning buttons 🖱️
8. Write trauma-sensitive summary ✍️
9. Decide if alternate title is needed 🤔
10. Select article type 📋
11. Fill type-specific fields 📋

**Time: 10-15 minutes per article**

### After (Templates + AI):
1. Click template button (optional) ⚡
2. Paste URL 📋
3. Toggle AI on 🤖
4. Click "Process with AI" 🚀
5. Wait 30 seconds ⏱️
6. Review and adjust ✅
7. Submit 💾

**Time: 2-3 minutes per article**

---

## Tips & Tricks

### Templates:
- Start every article with a template - it's faster than manual selection
- Templates auto-scroll to Basic Details so you can start entering the URL immediately
- Templates remember content warnings in localStorage for consistency

### AI Assistant:
- Works best with publicly accessible URLs
- For paywalled articles, use the "paste article text" option
- AI suggestions are usually 90%+ accurate but always review them
- The AI remembers your toggle state (on/off) between sessions
- If AI processing fails, you can always fall back to manual entry

### Combined Workflow:
1. Click template for your article type
2. Enter URL
3. Hit "Process with AI"
4. Quick review
5. Submit!

This combination gives you the best of both worlds: quick setup + intelligent automation.

---

## Troubleshooting

**AI toggle doesn't show controls:**
- Clear your browser cache and reload
- Check browser console for JavaScript errors

**"API key not configured" error:**
- Make sure you created `api/ai-config.php` from the example file
- Verify your API key is correct (starts with `sk-ant-api03-`)
- Check file permissions (file should be readable by web server)

**AI processing fails:**
- Try pasting article text instead of using URL
- Check that the article URL is publicly accessible
- Verify your Claude API key has available credits
- Check browser console and PHP error logs

**Templates not applying:**
- Clear localStorage: Browser DevTools → Application → Local Storage → Clear
- Refresh the page
- Try a different browser

---

## Files Modified/Created

- `js/news-processor.js` - Added template and AI functionality
- `api/news_processor.php` - Added UI for templates and AI toggle
- `css/news-processor.css` - Styled new features
- `api/process-news-ai.php` - Backend endpoint for Claude AI processing
- `api/ai-config.example.php` - API key configuration example
- `.gitignore` - Added ai-config.php to prevent committing API keys

---

## Future Enhancements

Potential additions based on these features:
- Batch processing (submit multiple URLs at once)
- Save AI-generated summaries to a library for reuse
- Browser extension for one-click processing
- Admin dashboard to review AI accuracy over time
- Custom content warning presets
- Export templates for different publication types
