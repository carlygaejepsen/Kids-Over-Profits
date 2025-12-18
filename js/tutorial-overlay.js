/**
 * Tutorial Overlay for Data Forms
 */

class TutorialOverlay {
    constructor(steps) {
        this.steps = steps || [];
        this.currentStepIndex = 0;
        this.isActive = false;
        
        this.init();
    }

    init() {
        this.createOverlay();
        this.createToggleButton();
        
        // Check if tutorial has been seen
        if (!localStorage.getItem('kop_data_form_tutorial_seen')) {
            // Let's just show the button with a notification badge instead of auto-start
            this.showNotification();
        }
    }

    createOverlay() {
        // Overlay container
        this.overlay = document.createElement('div');
        this.overlay.className = 'tutorial-overlay';
        
        // Highlight box (for positioning around targets)
        this.highlight = document.createElement('div');
        this.highlight.className = 'tutorial-target-highlight';
        this.overlay.appendChild(this.highlight);

        // Card container
        this.card = document.createElement('div');
        this.card.className = 'tutorial-card';
        this.card.innerHTML = `
            <div class="tutorial-header">
                <h3 class="tutorial-title"></h3>
                <button class="tutorial-close">×</button>
            </div>
            <div class="tutorial-content"></div>
            <div class="tutorial-footer">
                <span class="tutorial-steps"></span>
                <div class="tutorial-nav">
                    <button class="tutorial-btn tutorial-btn-prev">Back</button>
                    <button class="tutorial-btn tutorial-btn-next">Next</button>
                </div>
            </div>
        `;
        this.overlay.appendChild(this.card);
        
        document.body.appendChild(this.overlay);

        // Event Listeners
        this.card.querySelector('.tutorial-close').addEventListener('click', () => this.stop());
        this.card.querySelector('.tutorial-btn-prev').addEventListener('click', () => this.prevStep());
        this.card.querySelector('.tutorial-btn-next').addEventListener('click', () => this.nextStep());
        
        // Close on overlay click (optional)
        this.overlay.addEventListener('click', (e) => {
            if (e.target === this.overlay) this.stop();
        });
        
        // Resize handler
        window.addEventListener('resize', () => {
            if (this.isActive) this.positionOverlay();
        });
    }

    createToggleButton() {
        const btn = document.createElement('button');
        btn.className = 'tutorial-toggle-btn';
        btn.innerHTML = '❓';
        btn.title = 'Start Tutorial';
        
        const tooltip = document.createElement('div');
        tooltip.className = 'tutorial-toggle-tooltip';
        tooltip.textContent = 'Need help? Click for a tutorial!';
        btn.appendChild(tooltip);

        btn.addEventListener('click', () => this.toggle());
        document.body.appendChild(btn);
        this.toggleBtn = btn;
    }

    showNotification() {
        this.toggleBtn.classList.add('has-notification');
    }

    start() {
        this.isActive = true;
        this.currentStepIndex = 0;
        this.overlay.classList.add('active');
        this.showStep(0);
        localStorage.setItem('kop_data_form_tutorial_seen', 'true');
    }

    stop() {
        this.isActive = false;
        this.overlay.classList.remove('active');
        this.clearHighlight();
    }

    toggle() {
        if (this.isActive) {
            this.stop();
        } else {
            this.start();
        }
    }

    nextStep() {
        const currentStep = this.steps[this.currentStepIndex];
        
        // Validation check before moving forward
        if (currentStep.validate) {
            const isValid = currentStep.validate();
            if (!isValid) return; // Prevent navigation if validation fails
        }

        if (this.currentStepIndex < this.steps.length - 1) {
            this.currentStepIndex++;
            this.showStep(this.currentStepIndex);
        } else {
            this.stop(); // Finish
        }
    }

    prevStep() {
        if (this.currentStepIndex > 0) {
            this.currentStepIndex--;
            this.showStep(this.currentStepIndex);
        }
    }

    showStep(index) {
        const step = this.steps[index];
        if (!step) return;

        // Update Content
        this.card.querySelector('.tutorial-title').textContent = step.title;
        this.card.querySelector('.tutorial-content').innerHTML = step.content;
        this.card.querySelector('.tutorial-steps').textContent = `${index + 1} of ${this.steps.length}`;
        
        // Update Buttons
        const nextBtn = this.card.querySelector('.tutorial-btn-next');
        const prevBtn = this.card.querySelector('.tutorial-btn-prev');
        
        nextBtn.textContent = index === this.steps.length - 1 ? 'Finish' : 'Next';
        prevBtn.style.display = index === 0 ? 'none' : 'block';

        // Check if next button should be disabled initially (if validation fails)
        if (step.validate && !step.validate(true)) {
             // Optional: add visual cue that action is required, but generally we let them click and see the alert
        }

        // Position & Highlight
        if (step.target) {
            const targetEl = document.querySelector(step.target);
            if (targetEl && targetEl.offsetParent !== null) { // Check visibility
                this.highlightElement(targetEl);
                this.positionCard(targetEl, step.position || 'bottom');
            } else {
                this.clearHighlight();
                this.centerCard();
            }
        } else {
            this.clearHighlight();
            this.centerCard();
        }
    }

