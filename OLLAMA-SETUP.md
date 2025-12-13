# Ollama Setup Guide - 100% Free AI

Ollama is the **recommended free AI option** for the News Article Processor. It runs on your computer, is completely free, and has unlimited usage!

## Why Ollama?

✅ **Completely Free** - No API costs ever
✅ **Unlimited Usage** - Process as many articles as you want
✅ **Private** - All processing happens on your computer
✅ **No API Key Needed** - No signup required
✅ **Offline** - Works without internet (after initial download)
✅ **Fast** - Runs locally, no network latency

## Quick Setup (5 minutes)

### Step 1: Install Ollama

**Windows:**
1. Go to https://ollama.com/download
2. Download the Windows installer
3. Run the installer
4. Ollama will start automatically

**Mac:**
1. Go to https://ollama.com/download
2. Download the Mac app
3. Drag to Applications folder
4. Open Ollama from Applications

**Linux:**
```bash
curl -fsSL https://ollama.com/install.sh | sh
```

### Step 2: Download a Model

Open your terminal (Command Prompt on Windows, Terminal on Mac/Linux) and run:

```bash
ollama pull llama3.2
```

This downloads the Llama 3.2 model (~2GB). It's fast and great for text processing.

**Alternative models you can try:**
- `ollama pull mistral` - Mistral 7B (good alternative)
- `ollama pull gemma2` - Google's Gemma 2 (also good)
- `ollama pull llama3.2:1b` - Smaller/faster version (if you have limited RAM)

### Step 3: Start Ollama

Ollama should start automatically after installation, but if not:

```bash
ollama serve
```

Leave this terminal window open while using the news processor.

### Step 4: Use in News Processor

1. Open the News Article Processor in your browser
2. Toggle on "🤖 AI Assistant"
3. Select **"Ollama (Free, Local, Unlimited)"** from the dropdown
4. Paste your article URL
5. Click "🤖 Process with AI"
6. Wait 30-60 seconds for processing

That's it! 🎉

## System Requirements

**Minimum:**
- 8GB RAM
- 4GB free disk space
- Modern CPU (any processor from last 5 years)

**Recommended:**
- 16GB RAM (for faster processing)
- SSD storage

## Troubleshooting

### Error: "Ollama is not running"

**Solution:** Start Ollama by running:
```bash
ollama serve
```

### Error: "Model not found"

**Solution:** Make sure you've downloaded a model:
```bash
ollama pull llama3.2
```

### Processing is slow

**Solutions:**
1. Try a smaller model: `ollama pull llama3.2:1b`
2. Close other applications to free up RAM
3. Restart Ollama: `ollama serve`

### Want to see what models you have?

```bash
ollama list
```

### Want to remove a model?

```bash
ollama rm llama3.2
```

## Comparing to Other Free Options

| Provider | Cost | Speed | Limits | Setup Complexity |
|----------|------|-------|--------|------------------|
| **Ollama** | Free | Fast | Unlimited | Easy (5 min) |
| Groq | Free | Very Fast | 14,400/day | Easy (get API key) |
| Gemini | Free | Medium | 60/min | Easy (get API key) |
| Hugging Face | Free | Slow | Rate limited | Medium (get API key) |

**Verdict:** Ollama is the best overall option for unlimited free usage!

## Advanced: Switching Models

If you want to use a different model, edit `api/process-news-ai.php` line 253:

```php
'model' => 'llama3.2', // Change this to 'mistral', 'gemma2', etc.
```

## Getting Help

- Ollama Docs: https://github.com/ollama/ollama
- Ollama Discord: https://discord.gg/ollama
- Model Library: https://ollama.com/library

## Summary

Ollama gives you **unlimited free AI processing** with no API costs, no rate limits, and no privacy concerns. Perfect for processing as many news articles as you need!
