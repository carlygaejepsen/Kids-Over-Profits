/*
 * Document Archive: the "Find a program" filter over the A to Z list
 * (templates/page-document-archive.php). Without JavaScript the full list
 * shows and the filter stays hidden.
 */
(function () {
    'use strict';

    var box = document.querySelector('.kop-da-filter');
    var input = document.getElementById('kop-da-filter-input');
    if (!box || !input) {
        return;
    }
    var items = Array.prototype.slice.call(document.querySelectorAll('.kop-da-az li[data-name]'));
    var letters = Array.prototype.slice.call(document.querySelectorAll('.kop-da-letter'));
    var empty = box.querySelector('.kop-da-filter-empty');

    function apply() {
        var q = input.value.trim().toLowerCase();
        var shown = 0;
        items.forEach(function (li) {
            var hit = q === '' || li.getAttribute('data-name').indexOf(q) !== -1;
            li.hidden = !hit;
            if (hit) {
                shown++;
            }
        });
        letters.forEach(function (block) {
            block.hidden = !block.querySelector('li[data-name]:not([hidden])');
        });
        if (empty) {
            empty.hidden = shown !== 0;
        }
    }

    box.hidden = false;
    input.addEventListener('input', apply);
})();
