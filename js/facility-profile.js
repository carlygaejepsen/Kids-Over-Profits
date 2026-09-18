/*
 * Facility profile pages: let the facts rail follow the reader when it is
 * shorter than the window. A rail taller than the window scrolls with the
 * page as before, so nothing in it becomes unreachable.
 */
(function () {
    'use strict';
    var rail = document.querySelector('.kop-fp-rail');
    var body = document.querySelector('.kop-fp-body');
    if (!rail || !body) {
        return;
    }
    function stickyOffset() {
        // Kadence's sticky header, when enabled, is a fixed element at the top.
        var top = 0;
        var candidates = document.querySelectorAll('#masthead, #masthead *');
        for (var i = 0; i < candidates.length; i++) {
            var style = window.getComputedStyle(candidates[i]);
            if ((style.position === 'fixed' || style.position === 'sticky') && candidates[i].offsetHeight > 0) {
                top = Math.max(top, candidates[i].getBoundingClientRect().bottom);
            }
        }
        return Math.max(20, Math.round(top) + 20);
    }
    function update() {
        var offset = stickyOffset();
        var fits = window.innerWidth >= 960
            && rail.offsetHeight + offset + 20 < window.innerHeight
            && body.offsetHeight > rail.offsetHeight;
        rail.style.setProperty('--kop-fp-sticky-top', offset + 'px');
        rail.classList.toggle('is-sticky', fits);
    }
    update();
    window.addEventListener('resize', update);
    window.addEventListener('load', update);
})();
