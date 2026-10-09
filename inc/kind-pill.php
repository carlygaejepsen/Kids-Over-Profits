<?php
/**
 * The small "Company" / "Facility" pill beside a name in any list that can
 * hold both kinds. A parent company and a program can carry the very same
 * name (Embark Behavioral Health renamed its programs to the company's
 * name), so a row's name alone does not say which record it is.
 *
 * kop_kind_pill($kind) in PHP and kopKindPill(kind) in JS print the same
 * markup; the CSS and the JS helper are printed on every front-end and admin
 * page, like kopIcon() (inc/icons.php), so any script can call it.
 * scripts/test-kind-pill.php checks PHP == JS.
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!function_exists('kop_kind_pill_word')) {
    /** 'operator'/'company' -> Company, 'facility'/'program' -> Facility, else ''. */
    function kop_kind_pill_word($kind) {
        $kind = strtolower(trim((string)$kind));
        if ($kind === 'operator' || $kind === 'company' || $kind === 'companies') return 'Company';
        if ($kind === 'facility' || $kind === 'program' || $kind === 'facilities') return 'Facility';
        return '';
    }
}

if (!function_exists('kop_kind_pill')) {
    function kop_kind_pill($kind) {
        $word = kop_kind_pill_word($kind);
        if ($word === '') return '';
        return '<span class="kop-kind kop-kind--' . strtolower($word) . '">' . $word . '</span>';
    }
}

if (!function_exists('kop_kind_pill_css')) {
    function kop_kind_pill_css() {
        // Midnight on mint 15.6:1, on pastel yellow 19.6:1; the border keeps
        // the pill visible on a white or sand row.
        return '.kop-kind{display:inline-block;vertical-align:middle;margin:0 6px 0 0;padding:1px 7px;'
            . 'border-radius:999px;font-size:11px;line-height:1.5;font-weight:700;letter-spacing:.02em;'
            . 'white-space:nowrap;color:#000435;background:#B6E3D4;border:1px solid #24757F}'
            . '.kop-kind--company{background:#FFF5CB;border-color:#A3570D}';
    }
}

if (!function_exists('kop_kind_pill_js')) {
    function kop_kind_pill_js() {
        return <<<'JS'
window.kopKindPillWord = function (kind) {
    var k = String(kind || '').trim().toLowerCase();
    if (k === 'operator' || k === 'company' || k === 'companies') return 'Company';
    if (k === 'facility' || k === 'program' || k === 'facilities') return 'Facility';
    return '';
};
window.kopKindPill = function (kind) {
    var word = window.kopKindPillWord(kind);
    if (!word) return '';
    return '<span class="kop-kind kop-kind--' + word.toLowerCase() + '">' + word + '</span>';
};
window.kopKindPillNode = function (kind) {
    var word = window.kopKindPillWord(kind);
    if (!word) return null;
    var span = document.createElement('span');
    span.className = 'kop-kind kop-kind--' + word.toLowerCase();
    span.textContent = word;
    return span;
};
JS;
    }
}

if (!function_exists('kop_enqueue_kind_pill')) {
    function kop_enqueue_kind_pill() {
        wp_register_style('kop-kind-pill', false, array(), null);
        wp_enqueue_style('kop-kind-pill');
        wp_add_inline_style('kop-kind-pill', kop_kind_pill_css());
        wp_register_script('kop-kind-pill', false, array(), null, false);
        wp_enqueue_script('kop-kind-pill');
        wp_add_inline_script('kop-kind-pill', kop_kind_pill_js());
    }
    add_action('wp_enqueue_scripts', 'kop_enqueue_kind_pill', 1);
    add_action('admin_enqueue_scripts', 'kop_enqueue_kind_pill', 1);
}
