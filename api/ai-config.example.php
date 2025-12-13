<?php
/**
 * AI Configuration for News Article Processor
 *
 * Copy this file to ai-config.php and add your API keys
 * File: api/ai-config.php
 *
 * You only need to configure the AI provider(s) you want to use!
 */

// ========================================
// OLLAMA (100% FREE - RECOMMENDED)
// ========================================
// No API key needed! Runs locally on your computer.
// Setup:
// 1. Download from https://ollama.com/
// 2. Run: ollama pull llama3.2
// 3. Run: ollama serve
// That's it! Select "Ollama" in the dropdown.

// ========================================
// GROQ (FREE TIER - VERY FAST)
// ========================================
// Free tier: 14,400 requests/day
// Get your key from: https://console.groq.com/
define('GROQ_API_KEY', 'gsk_YOUR_GROQ_API_KEY_HERE');

// ========================================
// GOOGLE GEMINI (FREE TIER)
// ========================================
// Free tier: 60 requests/minute
// Get your key from: https://ai.google.dev/
define('GEMINI_API_KEY', 'AIza_YOUR_GEMINI_API_KEY_HERE');

// ========================================
// HUGGING FACE (FREE TIER)
// ========================================
// Free inference API
// Get your key from: https://huggingface.co/settings/tokens
define('HUGGINGFACE_API_KEY', 'hf_YOUR_HUGGINGFACE_TOKEN_HERE');

// ========================================
// CLAUDE (PAID API)
// ========================================
// Cost: ~$0.01-0.03 per article
// Get your key from: https://console.anthropic.com/
define('ANTHROPIC_API_KEY', 'sk-ant-api03-YOUR_API_KEY_HERE');

// ========================================
// ALTERNATIVE: Environment Variables
// ========================================
// Instead of defining keys here, you can set environment variables:
//
// Linux/Mac:
//   export GROQ_API_KEY="gsk_YOUR_KEY"
//   export GEMINI_API_KEY="AIza_YOUR_KEY"
//   export HUGGINGFACE_API_KEY="hf_YOUR_KEY"
//   export ANTHROPIC_API_KEY="sk-ant-YOUR_KEY"
//
// Windows:
//   set GROQ_API_KEY=gsk_YOUR_KEY
//   set GEMINI_API_KEY=AIza_YOUR_KEY
//   set HUGGINGFACE_API_KEY=hf_YOUR_KEY
//   set ANTHROPIC_API_KEY=sk-ant-YOUR_KEY
