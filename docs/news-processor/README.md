# News Processor

The **News Processor** is a specialized tool within the Kids Over Profits platform designed to streamline the collection and analysis of news articles related to the Troubled Teen Industry (TTI). It leverages Artificial Intelligence to automatically extract structured data from raw article text.

## Overview

The tool functions as a Single Page Application (SPA) embedded within the WordPress theme. It allows users (typically researchers or admins) to:
1.  Input a news article URL.
2.  Fetch the article content.
3.  Process the content using various AI models (Ollama, Groq, Gemini, Claude) to identify key entities like program names, people, and allegations.
4.  Review and edit the extracted data.
5.  Save the structured data into the `news_submissions` database.

## Architecture

The module follows a decoupled architecture where the frontend handles the UI and state, while the backend manages external API calls and database operations.

### Frontend
- **Entry Point:** `page-news-processor.php` - The WordPress template that loads the page.
- **UI Structure:** `api/news_processor.php` - Contains the HTML markup for the form, modals, and layout.
- **Logic:** `js/news-processor.js` - The core engine. It handles:
    - State management (using `localStorage` for persistence).
    - API communication (fetching articles, sending prompts to AI, saving data).
    - UI updates and form validation.

### Backend (`/api`)
- **AI Processing:** `process-news-ai.php` - The bridge to AI services. It constructs prompts and routes requests to the configured AI provider.
- **Data Persistence:** `save-news-submission.php` - CRUD endpoint for the `news_submissions` table.
- **Data Consistency:** `get-autocomplete.php` - Provides standardized lists of programs and entities to ensure data quality.

## Key Features

- **Multi-Model AI Support:** Configurable to use different LLMs depending on availability or cost (e.g., local Ollama instance vs. cloud-based Claude/Gemini).
- **Auto-Extraction:** Automatically identifies:
    - **Programs:** Facilities mentioned in the article.
    - **People:** Staff, owners, or victims involved.
    - **Locations:** City, State, Country.
    - **Dates:** Publication date and relevant event dates.
    - **Summary:** A concise summary of the article.
- **Local Persistence:** Drafts are saved to the browser's local storage, preventing data loss during accidental refreshes.

## Usage

1.  **Navigate** to the News Processor page (e.g., `/news-processor`).
2.  **Paste** the URL of a news article in the input field.
3.  **Click** "Fetch & Process".
4.  **Review** the AI-generated form filling. Verify the "Programs" and "People" fields against the article text.
5.  **Edit** any incorrect information.
6.  **Submit** the entry to the database.

## Configuration

Environment variables (typically in `.env` or server config) control the API keys for the AI services:
- `OLLAMA_API_URL`
- `GROQ_API_KEY`
- `GEMINI_API_KEY`
- `CLAUDE_API_KEY`
