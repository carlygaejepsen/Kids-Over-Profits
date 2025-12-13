<?php
/**
 * News Article Processor markup partial.
 *
 * Loaded by page-news-processor.php so WordPress can register
 * the template and enqueue assets via functions.php.
 */

get_header();
?>

<div id="news-processor-app" class="news-processor-wrapper">
    <div class="news-container">
        <div class="news-card">
            <!-- Header -->
            <div class="news-header">
                <h1>News Article Processor</h1>
                <p>Process Troubled Teen Industry news articles with trauma-sensitive protocols</p>
            </div>

            <!-- Quick Start Templates -->
            <div class="news-section">
                <div class="news-templates-header">
                    <h3>🚀 Quick Start Templates</h3>
                    <p>Choose a template to pre-configure the form for common article types</p>
                </div>
                <div class="news-templates-grid" id="template-buttons"></div>
            </div>

            <!-- AI Assistant -->
            <div class="news-section news-ai-section">
                <div class="news-ai-header">
                    <div class="news-ai-toggle-wrapper">
                        <label class="news-toggle-label">
                            <input type="checkbox" id="ai-enabled" class="news-toggle-input">
                            <span class="news-toggle-slider"></span>
                            <span class="news-toggle-text">🤖 AI Assistant</span>
                        </label>
                    </div>
                </div>
                <div id="ai-controls" class="news-ai-controls" style="display: none;">
                    <div class="news-info-box">
                        <strong>AI can help you:</strong>
                        <ul>
                            <li>Extract metadata from article URLs</li>
                            <li>Generate trauma-sensitive summaries</li>
                            <li>Identify content warnings automatically</li>
                            <li>Detect article type and extract relevant details</li>
                        </ul>
                    </div>

                    <div class="news-form-group">
                        <label>Choose AI Provider</label>
                        <select id="ai-provider" class="news-input">
                            <option value="ollama">Ollama (Free, Local, Unlimited) - Recommended</option>
                            <option value="groq">Groq (Free Tier - Very Fast)</option>
                            <option value="gemini">Google Gemini (Free Tier)</option>
                            <option value="huggingface">Hugging Face (Free Tier)</option>
                            <option value="claude">Claude (Paid API)</option>
                        </select>
                        <small style="display: block; margin-top: 0.25rem; color: #004435;">
                            <span id="provider-info">Ollama runs on your computer - completely free and unlimited!</span>
                        </small>
                    </div>

                    <div class="news-form-group">
                        <label>Paste Article Text (optional - if URL doesn't work)</label>
                        <textarea id="ai-article-text" class="news-textarea" rows="4" placeholder="Paste full article text here..."></textarea>
                    </div>
                    <button id="process-with-ai" class="news-btn news-btn-ai">🤖 Process with AI</button>
                    <div id="ai-status" class="news-ai-status"></div>
                </div>
            </div>

            <!-- Basic Details Section -->
            <div class="news-section">
                <button class="news-section-header" data-section="basic">
                    <div class="news-section-title">
                        <span class="news-icon">📄</span>
                        <h2>1. Basic Details Extraction</h2>
                    </div>
                    <span class="news-chevron">▼</span>
                </button>
                <div class="news-section-content active" id="section-basic">
                    <div class="news-form-group">
                        <label>Article Title</label>
                        <input type="text" name="title" class="news-input" placeholder="Enter article title">
                    </div>
                    
                    <div class="news-grid-2">
                        <div class="news-form-group">
                            <label>Author</label>
                            <div class="news-input-with-save">
                                <input type="text" name="author" class="news-input" data-autocomplete-category="human" list="authors-list" placeholder="Author name">
                                <datalist id="authors-list"></datalist>
                                <button class="news-save-btn" data-save="authors" data-field="author">💾</button>
                            </div>
                            <div class="news-saved-tags" data-category="authors"></div>
                        </div>
                        <div class="news-form-group">
                            <label>Publication Date</label>
                            <input type="date" name="publicationDate" class="news-input">
                        </div>
                    </div>

                    <div class="news-grid-2">
                        <div class="news-form-group">
                            <label>Publication Name</label>
                            <div class="news-input-with-save">
                                <input type="text" name="publicationName" class="news-input" list="publications-list" placeholder="e.g., New York Times">
                                <datalist id="publications-list"></datalist>
                                <button class="news-save-btn" data-save="publications" data-field="publicationName">💾</button>
                            </div>
                            <div class="news-saved-tags" data-category="publications"></div>
                        </div>
                        <div class="news-form-group">
                            <label>URL</label>
                            <input type="url" name="url" class="news-input" placeholder="https://...">
                        </div>
                    </div>

                    <div class="news-form-group">
                        <label>Facilities/Companies Mentioned</label>
                        <div id="facilities-container" class="news-dynamic-fields"></div>
                        <button type="button" class="news-btn news-btn-add" data-add-field="facilities">+ Add Facility</button>
                    </div>

                    <div class="news-grid-2">
                        <div class="news-form-group">
                            <label>Staff/Owners Mentioned</label>
                            <div id="staff-container" class="news-dynamic-fields"></div>
                            <button type="button" class="news-btn news-btn-add" data-add-field="staff">+ Add Staff</button>
                        </div>
                        <div class="news-form-group">
                            <label>Survivors Mentioned</label>
                            <div id="survivors-container" class="news-dynamic-fields"></div>
                            <button type="button" class="news-btn news-btn-add" data-add-field="survivors">+ Add Survivor</button>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Content Warnings Section -->
            <div class="news-section">
                <button class="news-section-header" data-section="warnings">
                    <div class="news-section-title">
                        <span class="news-icon">⚠️</span>
                        <h2>2. Content Warnings</h2>
                        <span class="news-count" id="warnings-count">0</span>
                    </div>
                    <span class="news-chevron">▶</span>
                </button>
                <div class="news-section-content" id="section-warnings">
                    <div class="news-warnings-grid" id="warnings-container"></div>
                </div>
            </div>

            <!-- Summary Section -->
            <div class="news-section">
                <button class="news-section-header" data-section="summary">
                    <div class="news-section-title">
                        <span class="news-icon">✏️</span>
                        <h2>3. Trauma-Sensitive Summary</h2>
                    </div>
                    <span class="news-chevron">▶</span>
                </button>
                <div class="news-section-content" id="section-summary">
                    <div class="news-info-box">
                        <strong>Summary Guidelines:</strong>
                        <ul>
                            <li>Use factual, neutral, concise language (2-3 sentences)</li>
                            <li>Avoid graphic detail, focus on information not sensation</li>
                            <li>Replace triggering words (e.g., "sexual assault" not "rape")</li>
                        </ul>
                    </div>

                    <div class="news-form-group">
                        <label>Summary</label>
                        <textarea name="summary" class="news-textarea" rows="4" placeholder="Write a trauma-sensitive summary (2-3 sentences)"></textarea>
                    </div>

                    <div class="news-checkbox-group">
                        <input type="checkbox" id="needsAlnewstle" name="needsAlternateTitle">
                        <label for="needsAlnewstle">Original title is sensationalist or graphic (create alternate title)</label>
                    </div>

                    <div class="news-form-group" id="alnewstleGroup" style="display: none;">
                        <label>Alternate Title</label>
                        <input type="text" name="alternateTitle" class="news-input" placeholder="Descriptive, accurate, and neutral title">
                    </div>
                </div>
            </div>

            <!-- Article Type Section -->
            <div class="news-section">
                <button class="news-section-header" data-section="type">
                    <div class="news-section-title">
                        <span class="news-icon">🏷️</span>
                        <h2>4. Article Type & Specific Details</h2>
                    </div>
                    <span class="news-chevron">▶</span>
                </button>
                <div class="news-section-content" id="section-type">
                    <div class="news-form-group">
                        <label>Article Type</label>
                        <div class="news-type-grid" id="article-types"></div>
                    </div>
                    <div id="type-specific-forms"></div>
                </div>
            </div>

            <!-- Export Buttons -->
            <div class="news-footer">
                <button id="export-json" class="news-btn news-btn-primary">Export as JSON</button>
                <button id="export-text" class="news-btn news-btn-secondary">Export as Text</button>
                <button id="submit-to-db" class="news-btn news-btn-submit">💾 Submit to Database</button>
                <button id="clear-form" class="news-btn news-btn-clear">Clear Form</button>
            </div>
        </div>
    </div>
</div>

<!-- Submission Modal -->
<div id="newsSubmitModal" class="news-submit-modal" style="display: none;">
    <div class="news-submit-modal-content">
        <h3>Submit Article to Database</h3>
        <div class="news-submit-form-group">
            <label for="newsSubmitterEmail">Your Email (optional):</label>
            <input type="email" id="newsSubmitterEmail" placeholder="your@email.com">
        </div>
        <div class="news-submit-form-group">
            <label for="newsSubmissionNotes">Notes (optional):</label>
            <textarea id="newsSubmissionNotes" rows="3" placeholder="Any additional context or notes..."></textarea>
        </div>
        <div class="news-submit-modal-actions">
            <button type="button" id="newsCancelSubmitBtn" class="news-btn news-btn-clear">Cancel</button>
            <button type="button" id="newsConfirmSubmitBtn" class="news-btn news-btn-primary">Submit</button>
        </div>
        <div id="newsSubmitStatus" class="news-submit-status"></div>
    </div>
</div>

<script>
// Configure autocomplete API endpoint for news processor
window.KOP_FormLoader = window.KOP_FormLoader || {};
window.KOP_FormLoader.API_ENDPOINTS = window.KOP_FormLoader.API_ENDPOINTS || {};
window.KOP_FormLoader.API_ENDPOINTS.AUTOCOMPLETE = '<?php echo get_stylesheet_directory_uri(); ?>/api/get-autocomplete.php';
</script>
<script src="<?php echo get_stylesheet_directory_uri(); ?>/js/autocomplete.js"></script>

<?php
get_footer();
?>
