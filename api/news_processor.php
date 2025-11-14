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
                                <input type="text" name="author" class="news-input" list="authors-list" placeholder="Author name">
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
                        <label>news Facilities/Companies</label>
                        <div class="news-input-with-save">
                            <textarea name="facilities" class="news-textarea" rows="3" placeholder="One per line"></textarea>
                            <button class="news-save-btn" data-save="facilities" data-field="facilities">💾</button>
                        </div>
                        <div class="news-saved-tags" data-category="facilities"></div>
                    </div>

                    <div class="news-grid-2">
                        <div class="news-form-group">
                            <label>Staff/Owners</label>
                            <div class="news-input-with-save">
                                <textarea name="staff" class="news-textarea" rows="3" placeholder="One per line"></textarea>
                                <button class="news-save-btn" data-save="staff" data-field="staff">💾</button>
                            </div>
                            <div class="news-saved-tags" data-category="staff"></div>
                        </div>
                        <div class="news-form-group">
                            <label>Survivors Mentioned</label>
                            <textarea name="survivors" class="news-textarea" rows="3" placeholder="One per line"></textarea>
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
                <button id="clear-form" class="news-btn news-btn-clear">Clear Form</button>
            </div>
        </div>
    </div>
</div>

<?php
get_footer();
?>