    highlightElement(element) {
        const rect = element.getBoundingClientRect();
        const scrollTop = window.pageYOffset || document.documentElement.scrollTop;
        const scrollLeft = window.pageXOffset || document.documentElement.scrollLeft;

        this.highlight.style.display = 'block';
        this.highlight.style.width = (rect.width + 10) + 'px';
        this.highlight.style.height = (rect.height + 10) + 'px';
        this.highlight.style.top = (rect.top + scrollTop - 5) + 'px';
        this.highlight.style.left = (rect.left + scrollLeft - 5) + 'px';
        
        // Scroll into view
        element.scrollIntoView({ behavior: 'smooth', block: 'center' });
    }

    clearHighlight() {
        this.highlight.style.display = 'none';
    }

    positionCard(targetEl, position) {
        // Mobile override: Force fixed bottom positioning
        if (window.innerWidth < 768) {
            this.card.style.position = 'fixed';
            this.card.style.top = 'auto';
            this.card.style.bottom = '20px';
            this.card.style.left = '5%';
            this.card.style.width = '90%';
            this.card.style.maxWidth = 'none';
            this.card.style.transform = 'none';
            return;
        }

        this.card.style.position = 'absolute';
        this.card.style.width = '350px';
        
        const targetRect = targetEl.getBoundingClientRect();
        const cardRect = this.card.getBoundingClientRect();
        const scrollTop = window.pageYOffset || document.documentElement.scrollTop;
        const scrollLeft = window.pageXOffset || document.documentElement.scrollLeft;
        
        let top, left;
        const margin = 20;

        switch (position) {
            case 'bottom':
                top = targetRect.bottom + scrollTop + margin;
                left = targetRect.left + scrollLeft + (targetRect.width / 2) - (cardRect.width / 2);
                break;
            case 'top':
                top = targetRect.top + scrollTop - cardRect.height - margin;
                left = targetRect.left + scrollLeft + (targetRect.width / 2) - (cardRect.width / 2);
                break;
            case 'right':
                top = targetRect.top + scrollTop + (targetRect.height / 2) - (cardRect.height / 2);
                left = targetRect.right + scrollLeft + margin;
                break;
            case 'left':
                top = targetRect.top + scrollTop + (targetRect.height / 2) - (cardRect.height / 2);
                left = targetRect.left + scrollLeft - cardRect.width - margin;
                break;
            default: // center
                this.centerCard();
                return;
        }

        // Viewport bounds check
        if (left < 10) left = 10;
        
        this.card.style.top = top + 'px';
        this.card.style.left = left + 'px';
        this.card.style.transform = 'none';
    }
    
    positionOverlay() {
       // Re-render current step to adjust position
       this.showStep(this.currentStepIndex);
    }

    centerCard() {
        this.card.style.position = 'fixed'; // Use fixed for centering to avoid scroll issues
        this.card.style.top = '50%';
        this.card.style.left = '50%';
        this.card.style.transform = 'translate(-50%, -50%)';
        
        // Mobile adjustment for center
        if (window.innerWidth < 768) {
            this.card.style.width = '90%';
        }
    }
}

// Initialize Tutorial Data
document.addEventListener('DOMContentLoaded', () => {
    // Only run on data form pages
    if (!document.querySelector('.category-navigation')) return;

    const tutorialSteps = [
        {
            title: 'Welcome to the Data Form! 👋',
            content: 'This tool helps us collect and organize information about the Troubled Teen Industry. This quick tutorial will guide you through the basics.',
            target: null // Center
        },
        {
            title: 'Choose a Category',
            content: 'Start by selecting what kind of data you are entering. <br>• <strong>Companies:</strong> Parent Companies/Organizations.<br>• <strong>Locations:</strong> Browse projects by state or country.<br>• <strong>Referrers:</strong> A list of people and agencies that refer kids to the TTI.<br><br><strong>Please select a category to continue.</strong>',
            target: '.category-tabs',
            position: 'bottom',
            validate: (silent = false) => {
                const activeTab = document.querySelector('.category-tab.active');
                if (activeTab && activeTab.dataset.category) {
                    return true;
                }
                if (!silent) {
                    alert('Please select one of the category tabs (Companies, Locations, or Referrers) to continue the tutorial.');
                }
                return false;
            }
        },
        {
            title: 'Project Management',
            content: 'We use a "Project" system. <strong>Please create a New Project or select a saved one to continue.</strong> The form fields will load once a project is active.',
            target: '.project-management',
            position: 'right',
            validate: (silent = false) => {
                if (window.currentProjectName) {
                    return true;
                }
                if (!silent) {
                    alert('Please create a New Project or select an existing one to continue the tutorial.');
                }
                return false;
            }
        },
        {
            title: 'Auto-Save & Drafts',
            content: 'Your work is NOT automatically sent to us. Use <strong>Save Draft Locally</strong> to save your progress in your browser. You can come back later and your data will be here!',
            target: '#save-draft-locally-btn',
            position: 'top'
        },
        {
            title: 'Autocomplete Fields',
            content: 'Many fields (like Parent Company Name) have autocomplete. Start typing to see if the entity already exists in our database to avoid duplicates.',
            target: '#operator-name',
            position: 'bottom'
        },
        {
            title: 'Submitting Data',
            content: 'When you are finished, click <strong>Submit for Review</strong>. This sends your data to our team for verification and inclusion in the master database.',
            target: 'button[onclick="submitSuggestion()"]',
            position: 'top'
        }
    ];

    window.kopTutorial = new TutorialOverlay(tutorialSteps);
});