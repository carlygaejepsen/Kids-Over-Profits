/**
 * Readable Givebutter donate button.
 *
 * The <givebutter-widget> draws its button inside two nested shadow roots,
 * white on the brand colour set in the Givebutter dashboard (#FF8A00, 2.2:1,
 * below WCAG AA). Page CSS cannot reach into a shadow root, so this adds one
 * rule inside it: the orange fill from css/colors.css (--kop-orange-fill,
 * white on it 5.35:1). Changing the button colour in the Givebutter dashboard
 * to #A3570D makes this a no-op.
 */
(function () {
    'use strict';

    var CSS = 'button{background-color:#A3570D !important;border-color:#A3570D !important;color:#FFFFFF !important;}'
        + 'button:hover,button:focus{background-color:#000080 !important;border-color:#000080 !important;}';

    function patch(root) {
        if (!root || root.__kopContrast) {
            return !!root;
        }
        var style = document.createElement('style');
        style.textContent = CSS;
        root.appendChild(style);
        root.__kopContrast = true;
        return true;
    }

    function run() {
        var widgets = document.querySelectorAll('givebutter-widget');
        var pending = 0;
        for (var i = 0; i < widgets.length; i++) {
            var outer = widgets[i].shadowRoot;
            var inner = outer && outer.querySelector('givebutter-button');
            if (!inner || !inner.shadowRoot || !inner.shadowRoot.querySelector('button')) {
                pending++;
                continue;
            }
            patch(inner.shadowRoot);
        }
        return pending;
    }

    // The widget script loads on its own schedule: look again for ~15 seconds.
    var tries = 0;
    function tick() {
        if (run() > 0 && tries++ < 30) {
            setTimeout(tick, 500);
        }
    }
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', tick);
    } else {
        tick();
    }
})();
