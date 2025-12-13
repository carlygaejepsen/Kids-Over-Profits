# Free AI Setup Guide - News Article Processor

You now have **4 free AI options** to choose from! No need to pay per request.

## 🏆 Best Option: Ollama (Recommended)

**100% Free | Unlimited | No API Key**

- Runs on your computer
- Unlimited article processing
- Completely free forever
- No signup required

**Quick Setup:**
1. Download from https://ollama.com/
2. Run: `ollama pull llama3.2`
3. Run: `ollama serve`
4. Select "Ollama" in the processor dropdown

📖 **Full guide:** See [OLLAMA-SETUP.md](OLLAMA-SETUP.md)

---

## 🚀 Option 2: Groq

**Free Tier | Very Fast | 14,400 requests/day**

Perfect if you want cloud-based AI with speed.

**Setup:**
1. Go to https://console.groq.com/
2. Sign up (free, no credit card)
3. Create API key
4. Add to `api/ai-config.php`:
   ```php
   define('GROQ_API_KEY', 'gsk_YOUR_KEY_HERE');
   ```
5. Select "Groq" in the processor dropdown

**Free tier:** 14,400 requests per day = ~500 articles/day

---

## 🌐 Option 3: Google Gemini

**Free Tier | Good Quality | 60 requests/minute**

**Setup:**
1. Go to https://ai.google.dev/
2. Get API key (free)
3. Add to `api/ai-config.php`:
   ```php
   define('GEMINI_API_KEY', 'AIza_YOUR_KEY_HERE');
   ```
4. Select "Gemini" in the processor dropdown

**Free tier:** 60 requests/minute = ~3,600 articles/day

---

## 🤗 Option 4: Hugging Face

**Free Tier | Community Models**

**Setup:**
1. Go to https://huggingface.co/settings/tokens
2. Create access token (free)
3. Add to `api/ai-config.php`:
   ```php
   define('HUGGINGFACE_API_KEY', 'hf_YOUR_TOKEN_HERE');
   ```
4. Select "Hugging Face" in the processor dropdown

---

## 📊 Comparison

| Provider | Cost | Speed | Daily Limit | Best For |
|----------|------|-------|-------------|----------|
| **Ollama** | Free | Fast | Unlimited | Everyone! |
| **Groq** | Free | Very Fast | 14,400 | Speed lovers |
| **Gemini** | Free | Medium | 3,600 | Google users |
| **Hugging Face** | Free | Slow | Varies | Experimenters |

---

## 🎯 Which Should You Choose?

### If you have a decent computer:
→ **Use Ollama** (completely free, unlimited, private)

### If you don't want to install anything:
→ **Use Groq** (fastest, generous free tier)

### If you already use Google services:
→ **Use Gemini** (easy if you have Google account)

### Want to try multiple?
→ Set up all of them! The config file supports multiple API keys, and you can switch between them in the dropdown.

---

## 🔧 Configuration

1. Copy the example config:
   ```bash
   cp api/ai-config.example.php api/ai-config.php
   ```

2. Edit `api/ai-config.php` and add your chosen API key(s):
   ```php
   // Only add the ones you want to use!
   define('GROQ_API_KEY', 'gsk_...');
   define('GEMINI_API_KEY', 'AIza...');
   define('HUGGINGFACE_API_KEY', 'hf_...');
   ```

3. For Ollama, no config needed - just install and run!

---

## 💰 Cost Comparison

| Provider | Per Article | 100 Articles | 1,000 Articles |
|----------|-------------|--------------|----------------|
| **Ollama** | $0 | $0 | $0 |
| **Groq** | $0 | $0 | $0 |
| **Gemini** | $0 | $0 | $0* |
| **Hugging Face** | $0 | $0 | $0 |
| Claude | $0.01-0.03 | $1-3 | $10-30 |

*Gemini free tier may have fair use limits at very high volumes

---

## 🎉 Summary

You can now process news articles with AI **completely free** using:
- ✅ Templates (pre-fill article type)
- ✅ Free AI (auto-fill metadata, summary, warnings)
- ✅ No costs ever

**Time per article:** 2-3 minutes (down from 10-15)
**Cost:** $0

Happy processing! 🚀
