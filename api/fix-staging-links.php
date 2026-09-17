<?php
/**
 * Fix staging links: admin tool that rewrites links to the old staging copy
 * of the site (https://kidsoverprofits.org/staging/<path>) back to the live
 * site in published post and page content.
 *
 * The staging copy still answers but returns intermittent 500s, and the
 * September 2026 audit found 45 published posts and pages pointing at it
 * (30 distinct URLs, mostly page links).
 *
 * A URL is rewritten only when the live equivalent exists: the path resolves
 * to a post or page via url_to_postid(), or it sits under wp-content/uploads/.
 * Everything else is listed as skipped so it can be fixed by hand.
 *
 * GET shows a preview (nothing is written). Ticked rows are applied via
 * POST. post_content changes for posts, pages, attachments, the block
 * navigation menu, Pagelayer templates and download records (revisions are
 * left alone). Postmeta rows are rewritten through get/update_post_meta so
 * serialized values stay intact.
 *
 * Admin-only. Loads WordPress via config.php.
 */

require_once __DIR__ . '/config.php';

if (!function_exists('current_user_can') || !current_user_can('manage_options')) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Not authorized. Log in to WordPress as an administrator first.';
    exit;
}

header('Content-Type: text/html; charset=utf-8');
header('X-Content-Type-Options: nosniff');

$STAGING_NEEDLE = 'kidsoverprofits.org/staging/';
$URL_PATTERN = '~https?://(?:www\.)?kidsoverprofits\.org/staging/([^"\'\s<>)]*)~i';

/**
 * Where a staging URL should point on the live site, or null when the live
 * target cannot be confirmed.
 */
function kop_fsl_live_target($rest) {
    $rest = ltrim((string) $rest, '/');
    // The staging copy lived one folder deeper for a while
    // (/staging/kids-over-profits/<path>); drop that segment.
    if (strpos($rest, 'kids-over-profits/') === 0) {
        $rest = substr($rest, strlen('kids-over-profits/'));
    } elseif ($rest === 'kids-over-profits') {
        $rest = '';
    }
    $path = (string) wp_parse_url('https://kidsoverprofits.org/' . $rest, PHP_URL_PATH);
    $path = ltrim($path, '/');

    if ($path === '') {
        return home_url('/');
    }
    if (strpos($path, 'wp-content/uploads/') === 0) {
        return home_url('/' . $rest);
    }
    // Theme assets: only when the file still exists on disk.
    $theme_prefix = 'wp-content/themes/child/';
    if (strpos($path, $theme_prefix) === 0) {
        $file = get_stylesheet_directory() . '/' . substr($path, strlen($theme_prefix));
        return file_exists($file) ? home_url('/' . $rest) : null;
    }
    $candidate = home_url('/' . $rest);
    $post_id = url_to_postid($candidate);
    if ($post_id > 0 && get_post_status($post_id) === 'publish') {
        return $candidate;
    }
    return null;
}

/**
 * All staging URLs in a piece of content with their live targets.
 * Returns [url => target|null], one entry per distinct URL.
 */
function kop_fsl_plan($content) {
    global $URL_PATTERN;
    $plan = array();
    if (!preg_match_all($URL_PATTERN, (string) $content, $m, PREG_SET_ORDER)) {
        return $plan;
    }
    foreach ($m as $match) {
        $url = $match[0];
        if (isset($plan[$url])) {
            continue;
        }
        $plan[$url] = kop_fsl_live_target($match[1]);
    }
    return $plan;
}

/** Apply the resolvable rewrites to the content string. */
function kop_fsl_rewrite($content, array $plan) {
    foreach ($plan as $url => $target) {
        if ($target === null) {
            continue;
        }
        $content = str_replace($url, $target, $content);
    }
    return $content;
}

