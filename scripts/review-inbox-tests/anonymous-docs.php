<?php
/**
 * scripts/test-review-inbox.php checks for the 'anonymous-docs' source
 * (inc/review-inbox/anonymous-docs.php over AnonymousDocPortal in
 * inc/features.php). Two made-up sealed files go in the test uploads folder
 * and are removed at the end.
 */

if (!function_exists('add_shortcode')) {
    function add_shortcode() {}
}
if (!class_exists('AnonymousDocPortal')) {
    require_once dirname(__DIR__, 2) . '/inc/features.php';
}

$GLOBALS['kop_rinbox_test_anon'] = (function () {
    $dir = wp_upload_dir()['basedir'] . '/anonymous-submissions/';
    if (!is_dir($dir)) mkdir($dir, 0700, true);
    $names = array();
    foreach (array(-3600, 0) as $i => $age) {
        $name = 'sub_' . bin2hex(random_bytes(6)) . '.sealed';
        file_put_contents($dir . $name, str_repeat("\x01", 2048 * ($i + 1)));
        touch($dir . $name, time() + 3600 + $age);   // newer than anything a real folder holds
        $names[] = $name;
    }
    $junk = 'not-a-submission-' . getmypid() . '.txt';
    file_put_contents($dir . $junk, 'x');
    register_shutdown_function(function () use ($dir, $names, $junk) {
        foreach (array_merge($names, array($junk)) as $n) {
            if (is_file($dir . $n)) unlink($dir . $n);
        }
    });
    return array('old' => $names[0], 'new' => $names[1], 'junk' => $junk);
})();

function kop_rinbox_test_anonymous_docs(array $src, array $item, callable $check) {
    $t = $GLOBALS['kop_rinbox_test_anon'];
    // Another run of this test may share the uploads folder, so look for this run's files by name.
    $all = call_user_func($src['list'], array('view' => 'new', 'search' => '', 'offset' => 0, 'limit' => 100));
    $keys = array_column($all['items'], 'key');
    $check('anonymous-docs: newest first', $keys !== array() && array_search($t['new'], $keys, true) < array_search($t['old'], $keys, true));
    $check('anonymous-docs: only sealed submissions are listed', in_array($t['old'], $keys, true) && !in_array($t['junk'], $keys, true));
    $item = kop_rinbox_get_item('anonymous-docs', $t['new']);
    $url = $item['links'][0]['url'];
    $check('anonymous-docs: the download is the old screen\'s admin-post link with its nonce',
        strpos($url, 'admin-post.php?action=kop_anon_download&file=' . rawurlencode($t['new'])) !== false && strpos($url, '_wpnonce=') !== false && strpos($url, '&amp;') === false, $url);
    $check('anonymous-docs: no fields to edit', !$item['fields']);

    $in = function ($view) use ($src, $t) {
        return in_array($t['new'], array_column(call_user_func($src['list'], array('view' => $view, 'search' => $t['new'], 'offset' => 0, 'limit' => 5))['items'], 'key'), true);
    };
    $res = call_user_func($src['act'], $item['key'], 'handled', array());
    $check('anonymous-docs: mark handled moves it to the Handled tab', $in('handled') && !$in('new'), $res['message']);
    $again = kop_rinbox_get_item('anonymous-docs', $item['key']);
    $check('anonymous-docs: a handled file offers Mark not handled', $again['status'] === 'handled' && array_column($again['actions'], 'id') === array('unhandled'));
    call_user_func($src['act'], $item['key'], 'unhandled', array());
    $check('anonymous-docs: mark not handled puts it back', $in('new') && !$in('handled'));
    try {
        call_user_func($src['act'], '../wp-config.php', 'handled', array());
        $check('anonymous-docs: a name that is not a submission is refused', false);
    } catch (RuntimeException $e) {
        $check('anonymous-docs: a name that is not a submission is refused', true, $e->getMessage());
    }

    // The old screen's status line and its "encrypt them now" button.
    $counts = call_user_func($src['view_counts'], array());
    $check('anonymous-docs: every tab has a count', array_keys($counts) === array('new', 'handled') && $counts['new'] >= 2, json_encode($counts));
    $portal = new AnonymousDocPortal();
    $status = $portal->status();
    $check('anonymous-docs: the portal reports its key, scanning and older unlocked files', array_keys($status) === array('key', 'scanning', 'plaintext') && is_int($status['plaintext']), json_encode($status));
    $GLOBALS['kop_anon_doc_portal'] = $portal;
    $spec = kop_rinbox_sources(true)['anonymous-docs'];
    $check('anonymous-docs: the help says which key locks the files, or that none is set up',
        strpos($spec['help'], $status['key'] !== '' ? 'Locked with key ' . $status['key'] : 'No public key') !== false);
    $check('anonymous-docs: the lock tool is offered only when older unlocked files wait', (bool) $spec['tools'] === ($status['plaintext'] > 0 && $status['key'] !== ''));
    unset($GLOBALS['kop_anon_doc_portal']);
}
