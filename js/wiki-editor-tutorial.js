// Guided tour for the TTI Wiki Entry Generator (templates/page-wiki-editor.php).
// Reuses the shared TutorialOverlay class from tutorial-overlay.js, which must
// be loaded first (see kop_enqueue_wiki_editor_assets in inc/enqueue.php).
(function () {
    'use strict';

    const initWikiEditorTutorial = () => {
        // Only run on the wiki editor page.
        if (!document.getElementById('wikiForm')) return;
        if (!window.TutorialOverlay) return;
        if (window.kopWikiTutorial) return;

        const steps = [
            {
                title: 'Welcome to the Wiki Entry Generator!',
                content: 'This tool builds Reddit wiki entries for r/troubledteens. Fill out the form, generate the markdown, and copy it to the wiki — or submit it to our database for review. This quick tour shows you around.',
                target: null // Center
            },
            {
                title: 'Editor Modes',
                content: 'Work in the <strong>Form Editor</strong> to fill structured fields, or switch to the <strong>Markdown Editor</strong> to edit the raw wiki markdown directly. You can switch at any time.',
                target: '.editor-mode-toggle',
                position: 'bottom'
            },
            {
                title: 'Browse Existing Entries',
                content: 'Load an existing wiki entry to edit it. Browse by <strong>📍 Location</strong> (state), <strong>🏢 Organization</strong> (parent company), or <strong>📝 Stubs</strong> — empty entries that still need to be written. Stubs are a great place to start contributing!',
                target: '.index-selection-pane .category-tabs',
                position: 'bottom'
            },
            {
                title: 'Pick an Index',
                content: 'Choose a state or organization from the dropdown. The facilities it contains will appear in the panel on the right.',
                target: '#locationIndexSelect, #orgIndexSelect',
                position: 'right'
            },
            {
                title: 'Load a Facility',
                content: 'Click a facility here to load its wiki entry into the form. Use the filter box to narrow long lists. Entries marked as empty will start you with a blank form for that program.',
                target: '#indexBrowserPanel',
                position: 'left',
                highlightPadding: 8
            },
            {
                title: 'Bulk Actions & Importing',
                content: '<strong>📤 Bulk Upload</strong> submits multiple markdown files at once. <strong>📥 Import from Clipboard</strong> converts a pasted wiki entry or plain-text article into form fields. <strong>🤖 Extract from Prose</strong> uses AI to fill fields from any pasted text — always review what it fills in!',
                target: '#toggleBulkUploadBtn, #toggleImportBtn, #toggleExtractProseBtn',
                highlightAll: true,
                position: 'bottom',
                highlightPadding: 4
            },
            {
                title: 'Entry Type',
                content: 'Choose whether you\'re writing about an <strong>individual facility</strong> or a <strong>parent organization</strong>. The form shows different fields for each — organization mode adds headquarters and a facilities-operated table, facility mode adds program details like capacity and age range.',
                target: '#entryType',
                position: 'bottom',
                scrollTarget: '#wikiForm'
            },
            {
                title: 'Link a Program Index Entry',
                content: 'Every wiki entry must be linked to a program in the index so it carries a unique ID — document libraries attach to that ID by folder number. Click this button to search for the matching program or create a new one. You can do this now or when you submit.',
                target: '.inline-program-link',
                position: 'bottom',
                highlightPadding: 8
            },
            {
                title: 'Fill in the Fields',
                content: 'Work through the sections: basic info, history, staff, program structure, rules, allegations, media, and testimonies. Many fields have <strong>autocomplete</strong> — start typing to match existing entries and keep names consistent across the wiki.',
                target: '#programName',
                position: 'bottom',
                scrollTarget: '#programName',
                highlightPadding: 8
            },
            {
                title: 'List Builders',
                content: 'Sections like Staff, Lawsuits, and News Articles use "adders": fill the small form, click the <strong>Add</strong> button, and the item appears in a list below. Add as many as you need — each item can be removed from the list before generating.',
                target: '#addStaffBtn',
                position: 'top',
                scrollTarget: '#staffName',
                highlightPadding: 6
            },
            {
                title: 'Auto-Linking',
                content: 'When this is checked, mentions of other TTI programs in your entry are automatically linked to their Reddit wiki pages. Leave it on unless you have a reason not to.',
                target: '.auto-linking-options',
                position: 'top',
                highlightPadding: 6
            },
            {
                title: 'Generate the Wiki Code',
                content: 'When the form is filled in, click here to generate the Reddit wiki markdown from your entries.',
                target: '#generateBtn',
                position: 'top',
                highlightPadding: 6
            },
            {
                title: 'Review the Output',
                content: 'The generated markdown appears here. You can edit it directly before copying or submitting — anything you change in this box is what gets used.',
                target: '#outputCode',
                position: 'top',
                highlightPadding: 6
            },
            {
                title: 'Copy or Submit',
                content: '<strong>Copy to Clipboard</strong> lets you paste the entry straight into the Reddit wiki editor. <strong>Convert to Past Tense</strong> rewrites the entry for closed programs. <strong>💾 Submit to Database</strong> sends the entry to our team for review — it opens a dialog where you confirm the linked program and can add your email and notes.',
                target: '#convertPastBtn, #copyBtn, #submitToDbBtn',
                highlightAll: true,
                highlightMode: 'sequential',
                position: 'top',
                highlightPadding: 4
            }
        ];

        window.kopWikiTutorial = new window.TutorialOverlay(steps, {
            storageKey: 'kop_wiki_editor_tutorial_seen'
        });
    };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initWikiEditorTutorial);
    } else {
        initWikiEditorTutorial();
    }

    // Last-chance delayed init in case markup arrives late.
    setTimeout(initWikiEditorTutorial, 1500);
})();