/** Collect every string inside a (possibly nested) meta value. */
function kop_fsl_flatten($value, array &$out) {
    if (is_string($value)) {
        $out[] = $value;
    } elseif (is_array($value)) {
        foreach ($value as $v) {
            kop_fsl_flatten($v, $out);
        }
    } elseif (is_object($value)) {
        foreach (get_object_vars($value) as $v) {
            kop_fsl_flatten($v, $out);
        }
    }
}

/** Rewrite every string inside a (possibly nested) meta value. */
function kop_fsl_rewrite_deep($value, array $plan) {
    if (is_string($value)) {
        return kop_fsl_rewrite($value, $plan);
    }
    if (is_array($value)) {
        foreach ($value as $k => $v) {
            $value[$k] = kop_fsl_rewrite_deep($v, $plan);
        }
        return $value;
    }
    if (is_object($value)) {
        foreach (get_object_vars($value) as $k => $v) {
            $value->$k = kop_fsl_rewrite_deep($v, $plan);
        }
        return $value;
    }
    return $value;
}

/** Plan for one postmeta key: all values, all nested strings. */
function kop_fsl_meta_plan($post_id, $meta_key) {
    $strings = array();
    foreach ((array) get_post_meta($post_id, $meta_key) as $value) {
        kop_fsl_flatten($value, $strings);
    }
    return kop_fsl_plan(implode(chr(10), $strings));
}

/** Post types whose content this tool may edit (revisions are history). */
function kop_fsl_post_types() {
    return array('post', 'page', 'attachment', 'wp_navigation', 'pagelayer-template', 'dlm_download');
}

// ---------------------------------------------------------------------------
// Apply
// ---------------------------------------------------------------------------
$applied = false;
$apply_log = array();

if ($_SERVER['REQUEST_METHOD'] === 'POST'
    && isset($_POST['do_apply'])
    && check_admin_referer('kop_fsl_apply')) {

    $requests = isset($_POST['row']) && is_array($_POST['row']) ? $_POST['row'] : array();
    $done = 0;
    $skipped = 0;
    $links = 0;
    foreach ($requests as $post_id => $req) {
        if (empty($req['go'])) {
            continue;
        }
        $post_id = (int) $post_id;
        $post = $post_id > 0 ? get_post($post_id) : null;
        if (!$post || !in_array($post->post_type, kop_fsl_post_types(), true) || $post->post_status === 'trash') {
            $skipped++;
            continue;
        }
        // Recompute from the live content so a stale form can't clobber edits
        // made since the preview rendered.
        $plan = kop_fsl_plan($post->post_content);
        $new_content = kop_fsl_rewrite($post->post_content, $plan);
        if ($new_content === $post->post_content) {
            $skipped++;
            continue;
        }
        $result = wp_update_post(array(
            'ID' => $post_id,
            'post_content' => wp_slash($new_content),
        ), true);
        if (is_wp_error($result)) {
            $skipped++;
            continue;
        }
        $done++;
        foreach ($plan as $target) {
            if ($target !== null) {
                $links++;
            }
        }
    }
    // Postmeta rows: WordPress handles (un)serialization in get/update_post_meta.
    $meta_requests = isset($_POST['meta']) && is_array($_POST['meta']) ? $_POST['meta'] : array();
    $meta_done = 0;
    $meta_skipped = 0;
    foreach ($meta_requests as $key => $req) {
        if (empty($req['go'])) {
            continue;
        }
        $parts = explode('|', (string) $key, 2);
        $mpost = (int) $parts[0];
        $mkey = isset($parts[1]) ? sanitize_text_field(wp_unslash($parts[1])) : '';
        if ($mpost <= 0 || $mkey === '' || !get_post($mpost)) {
            $meta_skipped++;
            continue;
        }
        $plan = kop_fsl_meta_plan($mpost, $mkey);
        $changed = false;
        foreach ((array) get_post_meta($mpost, $mkey) as $old_value) {
            $new_value = kop_fsl_rewrite_deep($old_value, $plan);
            if ($new_value === $old_value) {
                continue;
            }
            if (update_post_meta($mpost, $mkey, $new_value, $old_value) !== false) {
                $changed = true;
            }
        }
        if ($changed) {
            $meta_done++;
        } else {
            $meta_skipped++;
        }
    }

    if ($done > 0 || $meta_done > 0) {
        do_action('litespeed_purge_all');
    }
    $applied = true;
    $apply_log[] = "Updated {$done} post(s), {$links} distinct link(s); {$meta_done} postmeta row(s)."
        . (($skipped || $meta_skipped) ? " Skipped {$skipped} post(s) and {$meta_skipped} meta row(s) (already fixed, target missing, edited since preview, or update failed)." : '')
        . (($done || $meta_done) ? ' LiteSpeed cache purged.' : '');
}

