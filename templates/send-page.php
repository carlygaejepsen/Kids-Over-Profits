<?php
/**
 * /send/: send a link to KOP from any browser (inc/send-page.php routes here).
 * The form is posted by js/send-page.js to kop/v1/mobile/submit; css/send-page.css.
 */

if (!defined('ABSPATH')) {
    exit;
}

$kop_sp = isset($GLOBALS['kop_send_page']) && is_array($GLOBALS['kop_send_page']) ? $GLOBALS['kop_send_page'] : array();
$kop_sp_pre = isset($kop_sp['prefill']) && is_array($kop_sp['prefill']) ? $kop_sp['prefill'] : array();
$kop_sp_url = (string) ($kop_sp_pre['url'] ?? '');
$kop_sp_title = (string) ($kop_sp_pre['title'] ?? '');
$kop_sp_text = (string) ($kop_sp_pre['text'] ?? '');
$kop_sp_icon = function ($name) {
    return function_exists('kop_icon') ? kop_icon($name, array('class' => 'kop-send__icon')) : '';
};

get_header();
?>
<article class="entry content-bg single-entry kop-send-page" data-kop-bug-feature="send-page" data-kop-bug-label="Send to KOP">
    <div class="entry-content-wrap">

        <header class="entry-header kop-send__header">
            <h1 class="entry-title">Send to KOP</h1>
            <p class="kop-send__lede">Found an article, lawsuit, bill or program website we are missing? Send the link. A person reviews everything before it appears on the site.</p>
        </header>

        <section class="kop-send__panel" aria-labelledby="kop-send-form-title">
            <h2 id="kop-send-form-title">Send a link</h2>
            <form id="kop-send-form" class="kop-send__form" novalidate>
                <div class="kop-send__field">
                    <label for="kop-send-url">Link</label>
                    <input type="url" id="kop-send-url" name="url" inputmode="url" autocomplete="off" required placeholder="https://" value="<?php echo esc_attr($kop_sp_url); ?>">
                    <p class="kop-send__status" id="kop-send-check" role="status" aria-live="polite" hidden></p>
                </div>

                <div class="kop-send__field">
                    <label for="kop-send-kind">What is it?</label>
                    <select id="kop-send-kind" name="type">
                        <option value="article">News article</option>
                        <option value="lawsuit">Lawsuit or court record</option>
                        <option value="legislation">Bill</option>
                        <option value="website">Website or other page</option>
                    </select>
                </div>

                <div class="kop-send__field">
                    <label for="kop-send-title">Title</label>
                    <input type="text" id="kop-send-title" name="title" maxlength="500" value="<?php echo esc_attr($kop_sp_title); ?>">
                </div>

                <div class="kop-send__row" data-kop-send-for="article">
                    <div class="kop-send__field">
                        <label for="kop-send-outlet">Outlet</label>
                        <input type="text" id="kop-send-outlet" name="site_name" maxlength="255" placeholder="Where it was published">
                    </div>
                    <div class="kop-send__field">
                        <label for="kop-send-date">Date</label>
                        <input type="date" id="kop-send-date" name="published">
                    </div>
                </div>

                <div class="kop-send__row" data-kop-send-for="lawsuit" hidden>
                    <div class="kop-send__field">
                        <label for="kop-send-case">Case number</label>
                        <input type="text" id="kop-send-case" name="case_number" maxlength="120">
                    </div>
                    <div class="kop-send__field">
                        <label for="kop-send-court">Court</label>
                        <input type="text" id="kop-send-court" name="court" maxlength="255">
                    </div>
                </div>

                <div class="kop-send__row kop-send__row--three" data-kop-send-for="legislation" hidden>
                    <div class="kop-send__field">
                        <label for="kop-send-bill">Bill number</label>
                        <input type="text" id="kop-send-bill" name="bill_number" maxlength="60" placeholder="HB 123">
                    </div>
                    <div class="kop-send__field">
                        <label for="kop-send-state">State</label>
                        <input type="text" id="kop-send-state" name="jurisdiction" maxlength="40" placeholder="CA, or US">
                    </div>
                    <div class="kop-send__field">
                        <label for="kop-send-session">Session</label>
                        <input type="text" id="kop-send-session" name="session" maxlength="50" placeholder="2025">
                    </div>
                </div>

                <div class="kop-send__field">
                    <label for="kop-send-facility">Program it is about <span class="kop-send__optional">(optional)</span></label>
                    <input type="text" id="kop-send-facility" name="facility" maxlength="255" list="kop-send-facility-list" autocomplete="off">
                    <datalist id="kop-send-facility-list"></datalist>
                </div>

                <div class="kop-send__field">
                    <label for="kop-send-notes">Notes <span class="kop-send__optional">(optional)</span></label>
                    <textarea id="kop-send-notes" name="notes" rows="4" maxlength="2000"><?php echo esc_textarea($kop_sp_text); ?></textarea>
                    <p class="kop-send__hint">Text you highlighted on the page lands here. Add why it matters, if you like.</p>
                </div>

                <div class="kop-send__trap" aria-hidden="true">
                    <label for="kop-send-hp">Leave this empty</label>
                    <input type="text" id="kop-send-hp" name="website_hp" tabindex="-1" autocomplete="off" value="">
                </div>

                <?php
                if (function_exists('kop_followup_fields')) {
                    echo kop_followup_fields('kop-send-followup');
                }
                ?>

                <div class="kop-send__actions">
                    <button type="submit" class="kop-send__submit"><?php echo $kop_sp_icon('send'); ?><span>Send to KOP</span></button>
                </div>
                <div id="kop-send-result" class="kop-send__result" role="status" aria-live="polite" tabindex="-1" hidden></div>
            </form>
        </section>

        <section class="kop-send__panel" aria-labelledby="kop-send-ways-title">
            <h2 id="kop-send-ways-title">Send from anywhere you read</h2>
            <p>Three ways to send a page without copying its link here first.</p>

            <h3>1. A bookmark button</h3>
            <p>Works in any browser. Drag this button to your bookmarks bar. On any page, click it: this form opens with the page's link, title and any text you highlighted.</p>
            <p><a class="kop-send__bookmarklet" id="kop-send-bookmarklet" href="<?php echo esc_attr(kop_send_page_bookmarklet()); ?>"><?php echo $kop_sp_icon('send'); ?><span>Send to KOP</span></a></p>
            <p>On a phone you cannot drag. Add any page as a bookmark, then edit the bookmark and replace its address with this code.</p>
            <p>
                <label class="kop-send__codelabel" for="kop-send-code">Bookmark code</label>
                <textarea id="kop-send-code" class="kop-send__code" rows="4" readonly><?php echo esc_textarea(kop_send_page_bookmarklet()); ?></textarea>
            </p>
            <p><button type="button" class="kop-send__copy" id="kop-send-copy">Copy the code</button> <span class="kop-send__copied" id="kop-send-copied" role="status" aria-live="polite"></span></p>

            <h3>2. The browser extension</h3>
            <p>For Chrome, Edge, Firefox and Safari. It adds a Send to KOP button to the toolbar that fills in the page's details for you. The store listings are coming soon.</p>

            <h3>3. The Kids Over Profits app</h3>
            <p>Use the Share button in any app or browser on your phone and choose Kids Over Profits.</p>
        </section>
    </div>
</article>
<?php
get_footer();
