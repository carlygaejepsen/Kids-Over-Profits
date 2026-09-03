// Guided tours for the contribution tools that lacked one: the News
// Processor, the lawsuit and legislation submission forms, and the anonymous
// document portal. Modeled on js/wiki-editor-tutorial.js: each tour reuses
// the shared TutorialOverlay class (js/tutorial-overlay.js, loaded first via
// kop_enqueue_tool_tutorials in inc/enqueue.php), guards on its page's root
// element, and tracks its own "seen" badge with a per-tool storage key.
(function () {
    'use strict';

    const initToolTutorials = () => {
        if (!window.TutorialOverlay) return;

        // ------------------------------------------------------------------
        // News Article Processor (templates/page-news-processor.php)
        // ------------------------------------------------------------------
        if (document.getElementById('news-processor-app') && !window.kopNewsProcessorTutorial) {
            window.kopNewsProcessorTutorial = new window.TutorialOverlay([
                {
                    title: 'Welcome to the News Processor!',
                    content: 'This tool turns TTI news articles into structured database entries for the public news feed. Work through the numbered sections, then submit for review. This quick tour shows you around.',
                    target: null
                },
                {
                    title: 'Quick Start Templates',
                    content: 'Pick the template that matches the story - a lawsuit, an arrest, a closure - and the form pre-selects the right article type and fields for it.',
                    target: '#template-buttons',
                    position: 'bottom',
                    highlightPadding: 6
                },
                {
                    title: 'Let AI Do the First Pass',
                    content: 'Turn this on, paste the article URL (or the article text if the URL is paywalled), and click <strong>Process with AI</strong>. It fills in the fields below for you - always review what it extracted before submitting.',
                    target: '.news-toggle-label',
                    position: 'bottom',
                    highlightPadding: 6
                },
                {
                    title: 'Basic Details',
                    content: 'Title, author, date, and publication. The small save buttons remember values you use often - authors and outlets autocomplete on your next article.',
                    target: '.news-section-header',
                    position: 'bottom',
                    highlightPadding: 4
                },
                {
                    title: 'Tag the Facilities',
                    content: 'Link every facility or company the article mentions. Matching against the program index is what connects this article to facility profiles across the site.',
                    target: '#facilities-picker',
                    position: 'top',
                    scrollTarget: '#facilities-picker',
                    highlightPadding: 6
                },
                {
                    title: 'Content Warnings',
                    content: 'Tag the difficult content in the article - readers on the news feed use these to decide what to open. Err on the side of including a warning.',
                    target: '#section-warnings',
                    position: 'top',
                    scrollTarget: '#section-warnings',
                    highlightPadding: 4
                },
                {
                    title: 'Trauma-Sensitive Summary',
                    content: 'Write (or review the AI\'s) short factual summary. This is what most readers see first, so keep it clear and avoid graphic detail - the warnings above cover the rest.',
                    target: '#section-summary',
                    position: 'top',
                    scrollTarget: '#section-summary',
                    highlightPadding: 4
                },
                {
                    title: 'Article Type',
                    content: 'Confirm the article type - lawsuit, arrest, closure, expose, and so on. Some types open extra fields below for their specific details.',
                    target: '#article-types',
                    position: 'top',
                    scrollTarget: '#section-type',
                    highlightPadding: 4
                },
                {
                    title: 'Submit for Review',
                    content: '<strong>Submit to Database</strong> sends the entry to our team - a dialog asks for an optional email and notes first. The export buttons let you keep a JSON or text copy for yourself.',
                    target: '#submit-to-db, #export-json',
                    highlightAll: true,
                    position: 'top',
                    scrollTarget: '#submit-to-db',
                    highlightPadding: 4
                }
            ], { storageKey: 'kop_news_processor_tutorial_seen' });
        }

        // ------------------------------------------------------------------
        // Submit a Lawsuit (templates/page-submit-lawsuit.php)
        // ------------------------------------------------------------------
        if (document.getElementById('kop-submit-lawsuit-form') && !window.kopSubmitLawsuitTutorial) {
            window.kopSubmitLawsuitTutorial = new window.TutorialOverlay([
                {
                    title: 'Submitting a Lawsuit',
                    content: 'Know of a court case involving a TTI facility, operator, or staff member? This form adds it to our public tracker after review. <strong>A case name and a source link are enough</strong> - fill in whatever else you know.',
                    target: null
                },
                {
                    title: 'Case Name',
                    content: 'The only required field. Use the official case name if you know it ("Doe v. Example Academy"), or a short factual description if you don\'t.',
                    target: 'input[name="case_name"]',
                    position: 'bottom',
                    highlightPadding: 6
                },
                {
                    title: 'Court Details',
                    content: 'Docket number, court, jurisdiction, filing date, and status - fill in what you have. These make the case much easier for our team and other researchers to verify.',
                    target: 'input[name="case_number"]',
                    position: 'bottom',
                    highlightPadding: 6
                },
                {
                    title: 'Who Is Involved',
                    content: 'Facilities, plaintiffs, and defendants - <strong>one per line</strong>. Facility names here link the case to facility profiles across the site.',
                    target: 'textarea[name="facilities_mentioned"]',
                    position: 'bottom',
                    scrollTarget: 'textarea[name="facilities_mentioned"]',
                    highlightPadding: 6
                },
                {
                    title: 'Claims and Summary',
                    content: 'List the claims one per line (physical abuse, wrongful death, fraud), and add a brief factual summary of what the case alleges.',
                    target: 'textarea[name="claims"]',
                    position: 'top',
                    scrollTarget: 'textarea[name="claims"]',
                    highlightPadding: 6
                },
                {
                    title: 'Sources',
                    content: 'News coverage or court records, one URL per line. <strong>This is the most important field for verification</strong> - a case we can\'t verify can\'t go on the tracker.',
                    target: 'textarea[name="source_urls"]',
                    position: 'top',
                    scrollTarget: 'textarea[name="source_urls"]',
                    highlightPadding: 6
                },
                {
                    title: 'About You (Optional)',
                    content: 'Entirely optional - leave it blank to submit anonymously. An email lets us follow up if we have questions.',
                    target: '.kop-submitter-box',
                    position: 'top',
                    scrollTarget: '.kop-submitter-box',
                    highlightPadding: 6
                },
                {
                    title: 'Submit for Review',
                    content: 'Our team reviews every submission before it appears on the public Lawsuits tracker. Thank you for helping hold the industry accountable.',
                    target: '.kop-submit-btn',
                    position: 'top',
                    scrollTarget: '.kop-submit-btn',
                    highlightPadding: 6
                }
            ], { storageKey: 'kop_submit_lawsuit_tutorial_seen' });
        }

        // ------------------------------------------------------------------
        // Submit Legislation (templates/page-submit-legislation.php)
        // ------------------------------------------------------------------
        if (document.getElementById('kop-submit-legislation-form') && !window.kopSubmitLegislationTutorial) {
            window.kopSubmitLegislationTutorial = new window.TutorialOverlay([
                {
                    title: 'Submitting Legislation',
                    content: 'Track bills that affect the Troubled Teen Industry - good or bad. Submissions are reviewed before they appear on the public legislation tracker. <strong>A bill title and a link are enough to start.</strong>',
                    target: null
                },
                {
                    title: 'Bill Title',
                    content: 'The only required field. The official title is best, but a short description of what the bill does works too.',
                    target: 'input[name="bill_title"]',
                    position: 'bottom',
                    highlightPadding: 6
                },
                {
                    title: 'Identify the Bill',
                    content: 'Bill number (like "HB 123" or "S. 456"), jurisdiction, session year, and where the bill currently stands.',
                    target: 'input[name="bill_number"]',
                    position: 'bottom',
                    highlightPadding: 6
                },
                {
                    title: 'Link to the Official Record',
                    content: 'The <strong>full text URL</strong> is the bill\'s unique fingerprint in our system - it\'s how we avoid duplicate entries. The tracker URL (LegiScan, a legislature site) helps readers follow along.',
                    target: 'input[name="full_text_url"]',
                    position: 'bottom',
                    scrollTarget: 'input[name="official_url"]',
                    highlightPadding: 6
                },
                {
                    title: 'What the Bill Does',
                    content: 'Sponsors (one per line), a plain-language summary, and subject tags so researchers can find related bills.',
                    target: 'textarea[name="summary"]',
                    position: 'top',
                    scrollTarget: 'textarea[name="sponsors"]',
                    highlightPadding: 6
                },
                {
                    title: 'About You (Optional)',
                    content: 'Entirely optional - leave it blank to submit anonymously. An email lets us follow up if we have questions.',
                    target: '.kop-submitter-box',
                    position: 'top',
                    scrollTarget: '.kop-submitter-box',
                    highlightPadding: 6
                },
                {
                    title: 'Submit for Review',
                    content: 'Our team verifies every bill before it appears on the tracker. Thank you for keeping an eye on the laws that shape this industry.',
                    target: '.kop-submit-btn',
                    position: 'top',
                    scrollTarget: '.kop-submit-btn',
                    highlightPadding: 6
                }
            ], { storageKey: 'kop_submit_legislation_tutorial_seen' });
        }

        // ------------------------------------------------------------------
        // Anonymous Document Portal ([anonymous_doc_portal] shortcode)
        // ------------------------------------------------------------------
        if (document.getElementById('anonymous-doc-form') && !window.kopAnonPortalTutorial) {
            window.kopAnonPortalTutorial = new window.TutorialOverlay([
                {
                    title: 'Secure Anonymous Document Drop',
                    content: 'Share documents about TTI facilities without identifying yourself. Files are scanned for malware and stored encrypted; the form asks for <strong>no name, email, or account</strong>.',
                    target: null
                },
                {
                    title: 'Add Your File',
                    content: 'Drag a file here or click to browse. PDFs, Word documents, text files, images, and ZIP archives are accepted, up to 10MB.',
                    target: '#file-drop-zone',
                    position: 'bottom',
                    highlightPadding: 6
                },
                {
                    title: 'Add Context (Optional)',
                    content: 'Anything that helps us understand the document - which facility, what time period, what it shows. These notes are encrypted along with the file.',
                    target: '#doc-notes',
                    position: 'top',
                    highlightPadding: 6
                },
                {
                    title: 'Submit Securely',
                    content: 'One click and it\'s on its way to our team. If the document contains identifying details about you that you\'d like removed before publication, say so in the notes.',
                    target: '#submit-doc',
                    position: 'top',
                    highlightPadding: 6
                }
            ], { storageKey: 'kop_anon_portal_tutorial_seen' });
        }
    };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initToolTutorials);
    } else {
        initToolTutorials();
    }

    // Last-chance delayed init in case markup arrives late (JS-rendered UIs).
    setTimeout(initToolTutorials, 1500);
})();