// ---------------------------------------------------------------------------
// Preview
// ---------------------------------------------------------------------------
global $wpdb;

$like = '%' . $wpdb->esc_like($STAGING_NEEDLE) . '%';
$types_sql = implode(',', array_map(static function ($t) use ($wpdb) { return $wpdb->prepare('%s', $t); }, kop_fsl_post_types()));
$rows = $wpdb->get_results($wpdb->prepare(
    "SELECT ID, post_title, post_type, post_status, post_content
       FROM {$wpdb->posts}
      WHERE post_type IN ({$types_sql})
        AND post_status <> 'trash'
        AND post_content LIKE %s
      ORDER BY post_type, post_title",
    $like
));

$preview = array();
$total_urls = 0;
$total_ok = 0;
foreach ($rows as $r) {
    $plan = kop_fsl_plan($r->post_content);
    if (!$plan) {
        continue;
    }
    $ok = 0;
    foreach ($plan as $target) {
        if ($target !== null) {
            $ok++;
        }
    }
    $total_urls += count($plan);
    $total_ok += $ok;
    $preview[] = array(
        'id' => (int) $r->ID,
        'title' => $r->post_title,
        'type' => $r->post_type,
        'status' => $r->post_status,
        'plan' => $plan,
        'ok' => $ok,
    );
}

