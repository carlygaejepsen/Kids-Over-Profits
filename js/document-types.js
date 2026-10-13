/**
 * /documents/<type>/ (templates/document-types.php): filter the documents by
 * program or title words, state and decade. Sections with nothing left are
 * hidden. Without this script the page is a plain list.
 */
(function () {
    'use strict';

    function fold(s) {
        return String(s || '').toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, '').replace(/\s+/g, ' ').trim();
    }

    function init() {
        var form = document.querySelector('.kop-dt-filter');
        if (!form) return;
        var places = Array.prototype.slice.call(document.querySelectorAll('.kop-dt-place'));
        var nomatch = document.querySelector('.kop-dt-nomatch');
        var count = form.querySelector('.kop-dt-filter__count');
        var q = form.querySelector('[name=q]');
        var state = form.querySelector('[name=state]');
        var decade = form.querySelector('[name=decade]');
        var total = document.querySelectorAll('.kop-dt-doc').length;
        form.hidden = false;
        form.addEventListener('submit', function (e) { e.preventDefault(); });

        function apply() {
            var words = fold(q.value).split(' ').filter(Boolean);
            var st = state ? state.value : '';
            var dec = decade ? decade.value.slice(0, 3) : '';
            var shown = 0;
            places.forEach(function (sec) {
                var name = fold(sec.getAttribute('data-name'));
                var stateOk = !st || sec.getAttribute('data-state') === st;
                var left = 0;
                sec.querySelectorAll('.kop-dt-doc').forEach(function (li) {
                    var hay = name + ' ' + fold(li.getAttribute('data-title'));
                    var ok = stateOk
                        && (!dec || (li.getAttribute('data-year') || '').slice(0, 3) === dec)
                        && words.every(function (w) { return hay.indexOf(w) !== -1; });
                    li.hidden = !ok;
                    if (ok) left++;
                });
                sec.hidden = left === 0;
                shown += left;
            });
            var filtered = words.length || st || dec;
            count.textContent = filtered ? 'Showing ' + shown + ' of ' + total + '.' : '';
            if (nomatch) nomatch.hidden = shown !== 0;
        }

        var timer = null;
        q.addEventListener('input', function () { clearTimeout(timer); timer = setTimeout(apply, 150); });
        if (state) state.addEventListener('change', apply);
        if (decade) decade.addEventListener('change', apply);
    }

    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
    else init();
})();
