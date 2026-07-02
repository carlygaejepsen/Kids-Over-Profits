// Prevent duplicate definition if this script is loaded twice (different theme copies)
if (window.TutorialOverlay) {
    // Ensure an init function exists so other code can call it safely
    window.initTutorialOverlay = window.initTutorialOverlay || function() {};
} else {
    class TutorialOverlay {
        constructor(steps) {
            this.steps = Array.isArray(steps) ? steps : [];
            this.currentStepIndex = 0;
            this.isActive = false;
            this.activeElement = null;
            this.activeElements = []; // For multi-element highlighting
            this.overlay = null;
            this.card = null;
            this.highlight = null;
            this.highlights = []; // Array for multiple highlight boxes
            this.toggleBtn = null;
            this.originalZIndex = undefined;
            this.originalPosition = undefined;
            this.originalStyles = []; // Store original styles for multiple elements

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
                    <button class="tutorial-close">X</button>
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

            document.body.appendChild(this.overlay);
            document.body.appendChild(this.card); // Card must be sibling of overlay, not child, to escape stacking context

            // Event Listeners
            this.card.querySelector('.tutorial-close').addEventListener('click', () => this.stop());
            this.card.querySelector('.tutorial-btn-prev').addEventListener('click', () => this.prevStep());
            this.card.querySelector('.tutorial-btn-next').addEventListener('click', () => this.nextStep());

            // Close on overlay click (but not during validation steps)
            this.overlay.addEventListener('click', (e) => {
                if (e.target === this.overlay) {
                    const currentStep = this.steps[this.currentStepIndex];
                    // Don't close if current step has validation (user needs to complete the action)
                    if (currentStep && typeof currentStep.validate === 'function') {
                        return; // Don't close during validation steps
                    }
                    this.stop();
                }
            });

            // Resize handler
            window.addEventListener('resize', () => {
                if (this.isActive) this.positionOverlay();
            });

            // Scroll handler (update position to track element)
            window.addEventListener('scroll', () => {
                if (this.isActive) this.positionOverlay();
            }, { passive: true });
        }

        createToggleButton() {
            // If an existing toggle button is present, reuse it and ensure tooltip behavior
            const existingBtn = document.querySelector('.tutorial-toggle-btn');
            if (existingBtn) {
                this.toggleBtn = existingBtn;
                // ensure navy styling
                existingBtn.style.backgroundColor = '#001f3f';
                existingBtn.style.color = '#fff';
                existingBtn.style.border = 'none';
                existingBtn.style.zIndex = '2147483647';
                // Ensure tooltip element exists and is positioned
                if (!document.querySelector('.tutorial-toggle-tooltip')) {
                    attachTooltipToButton(existingBtn);
                }
                return;
            }

            const btn = document.createElement('button');
            btn.className = 'tutorial-toggle-btn';
            btn.innerHTML = '?';
            btn.title = 'Start Tutorial';

            // Navy styling (replace teal)
            btn.style.zIndex = '2147483647';
            btn.style.backgroundColor = '#001f3f';
            btn.style.color = '#ffffff';
            btn.style.border = 'none';
            btn.style.width = '44px';
            btn.style.height = '44px';
            btn.style.borderRadius = '8px';
            btn.style.position = 'fixed';
            btn.style.right = '16px';
            btn.style.bottom = '16px';
            btn.style.display = 'inline-flex';
            btn.style.alignItems = 'center';
            btn.style.justifyContent = 'center';
            btn.style.cursor = 'pointer';

            document.body.appendChild(btn);
            this.toggleBtn = btn;

            attachTooltipToButton(btn);

            btn.addEventListener('click', () => this.toggle());

            // Helper: attach a body-placed tooltip and positioning handlers
            function attachTooltipToButton(buttonEl) {
                const tip = document.createElement('div');
                tip.className = 'tutorial-toggle-tooltip';
                tip.textContent = 'Need help? Click for a tutorial!';
                // Basic inline styles to avoid relying on external CSS
                tip.style.position = 'fixed';
                tip.style.padding = '8px 10px';
                tip.style.background = '#001f3f';
                tip.style.color = '#fff';
                tip.style.borderRadius = '6px';
                tip.style.fontSize = '12px';
                tip.style.zIndex = '2147483646';
                tip.style.display = 'none';
                tip.style.maxWidth = '260px';
                tip.style.boxShadow = '0 6px 18px rgba(0,0,0,0.18)';
                tip.style.lineHeight = '1.2';
                tip.style.wordWrap = 'break-word';

                document.body.appendChild(tip);

                let hideTimeout = null;

                function positionTooltip() {
                    const btnRect = buttonEl.getBoundingClientRect();
                    // force reflow to get tooltip dimensions
                    tip.style.display = 'block';
                    tip.style.visibility = 'hidden';
                    const tipRect = tip.getBoundingClientRect();
                    tip.style.visibility = '';

                    const margin = 8;
                    // Prefer above the button
                    let top = btnRect.top - tipRect.height - margin;
                    let left = btnRect.left + (btnRect.width - tipRect.width) / 2;

                    // If not enough space above, place below
                    if (top < 8) {
                        top = btnRect.bottom + margin;
                    }

                    // Keep tooltip within viewport horizontally
                    if (left < 8) left = 8;
                    const maxLeft = window.innerWidth - tipRect.width - 8;
                    if (left > maxLeft) left = maxLeft;

                    // Final bounds safety
                    if (top < 8) top = 8;

                    tip.style.top = `${Math.round(top)}px`;
                    tip.style.left = `${Math.round(left)}px`;
                    tip.style.display = 'block';
                }

                const show = () => {
                    if (hideTimeout) { clearTimeout(hideTimeout); hideTimeout = null; }
                    positionTooltip();
                };
                const hide = () => {
                    hideTimeout = setTimeout(() => { tip.style.display = 'none'; }, 120);
                };

                buttonEl.addEventListener('mouseenter', show);
                buttonEl.addEventListener('focus', show);
                buttonEl.addEventListener('mouseleave', hide);
                buttonEl.addEventListener('blur', hide);

                // Touch devices: show briefly on touchstart
                buttonEl.addEventListener('touchstart', (ev) => {
                    positionTooltip();
                    setTimeout(() => { tip.style.display = 'none'; }, 2000);
                }, { passive: true });

                // Reposition on resize/scroll
                window.addEventListener('resize', () => { if (tip.style.display === 'block') positionTooltip(); });
                window.addEventListener('scroll', () => { if (tip.style.display === 'block') positionTooltip(); }, { passive: true });
            }
        }

        showNotification() {
            this.toggleBtn.classList.add('has-notification');
        }

        start() {
            this.isActive = true;
            this.currentStepIndex = 0;
            this.overlay.classList.add('active');
            document.body.classList.add('tutorial-active');
            this.showStep(0);
            localStorage.setItem('kop_data_form_tutorial_seen', 'true');
        }

        stop() {
            this.isActive = false;
            this.overlay.classList.remove('active');
            document.body.classList.remove('tutorial-active');
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

            // Execute onNext handler if present
            if (currentStep.onNext) {
                currentStep.onNext();
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

            // Execute onShow handler if present (e.g., expand the toolbar before highlighting
            // elements that live inside its collapsible content)
            if (typeof step.onShow === 'function') {
                step.onShow();
            }

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

            // Auto-expand section if ensureOpen is set - find first CLOSED section and expand it
            if (step.ensureOpen) {
                // Find all sections
                const allSections = document.querySelectorAll('.section, .collapsible-section, .collapsible, .collapsible-container');

                // Find the first section that is NOT expanded
                let firstCollapsedSection = null;
                for (const section of allSections) {
                    if (!section.classList.contains('expanded')) {
                        firstCollapsedSection = section;
                        break;
                    }
                }

                // If we found a collapsed section, try to expand it
                if (firstCollapsedSection) {
                    const toggle = firstCollapsedSection.querySelector('.section-toggle');
                    const header = firstCollapsedSection.querySelector('.section-header');

                    if (toggle) {
                        toggle.click();
                    } else if (header) {
                        header.click();
                    }
                }
            }

            // Position & Highlight
            if (step.target) {
                // Check if we should highlight all matching elements
                if (step.highlightAll) {
                    const targetEls = Array.from(document.querySelectorAll(step.target)).filter(el => el.offsetParent !== null);
                    if (targetEls.length > 0) {
                        if (step.scrollTarget) {
                            const scrollEl = document.querySelector(step.scrollTarget);
                            if (scrollEl) {
                                // Manual scroll calculation to ensure correct positioning under fixed toolbar
                                setTimeout(() => {
                                    const rect = scrollEl.getBoundingClientRect();
                                    const scrollTop = window.pageYOffset || document.documentElement.scrollTop;
                                    const toolbar = document.querySelector('.fixed-toolbar');
                                    const toolbarHeight = toolbar ? toolbar.getBoundingClientRect().height : 0;
                                    const buffer = 40;
                                    const totalOffset = toolbarHeight + buffer;
                                    const targetY = rect.top + scrollTop - totalOffset;
                                    window.scrollTo({
                                        top: targetY,
                                        behavior: 'smooth'
                                    });
                                }, 300);
                            }
                        }
                        
                        // Force sequential mode for last step if detected by title
                        if (step.title.includes('Save & Submit')) step.highlightMode = 'sequential';

                        // If this is the final step and a scrollTarget/container is specified,
                        // elevate the entire container so the whole submission section sits above the overlay.
                        if (index === this.steps.length - 1 && step.scrollTarget) {
                            const container = document.querySelector(step.scrollTarget);
                            if (container) {
                                // Store to restore later
                                this.elevatedContainer = {
                                    el: container,
                                    originalZIndex: container.style.zIndex,
                                    originalPosition: container.style.position
                                };
                                if (window.getComputedStyle(container).position === 'static') {
                                    container.style.position = 'relative';
                                }
                                container.style.zIndex = '200020';
                                container.classList.add('tutorial-active-target');
                            }
                        }

                        this.highlightMultipleElements(targetEls, step.highlightMode, step.highlightPadding);
                        // Position card relative to first element
                        this.positionCard(targetEls[0], step.position || 'bottom');
                    } else {
                        this.clearHighlight();
                        this.centerCard();
                    }
                } else {
                    // Single element highlighting (original behavior)
                    const targetEl = document.querySelector(step.target);
                    if (targetEl && targetEl.offsetParent !== null) {
                        const padding = step.highlightPadding !== undefined ? step.highlightPadding : 5;
                        if (step.scrollTarget) {
                            const scrollEl = document.querySelector(step.scrollTarget);
                            if (scrollEl) {
                                setTimeout(() => {
                                    const rect = scrollEl.getBoundingClientRect();
                                    const scrollTop = window.pageYOffset || document.documentElement.scrollTop;
                                    const toolbar = document.querySelector('.fixed-toolbar');
                                    const toolbarHeight = toolbar ? toolbar.getBoundingClientRect().height : 0;
                                    const buffer = 40;
                                    const totalOffset = toolbarHeight + buffer;
                                    const targetY = rect.top + scrollTop - totalOffset;
                                    window.scrollTo({
                                        top: targetY,
                                        behavior: 'smooth'
                                    });
                                }, 300);
                            }
                        }
                        this.highlightElement(targetEl, padding);
                        this.positionCard(targetEl, step.position || 'bottom');
                    } else {
                        this.clearHighlight();
                        this.centerCard();
                    }
                }
            } else {
                this.clearHighlight();
                this.centerCard();
            }
        }

        highlightElement(element, padding = 5) {
            this.clearHighlight();
            this.activeElement = element;

            const computedStyle = window.getComputedStyle(element);
            const isFixed = computedStyle.position === 'fixed' || element.classList.contains('fixed-toolbar') || element.closest('.fixed-toolbar');

            // Store original inline styles to restore later
            this.originalZIndex = element.style.zIndex;
            this.originalPosition = element.style.position;

            // Handle parent overflows (up to 4 levels to catch toolbar wrappers)
            this.modifiedParents = [];
            let curr = element.parentElement;
            for (let i = 0; i < 4; i++) {
                if (!curr || curr === document.body) break;
                const style = window.getComputedStyle(curr);
                // Check if overflow is restrictive
                if (style.overflow !== 'visible' || style.overflowX !== 'visible' || style.overflowY !== 'visible') {
                    this.modifiedParents.push({
                        el: curr,
                        originalOverflow: curr.style.overflow,
                        originalOverflowX: curr.style.overflowX,
                        originalOverflowY: curr.style.overflowY
                    });
                    curr.style.overflow = 'visible';
                    curr.style.overflowX = 'visible';
                    curr.style.overflowY = 'visible';
                }
                curr = curr.parentElement;
            }

            // Apply class for visual highlight (glow)
            element.classList.add('tutorial-active-target');

            // Position the highlight hole
            if (this.highlight) {
                const rect = element.getBoundingClientRect();
                // Since this.overlay is position: fixed, top 0, left 0,
                // we use the rect relative to the viewport.
                this.highlight.style.display = 'block';
                this.highlight.style.top = (rect.top - padding) + 'px';
                this.highlight.style.left = (rect.left - padding) + 'px';
                this.highlight.style.width = (rect.width + padding * 2) + 'px';
                this.highlight.style.height = (rect.height + padding * 2) + 'px';
            }

            // Ensure z-index works by setting position if static
            if (computedStyle.position === 'static') {
                element.style.position = 'relative';
            }

            // Ensure z-index is higher than overlay
            // Note: The class sets !important, but inline style helps if specificity is tricky
            // We let the class handle the heavy lifting for z-index usually.

            // Scroll Logic
            // Temporarily unlock scroll to allow auto-scroll
            document.body.classList.remove('tutorial-active');

            if (!isFixed) {
                // If element is not currently fully visible, ensure it is scrolled into view.
                // This covers cases where a `scrollTarget` was specified but not found,
                // which previously prevented the element from being scrolled into view.
                try {
                    const rect = element.getBoundingClientRect();
                    const viewportHeight = window.innerHeight || document.documentElement.clientHeight;
                    const fullyVisible = rect.top >= 0 && rect.bottom <= viewportHeight;
                    if (!fullyVisible) {
                        element.scrollIntoView({ behavior: 'auto', block: 'center', inline: 'center' });
                    }
                } catch (e) {
                    // Fallback to naive scroll if something goes wrong
                    try { element.scrollIntoView({ behavior: 'auto', block: 'center', inline: 'center' }); } catch (err) {}
                }
            }

            // Re-lock scrolling handled by overlay class mostly
            document.body.classList.add('tutorial-active');
        }

        highlightMultipleElements(elements, mode, padding = 8) {
            this.clearHighlight();
            this.activeElements = elements;

            // Temporarily unlock scroll
            document.body.classList.remove('tutorial-active');

            const rects = [];

            elements.forEach((element, index) => {
                const computedStyle = window.getComputedStyle(element);

                // Store original styles including parent overflow
                const parent = element.parentElement;
                this.originalStyles[index] = {
                    zIndex: element.style.zIndex,
                    position: element.style.position,
                    backgroundColor: element.style.backgroundColor,
                    parentOverflow: parent ? parent.style.overflow : null
                };

                // Apply logic based on mode
                if (mode === 'sequential') {
                    // Only the first one gets it initially
                    if (index === 0) {
                        element.classList.add('tutorial-active-target');
                    }
                } else {
                    // Simultaneous: All get it
                    element.classList.add('tutorial-active-target');
                    // Stagger animation start times for visual effect
                    if (elements.length > 1) {
                        element.style.setProperty('animation-delay', `${index * 1}s`, 'important');
                    }
                }

                // Ensure z-index works
                if (computedStyle.position === 'static') {
                    element.style.position = 'relative';
                }

                // Force the z-index to be above overlay
                element.style.zIndex = '200025';

                // Collect rect for clip-path
                const rect = element.getBoundingClientRect();
                rects.push({
                    left: rect.left - padding,
                    top: rect.top - padding,
                    right: rect.right + padding,
                    bottom: rect.bottom + padding,
                    width: rect.width + padding * 2,
                    height: rect.height + padding * 2
                });

                // Handle parent overflows (up to 4 levels)
                let curr = parent;
                for (let i = 0; i < 4; i++) {
                    if (!curr || curr === document.body) break;
                    const style = window.getComputedStyle(curr);
                    if (style.overflow !== 'visible' || style.overflowX !== 'visible' || style.overflowY !== 'visible') {
                        // Store if not already stored (to avoid duplicates if multiple elements share parent)
                        const existingMod = this.modifiedParents.find(m => m.el === curr);
                        if (!existingMod) {
                            this.modifiedParents.push({
                                el: curr,
                                originalOverflow: curr.style.overflow,
                                originalOverflowX: curr.style.overflowX,
                                originalOverflowY: curr.style.overflowY
                            });
                            curr.style.overflow = 'visible';
                            curr.style.overflowX = 'visible';
                            curr.style.overflowY = 'visible';
                        }
                    }
                    curr = curr.parentElement;
                }
            });

            // Create clip-path with holes for each element
            // Use an SVG-based approach with multiple rects cut out
            this.createMultiHoleOverlay(rects);

            // Start sequential cycle
            if (mode === 'sequential' && elements.length > 1) {
                let currentIndex = 0;
                this.highlightInterval = setInterval(() => {
                    // Remove class from ALL elements to be safe
                    elements.forEach(el => el.classList.remove('tutorial-active-target'));

                    // Move to next
                    currentIndex = (currentIndex + 1) % elements.length;

                    // Add class to new current
                    elements[currentIndex].classList.add('tutorial-active-target');

                    // Update clip-path to highlight current element more prominently
                    // (optional: could animate the holes)
                }, 2000);
            }

            // Re-lock scrolling
            document.body.classList.add('tutorial-active');
        }

        createMultiHoleOverlay(rects) {
            // Remove existing multi-hole overlay if any
            const existing = document.getElementById('tutorial-multi-hole-overlay');
            if (existing) existing.remove();

            const vw = window.innerWidth;
            const vh = window.innerHeight;

            // Create SVG overlay with holes
            const svg = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
            svg.id = 'tutorial-multi-hole-overlay';
            svg.setAttribute('width', '100%');
            svg.setAttribute('height', '100%');
            svg.style.cssText = `
                position: fixed;
                top: 0;
                left: 0;
                width: 100vw;
                height: 100vh;
                z-index: 199995;
                pointer-events: none;
            `;

            // Create defs with mask
            const defs = document.createElementNS('http://www.w3.org/2000/svg', 'defs');
            const mask = document.createElementNS('http://www.w3.org/2000/svg', 'mask');
            mask.id = 'tutorial-holes-mask';

            // White background (visible area)
            const whiteBg = document.createElementNS('http://www.w3.org/2000/svg', 'rect');
            whiteBg.setAttribute('x', '0');
            whiteBg.setAttribute('y', '0');
            whiteBg.setAttribute('width', '100%');
            whiteBg.setAttribute('height', '100%');
            whiteBg.setAttribute('fill', 'white');
            mask.appendChild(whiteBg);

            // Black rects for holes (transparent areas)
            rects.forEach(r => {
                const hole = document.createElementNS('http://www.w3.org/2000/svg', 'rect');
                hole.setAttribute('x', r.left);
                hole.setAttribute('y', r.top);
                hole.setAttribute('width', r.width);
                hole.setAttribute('height', r.height);
                hole.setAttribute('rx', '8');
                hole.setAttribute('ry', '8');
                hole.setAttribute('fill', 'black');
                mask.appendChild(hole);
            });

            defs.appendChild(mask);
            svg.appendChild(defs);

            // Dark overlay rect with mask applied
            const overlay = document.createElementNS('http://www.w3.org/2000/svg', 'rect');
            overlay.setAttribute('x', '0');
            overlay.setAttribute('y', '0');
            overlay.setAttribute('width', '100%');
            overlay.setAttribute('height', '100%');
            overlay.setAttribute('fill', 'rgba(0, 0, 0, 0.5)');
            overlay.setAttribute('mask', 'url(#tutorial-holes-mask)');
            svg.appendChild(overlay);

            document.body.appendChild(svg);
            this.multiHoleOverlay = svg;
        }

        clearHighlight() {
            // Clear interval if exists
            if (this.highlightInterval) {
                clearInterval(this.highlightInterval);
                this.highlightInterval = null;
            }

            // Reset overlay background (used for multi-element highlighting)
            if (this.overlay) {
                this.overlay.style.backgroundColor = 'transparent';
            }

            // Remove multi-hole SVG overlay if exists
            if (this.multiHoleOverlay) {
                this.multiHoleOverlay.remove();
                this.multiHoleOverlay = null;
            }
            // Also check by ID in case reference was lost
            const existingSvg = document.getElementById('tutorial-multi-hole-overlay');
            if (existingSvg) existingSvg.remove();

            // Clear single highlight box
            if (this.highlight) {
                this.highlight.style.display = 'none';
            }

            // Clear multiple highlight boxes (if any were created)
            this.highlights.forEach(highlightBox => {
                if (highlightBox && highlightBox.parentNode) {
                    highlightBox.parentNode.removeChild(highlightBox);
                }
            });
            this.highlights = [];

            // Clear single active element
            if (this.activeElement) {
                this.activeElement.classList.remove('tutorial-active-target');

                // Restore original styles
                if (this.originalZIndex !== undefined) {
                    this.activeElement.style.zIndex = this.originalZIndex;
                } else {
                    this.activeElement.style.removeProperty('z-index');
                }

                if (this.originalPosition !== undefined) {
                    this.activeElement.style.position = this.originalPosition;
                } else {
                    this.activeElement.style.removeProperty('position');
                }

                // Restore parent overflows
                if (this.modifiedParents) {
                    this.modifiedParents.forEach(p => {
                        if (p.originalOverflow !== undefined && p.originalOverflow !== '') p.el.style.overflow = p.originalOverflow;
                        else p.el.style.removeProperty('overflow');
                        
                        if (p.originalOverflowX !== undefined && p.originalOverflowX !== '') p.el.style.overflowX = p.originalOverflowX;
                        else p.el.style.removeProperty('overflow-x');

                        if (p.originalOverflowY !== undefined && p.originalOverflowY !== '') p.el.style.overflowY = p.originalOverflowY;
                        else p.el.style.removeProperty('overflow-y');
                    });
                    this.modifiedParents = [];
                }

                this.activeElement = null;
                this.originalZIndex = undefined;
                this.originalPosition = undefined;
                this.originalParentOverflow = undefined;
            }

            // Clear multiple active elements
            if (this.activeElements && this.activeElements.length > 0) {
                this.activeElements.forEach((element, index) => {
                    element.classList.remove('tutorial-active-target');
                    element.style.removeProperty('animation-delay');

                    // Restore original styles
                    const originalStyle = this.originalStyles[index];
                    if (originalStyle) {
                        if (originalStyle.zIndex !== undefined && originalStyle.zIndex !== '') {
                            element.style.zIndex = originalStyle.zIndex;
                        } else {
                            element.style.removeProperty('z-index');
                        }

                        if (originalStyle.position !== undefined && originalStyle.position !== '') {
                            element.style.position = originalStyle.position;
                        } else {
                            element.style.removeProperty('position');
                        }

                        if (originalStyle.backgroundColor !== undefined && originalStyle.backgroundColor !== '') {
                            element.style.backgroundColor = originalStyle.backgroundColor;
                        } else {
                            element.style.removeProperty('background-color');
                        }

                        // Restore parent overflow
                        const parent = element.parentElement;
                        if (parent && originalStyle.parentOverflow !== null) {
                            if (originalStyle.parentOverflow !== undefined && originalStyle.parentOverflow !== '') {
                                parent.style.overflow = originalStyle.parentOverflow;
                            } else {
                                parent.style.removeProperty('overflow');
                            }
                        }
                    }
                });

                this.activeElements = [];
                this.originalStyles = [];
            }

            // Restore any elevated container (e.g., submission section for final step)
            if (this.elevatedContainer) {
                const c = this.elevatedContainer;
                if (c.el) {
                    if (c.originalZIndex !== undefined && c.originalZIndex !== '') c.el.style.zIndex = c.originalZIndex;
                    else c.el.style.removeProperty('z-index');

                    if (c.originalPosition !== undefined && c.originalPosition !== '') c.el.style.position = c.originalPosition;
                    else c.el.style.removeProperty('position');

                    c.el.classList.remove('tutorial-active-target');
                }
                this.elevatedContainer = null;
            }
        }

        positionCard(targetEl, position) {
            this.card.style.position = 'fixed';
            this.card.style.width = '320px';

            const targetRect = targetEl.getBoundingClientRect();
            const cardRect = this.card.getBoundingClientRect();
            const viewportHeight = window.innerHeight;
            const viewportWidth = window.innerWidth;

            let top, left;
            const margin = 15;

            switch (position) {
                case 'bottom':
                    top = targetRect.bottom + margin;
                    left = targetRect.left + (targetRect.width / 2) - (cardRect.width / 2);

                    // If card would go off bottom, place above instead
                    if (top + cardRect.height > viewportHeight - 10) {
                        top = targetRect.top - cardRect.height - margin;
                    }
                    // Final vertical clamp (card is above/below target, so this won't cause overlap)
                    if (top < 10) top = 10;
                    break;
                case 'top':
                    top = targetRect.top - cardRect.height - margin;
                    left = targetRect.left + (targetRect.width / 2) - (cardRect.width / 2);

                    // If card would go off top, place below instead
                    if (top < 10) {
                        top = targetRect.bottom + margin;
                    }
                    // Final vertical clamp
                    if (top + cardRect.height > viewportHeight - 10) top = viewportHeight - cardRect.height - 10;
                    break;
                case 'right': {
                    top = targetRect.top + (targetRect.height / 2) - (cardRect.height / 2);
                    left = targetRect.right + margin;

                    // If card doesn't fit to the right, flip to the left side
                    if (left + cardRect.width > viewportWidth - 10) {
                        left = targetRect.left - cardRect.width - margin;
                    }
                    // If left side also doesn't fit, fall back to below/above the target
                    if (left < 10) {
                        left = targetRect.left + (targetRect.width / 2) - (cardRect.width / 2);
                        top = targetRect.bottom + margin;
                        if (top + cardRect.height > viewportHeight - 10) {
                            top = targetRect.top - cardRect.height - margin;
                        }
                    }

                    // Final clamp on both axes — last resort to keep card on-screen
                    if (top < 10) top = 10;
                    if (top + cardRect.height > viewportHeight - 10) top = viewportHeight - cardRect.height - 10;
                    if (left < 10) left = 10;
                    if (left + cardRect.width > viewportWidth - 10) left = viewportWidth - cardRect.width - 10;

                    this.card.style.top = top + 'px';
                    this.card.style.left = left + 'px';
                    this.card.style.transform = 'none';
                    return;
                }
                case 'left': {
                    top = targetRect.top + (targetRect.height / 2) - (cardRect.height / 2);
                    left = targetRect.left - cardRect.width - margin;

                    // If card doesn't fit to the left, flip to the right side
                    if (left < 10) {
                        left = targetRect.right + margin;
                    }
                    // If right side also doesn't fit, fall back to below/above the target
                    if (left + cardRect.width > viewportWidth - 10) {
                        left = targetRect.left + (targetRect.width / 2) - (cardRect.width / 2);
                        top = targetRect.bottom + margin;
                        if (top + cardRect.height > viewportHeight - 10) {
                            top = targetRect.top - cardRect.height - margin;
                        }
                    }

                    // Final clamp on both axes — last resort to keep card on-screen
                    if (top < 10) top = 10;
                    if (top + cardRect.height > viewportHeight - 10) top = viewportHeight - cardRect.height - 10;
                    if (left < 10) left = 10;
                    if (left + cardRect.width > viewportWidth - 10) left = viewportWidth - cardRect.width - 10;

                    this.card.style.top = top + 'px';
                    this.card.style.left = left + 'px';
                    this.card.style.transform = 'none';
                    return;
                }
                default: // center
                    this.centerCard();
                    return;
            }

            // Final horizontal clamp for top/bottom — card is above/below target so horizontal
            // clamping cannot cause vertical overlap with the target
            if (left < 10) left = 10;
            if (left + cardRect.width > viewportWidth - 10) {
                left = viewportWidth - cardRect.width - 10;
            }

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
    const initTutorialOverlay = () => {
        // Only run on data form pages
        if (!document.querySelector('.category-navigation') && !document.querySelector('#category-navigation')) return;
        if (window.kopTutorial) return;

        const tutorialSteps = [
            {
                title: 'Welcome to the Data Form!',
                content: 'This tool helps us collect and organize information about the Troubled Teen Industry. This quick tutorial will guide you through the basics.<br><br><strong>DIY Tip:</strong> Tap (mobile) or hover (desktop) over the <span style="display: inline-flex; align-items: center; justify-content: center; width: 16px; height: 16px; background-color: #6b7280; color: white; border-radius: 50%; font-size: 11px; font-weight: bold; margin: 0 3px;">?</span> icons next to field labels for detailed explanations of what data to enter.',
                target: null // Center
            },
            {
                title: 'Category Selection',
                content: 'Let\'s explore the three types of data you can collect. Each category organizes information differently to help you research the TTI effectively.',
                target: '.category-tabs',
                position: 'bottom'
            },
            {
                title: 'Parent Companies',
                content: 'Track TTI programs organized by their parent companies and corporate owners. Use this to research ownership structures, corporate networks, and which companies operate multiple facilities.',
                target: '.category-tab[data-category="companies"]',
                position: 'bottom'
            },
            {
                title: 'Locations',
                content: 'Browse programs organized by state or country. Useful for logging facilities that are not part of a bigger chain or parent organization..',
                target: '.category-tab[data-category="locations"]',
                position: 'bottom'
            },
            {
                title: 'Referrers',
                content: 'Track education consultants and agencies that refer kids to TTI programs. Research who is sending children to these facilities and their professional networks.',
                target: '.category-tab[data-category="referrers"]',
                position: 'bottom'
            },
            {
                title: 'Choose a Category to Edit',
                content: 'Pick the category tab that matches the data you want to update. This keeps saved projects and fields organized by type.',
                target: '.category-tabs',
                position: 'bottom'
            },
            {
                title: 'Select a Saved Project',
                content: 'Click a project in the Saved Projects list to load it for editing.',
                target: '#company-saved-projects-list, #location-saved-projects-list, #referrer-saved-projects-list',
                position: 'right',
                scrollTarget: '#company-saved-projects-list, #location-saved-projects-list, #referrer-saved-projects-list',
                highlightPadding: 8
            },
            {
                title: 'Select an Entry',
                content: 'Use the dropdown in the toolbar OR the facility loader panel to choose the facility or consultant you want to edit.',
                target: '.facility-loader-panel, #facility-dropdown',
                highlightAll: true,
                position: 'bottom',
                scrollTarget: '.facility-loader-panel',
                highlightPadding: 2
            },
                        {
                title: 'Expanding Sections',
                content: 'Sections can be expanded or minimized to focus on one part of the form. Click the section header or toggle to open it so you can access the fields and note buttons.',
                target: '#operator-section .section-header',
                position: 'right',
                highlightPadding: 50,
                scrollTarget: '#operator-section',
                onNext: () => {
                    const operatorSection = document.querySelector('#operator-section');
                    if (operatorSection && !operatorSection.classList.contains('expanded')) {
                        const header = operatorSection.querySelector('.section-header');
                        if (header) header.click();
                    }
                }
            },
            {
                title: 'Autocomplete & Field Help',
                content: 'Many fields have autocomplete to help prevent duplicates. Start typing to see existing entries.<br><br><strong>DIY Remember:</strong> Each field has a <span style="display: inline-flex; align-items: center; justify-content: center; width: 16px; height: 16px; background-color: #6b7280; color: white; border-radius: 50%; font-size: 11px; font-weight: bold; margin: 0 3px;">?</span> tooltip explaining what data is expected. Tap or hover over these icons for guidance!',
                target: '#operator-name',
                position: 'bottom',
                // Scroll the surrounding content into view so fields are visible
                scrollTarget: '#operator-name',
                highlightPadding: 8
            },
            {
                title: 'Adding Notes',
                content: 'Look for note icons next to important fields. Click them to add <strong>context, sources, links, or additional details</strong> that don\'t fit in the main field. These notes are saved with your data and help reviewers verify your information.',
                // Target common note button/icon selectors so the tutorial highlights the actionable buttons
                target: '.note-icon, .note-btn, .note-button, button.note, .field-note-btn',
                position: 'left',
                highlightPadding: 14,
                highlightAll: true
            },
            {
                title: 'Open the Data Toolbar',
                content: 'The <strong>Data Toolbar</strong> sits pinned at the top of the screen and stays visible as you scroll. It starts minimized to save space. Click the <strong>▼ button</strong> on the right of the toolbar (or just click <strong>Next</strong>) to expand it and reveal the quick-action buttons.',
                target: '#toolbar-toggle-btn',
                position: 'bottom',
                highlightPadding: 6,
                onNext: () => {
                    const toolbar = document.getElementById('fixed-toolbar');
                    if (toolbar && toolbar.classList.contains('minimized')) {
                        const toggle = document.getElementById('toolbar-toggle-btn');
                        if (toggle) toggle.click();
                    }
                }
            },
            {
                title: 'The Data Toolbar',
                content: 'Now that it\'s open, this toolbar provides quick access to essential functions. Let\'s explore each button...',
                target: '.toolbar-content',
                position: 'bottom',
                // Defensive: make sure the toolbar is expanded (e.g. when navigating back to
                // this step) so the buttons are visible and the card doesn't land on the toolbar.
                onShow: () => {
                    const toolbar = document.getElementById('fixed-toolbar');
                    if (toolbar && toolbar.classList.contains('minimized')) {
                        const toggle = document.getElementById('toolbar-toggle-btn');
                        if (toggle) toggle.click();
                    }
                    // Reposition after the expand animation settles (max-height transition ~0.3s).
                    // Runs unconditionally because the previous step's onNext may have just
                    // triggered the expand, so the .toolbar-content is still animating from 0 height.
                    setTimeout(() => {
                        if (window.kopTutorial && window.kopTutorial.isActive) {
                            window.kopTutorial.positionOverlay();
                        }
                    }, 350);
                }
            },
            {
                title: 'New Project',
                content: 'Start a brand new data collection project at any time.',
                target: '#new-project-btn-toolbar',
                position: 'bottom'
            },
            {
                title: 'Generate Report',
                content: 'Create a formatted, printable report and open it in a new window. You can then print it or save it as a PDF.',
                target: '#generate-report-btn-toolbar',
                position: 'bottom'
            },
            {
                title: 'Add Entry',
                content: 'Add a new facility, program, or consultant entry to your current project.',
                target: '#add-facility-btn-toolbar',
                position: 'bottom'
            },
            {
                title: 'Navigation Controls',
                content: 'Use the arrows (Prev/Next) or dropdown menu to navigate between different entries in your project.',
                target: '#prev-facility-btn-toolbar, #facility-dropdown, #next-facility-btn-toolbar',
                highlightAll: true,
                position: 'bottom',
                highlightPadding: 4
            },
            {
                title: 'Jump to Top',
                content: 'Click this button to quickly scroll back to the top of the page. Useful when working with long forms.',
                target: '#scroll-to-top-btn-toolbar',
                position: 'bottom'
            },
            {
                title: 'Search Data',
                content: 'Search and organize your facility data by various criteria like staff, location, or program type.',
                target: '#show-organizer-modal-btn',
                position: 'bottom'
            },
            {
                title: 'Save & Submit Your Work',
                content: 'Your work is NOT automatically sent to us. Use <strong>Save Draft Locally</strong> to save your progress in your browser and continue later.<br><br>When finished, click <strong>Submit for Review</strong> to send your data to our team for verification and inclusion in the master database.',
                target: '#save-draft-locally-btn, #submit-suggestion-btn-section, #submit-suggestion-btn-toolbar',
                highlightAll: true,
                highlightMode: 'sequential',
                position: 'top',
                scrollTarget: '#submission-section'
            }
        ];

        window.kopTutorial = new TutorialOverlay(tutorialSteps);
    };

    window.initTutorialOverlay = initTutorialOverlay;

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initTutorialOverlay);
    } else {
        initTutorialOverlay();
    }

    // Retry after form data modules finish wiring the page.
    document.addEventListener('formReady', initTutorialOverlay, { once: true });

    // Last-chance delayed init in case markup arrives late.
    setTimeout(initTutorialOverlay, 1500);
}