$meta_rows = $wpdb->get_results($wpdb->prepare(
    "SELECT DISTINCT post_id, meta_key
       FROM {$wpdb->postmeta}
      WHERE meta_value LIKE %s
      ORDER BY post_id
      LIMIT 200",
    $like
));
$meta_preview = array();
foreach ($meta_rows as $m) {
    $plan = kop_fsl_meta_plan((int) $m->post_id, $m->meta_key);
    $ok = 0;
    foreach ($plan as $target) {
        if ($target !== null) {
            $ok++;
        }
    }
    $meta_preview[] = array(
        'post_id' => (int) $m->post_id,
        'key' => $m->meta_key,
        'plan' => $plan,
        'ok' => $ok,
    );
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="robots" content="noindex, nofollow">
<title>Fix staging links</title>
<style>
    body { font: 14px/1.45 system-ui, sans-serif; margin: 24px; color: #000435; background: #F2EEDF; }
    h1 { margin: 0 0 6px; }
    .note { background: #FFF5CB; border: 1px solid #EF9034; padding: 10px 12px; border-radius: 6px; margin: 12px 0; }
    .log { background: #B6E3D4; border: 1px solid #33A7B5; padding: 10px 12px; border-radius: 6px; margin: 12px 0; }
    table { border-collapse: collapse; width: 100%; background: #fff; }
    th, td { border: 1px solid #ccc; padding: 6px 8px; text-align: left; vertical-align: top; }
    th { background: #000080; color: #fff; }
    tr.skip td { color: #777; }
    .url { font-family: monospace; font-size: 12px; word-break: break-all; }
    .bad { color: #a33; }
    .good { color: #1a6b2f; }
    button { background: #000080; color: #fff; border: 0; padding: 8px 16px; border-radius: 6px; cursor: pointer; font-size: 14px; }
    button:disabled { background: #999; cursor: default; }
</style>
</head>
<body>
<h1>Fix staging links</h1>
<p>Rewrites <code>kidsoverprofits.org/staging/&lt;path&gt;</code> links in post and page content to the live site. Links whose live target cannot be confirmed are listed but left alone.</p>

<?php foreach ($apply_log as $line): ?>
    <div class="log"><?php echo esc_html($line); ?></div>
<?php endforeach; ?>

<form method="post" action="<?php echo esc_url($_SERVER['REQUEST_URI']); ?>">
<?php wp_nonce_field('kop_fsl_apply'); ?>
<?php if (!$preview): ?>
    <div class="note">No post content contains staging links (revisions are ignored).</div>
<?php else: ?>
    <div class="note">
        <?php echo count($preview); ?> post(s) with staging links;
        <?php echo (int) $total_ok; ?> of <?php echo (int) $total_urls; ?> distinct link(s) can be rewritten.
    </div>
    <table>
        <thead>
            <tr><th></th><th>Post</th><th>Links</th></tr>
        </thead>
        <tbody>
        <?php foreach ($preview as $p): ?>
            <tr class="<?php echo $p['ok'] ? '' : 'skip'; ?>">
                <td>
                    <?php if ($p['ok']): ?>
                        <input type="checkbox" name="row[<?php echo (int) $p['id']; ?>][go]" value="1" checked>
                    <?php endif; ?>
                </td>
                <td>
                    <a href="<?php echo esc_url(get_edit_post_link($p['id'], 'raw')); ?>" target="_blank"><?php echo esc_html($p['title'] !== '' ? $p['title'] : '(no title)'); ?></a><br>
                    <small><?php echo esc_html($p['type'] . ' / ' . $p['status'] . ' / #' . $p['id']); ?></small>
                </td>
                <td>
                    <?php foreach ($p['plan'] as $url => $target): ?>
                        <div class="url">
                            <?php echo esc_html($url); ?>
                            <?php if ($target !== null): ?>
                                <span class="good">-&gt; <?php echo esc_html($target); ?></span>
                            <?php else: ?>
                                <span class="bad">(skipped: no published page, upload, or theme file at that path)</span>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
<?php endif; ?>

<h2>Postmeta rows containing the staging host</h2>
<?php if (!$meta_preview): ?>
    <p>None.</p>
<?php else: ?>
    <p>Serialized values go through get_post_meta / update_post_meta, so nested strings are rewritten safely.</p>
    <table>
        <thead><tr><th></th><th>Post</th><th>Meta key</th><th>Links</th></tr></thead>
        <tbody>
        <?php foreach ($meta_preview as $mp): ?>
            <tr class="<?php echo $mp['ok'] ? '' : 'skip'; ?>">
                <td>
                    <?php if ($mp['ok']): ?>
                        <input type="checkbox" name="meta[<?php echo esc_attr($mp['post_id'] . '|' . $mp['key']); ?>][go]" value="1" checked>
                    <?php endif; ?>
                </td>
                <td>
                    <a href="<?php echo esc_url(get_edit_post_link($mp['post_id'], 'raw')); ?>" target="_blank">#<?php echo (int) $mp['post_id']; ?> <?php echo esc_html(get_the_title($mp['post_id'])); ?></a><br>
                    <small><?php echo esc_html((string) get_post_type($mp['post_id'])); ?></small>
                </td>
                <td class="url"><?php echo esc_html($mp['key']); ?></td>
                <td>
                    <?php foreach ($mp['plan'] as $url => $target): ?>
                        <div class="url">
                            <?php echo esc_html($url); ?>
                            <?php if ($target !== null): ?>
                                <span class="good">-&gt; <?php echo esc_html($target); ?></span>
                            <?php else: ?>
                                <span class="bad">(skipped: no published page, upload, or theme file at that path)</span>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
<?php endif; ?>

<?php if ($preview || $meta_preview): ?>
    <p>
        <button type="submit" name="do_apply" value="1">Apply ticked rows</button>
    </p>
<?php endif; ?>
</form>
</body>
</html>
