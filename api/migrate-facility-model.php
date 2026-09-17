<?php
/**
 * Phase 3 of the facility data model migration (docs/DATA-MODEL-MIGRATION.md).
 *
 * Admin only. Builds the v2 model from the legacy tables with the same code
 * as the offline rehearsal (inc/facility-migration.php) and writes it to NEW
 * tables only: facilities_v2, {prefix}kop_facility_locations, kop_operators,
 * kop_operator_facilities, kop_operator_links, kop_facility_identity,
 * kop_migration_state. facilities_master, locations_master and every link
 * table are read, never written.
 *
 *   ?action=status                       what exists, last sync, whether legacy data changed since
 *   ?action=dry_run                      plan from live data, summary as JSON (writes nothing)
 *   ?action=dry_run&report=review        one report as TSV: review | splits | conflicts |
 *                                        memberships | operator_links | link_repoints
 *   ?action=run                          page with a button that applies the plan in batches
 *   ?action=cutover                      page that switches public areas to the v2 tables one at a time
 *   ?action=writes                       page that sends admin saves to the v2 tables (phase 4 step 5)
 *   POST action=apply&stage=&offset=&batch=&_wpnonce=   one batch (used by the run page)
 *
 * Re-running is safe: unchanged rows are skipped, memberships are replaced per
 * facility, and ids for split/new facilities are kept in kop_facility_identity.
 * After the first apply, inc/facility-v2-sync.php keeps v2 in step with legacy
 * saves every 10 minutes.
 */

@set_time_limit(300);
error_reporting(E_ALL);
ini_set('display_errors', 0);

require_once __DIR__ . '/config.php';

if (!function_exists('current_user_can')) {
    $kop_wp = __DIR__;
    for ($i = 0; $i < 6; $i++) {
        $kop_wp = dirname($kop_wp);
        if (file_exists($kop_wp . '/wp-load.php')) {
            require_once $kop_wp . '/wp-load.php';
            break;
        }
    }
}
if (!function_exists('current_user_can') || !current_user_can('manage_options')) {
    http_response_code(403);
    header('Content-Type: application/json');
    echo json_encode(array('success' => false, 'error' => 'Admin access required.'));
    exit;
}
if (!isset($pdo) || !($pdo instanceof PDO)) {
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode(array('success' => false, 'error' => 'No database connection (api/config.php).'));
    exit;
}
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

require_once dirname(__DIR__) . '/inc/facility-migration.php';
require_once dirname(__DIR__) . '/inc/facility-v2-writer.php';

global $wpdb;
$prefix = isset($wpdb->prefix) ? $wpdb->prefix : ((isset($table_prefix) && is_string($table_prefix)) ? $table_prefix : 'wpdl_');
$action = isset($_REQUEST['action']) ? preg_replace('/[^a-z_]/', '', (string)$_REQUEST['action']) : 'status';
$nonce_action = 'kop_migrate_facility_model';

function kop_mfm_json($data, $code = 200) {
    http_response_code($code);
    header('Content-Type: application/json');
    echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

function kop_mfm_tsv(array $header, array $rows) {
    header('Content-Type: text/plain; charset=utf-8');
    echo implode("\t", $header) . "\n";
    foreach ($rows as $row) {
        $cells = array();
        foreach ($header as $column) {
            $v = $row[$column] ?? '';
            if (is_array($v)) $v = json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $cells[] = str_replace(array("\t", "\r", "\n"), ' ', (string)$v);
        }
        echo implode("\t", $cells) . "\n";
    }
    exit;
}

function kop_mfm_summary(array $plan) {
    $review = array();
    foreach ($plan['review'] as $r) $review[$r['reason']] = ($review[$r['reason']] ?? 0) + 1;
    ksort($review);
    $validation_errors = $review['validation_error'] ?? 0;
    return array(
        'facilities'          => count($plan['docs']),
        'by_origin'           => array_count_values($plan['origins']),
        'identity_splits'     => count($plan['splits']),
        'memberships'         => count($plan['memberships']),
        'unknown_location'    => count(array_filter($plan['memberships'], function ($m) { return $m['location_key'] === 'UNKNOWN'; })),
        'conflicts'           => count($plan['conflicts']),
        'operators'           => count($plan['operators']),
        'operator_facilities' => count($plan['operator_facilities']),
        'operator_links'      => count($plan['operator_links']),
        'link_repoints'       => count($plan['link_repoints']),
        'validation_errors'   => $validation_errors,
        'review'              => $review,
        'stats'               => $plan['stats'],
        'json_errors'         => $plan['json_errors'],
    );
}

try {
    switch ($action) {
        case 'status': {
            $exists = kop_migration_tables_exist($pdo, $prefix);
            $out = array('success' => true, 'tables_exist' => $exists);
            if ($exists) {
                $t = kop_migration_tables($prefix);
                $last = kop_migration_state_get($pdo, $prefix, 'last_sync');
                $out['applied'] = (bool)kop_migration_state_get($pdo, $prefix, 'applied', false);
                $out['last_sync'] = $last;
                $out['legacy_changed_since_sync'] = !is_array($last) || ($last['fingerprint'] ?? '') !== kop_migration_fingerprint($pdo);
                $out['writes'] = kop_migration_state_get($pdo, $prefix, 'writes');
                $out['counts'] = array(
                    'facilities_v2'   => (int)$pdo->query("SELECT COUNT(*) FROM `{$t['facilities']}`")->fetchColumn(),
                    'memberships'     => (int)$pdo->query("SELECT COUNT(*) FROM `{$t['facility_locations']}`")->fetchColumn(),
                    'needs_review'    => (int)$pdo->query("SELECT COUNT(*) FROM `{$t['facility_locations']}` WHERE needs_review = 1")->fetchColumn(),
                    'operators'       => (int)$pdo->query("SELECT COUNT(*) FROM `{$t['operators']}`")->fetchColumn(),
                    'operator_links'  => (int)$pdo->query("SELECT COUNT(*) FROM `{$t['operator_links']}`")->fetchColumn(),
                    'identity'        => (int)$pdo->query("SELECT COUNT(*) FROM `{$t['identity']}`")->fetchColumn(),
                );
            }
            kop_mfm_json($out);
        }

        case 'dry_run': {
            $plan = kop_migration_plan_from_db($pdo, $prefix, false);
            $report = isset($_GET['report']) ? (string)$_GET['report'] : '';
            switch ($report) {
                case 'review':
                    kop_mfm_tsv(array('facility_id', 'reason', 'detail'), $plan['review']);
                case 'conflicts':
                    kop_mfm_tsv(array('facility_id', 'field', 'value_kept', 'value_dropped', 'sources'), $plan['conflicts']);
                case 'memberships':
                    kop_mfm_tsv(array('facility_id', 'location_key', 'role', 'source', 'needs_review', 'review_reason'), $plan['memberships']);
                case 'operator_links':
                    kop_mfm_tsv(array('link_kind', 'link_id', 'operator_id', 'operator_name', 'link_type', 'title'), $plan['operator_links']);
                case 'link_repoints':
                    kop_mfm_tsv(array('link_kind', 'link_id', 'from_facility_id', 'to_facility_id', 'reason'), $plan['link_repoints']);
                case 'splits':
                    $rows = array();
                    foreach ($plan['splits'] as $old => $ids) {
                        $parts = array();
                        foreach ($ids as $id) {
                            $d = $plan['docs'][$id];
                            $parts[] = $id . ': ' . $d['identification']['name'] . ' (' . trim($d['location']['city'] . ', ' . ($d['location']['state'] ?? $d['location']['country'] ?? '?'), ', ') . ')';
                        }
                        $rows[] = array('old_id' => $old, 'facilities' => implode(' | ', $parts), 'links' => implode(' ; ', $plan['split_link_notes'][$old] ?? array()));
                    }
                    kop_mfm_tsv(array('old_id', 'facilities', 'links'), $rows);
            }
            kop_mfm_json(array(
                'success' => true,
                'dry_run' => true,
                'note'    => 'Nothing was written. Ids for split and new facilities are provisional until the first apply.',
                'plan'    => kop_mfm_summary($plan),
            ));
        }

        case 'apply': {
            if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !wp_verify_nonce($_POST['_wpnonce'] ?? '', $nonce_action)) {
                kop_mfm_json(array('success' => false, 'error' => 'POST with a valid nonce required (use ?action=run).'), 400);
            }
            if (kop_v2_writes_active($pdo, $prefix, true)) {
                kop_mfm_json(array('success' => false, 'error' => 'Admin saves write the v2 tables now; re-applying from the frozen legacy tables would undo them. Switch writes back first (?action=writes).'), 409);
            }
            $stage = ($_POST['stage'] ?? 'facilities') === 'finish' ? 'finish' : 'facilities';
            $offset = max(0, (int)($_POST['offset'] ?? 0));
            $batch = min(2000, max(50, (int)($_POST['batch'] ?? 500)));

            $locked = (int)$pdo->query("SELECT GET_LOCK('kop_facility_v2_sync', 10)")->fetchColumn();
            if ($locked !== 1) {
                kop_mfm_json(array('success' => false, 'error' => 'A sync is running; try again in a minute.'), 409);
            }
            try {
                kop_migration_create_tables($pdo, $prefix);
                $plan = kop_migration_plan_from_db($pdo, $prefix, true);
                $result = kop_migration_apply_batch($pdo, $prefix, $plan, $stage, $offset, $batch);
            } finally {
                $pdo->query("SELECT RELEASE_LOCK('kop_facility_v2_sync')");
            }
            if (!empty($result['done'])) {
                $result['summary'] = kop_mfm_summary($plan);
            }
            kop_mfm_json(array('success' => true) + $result);
        }

        case 'set_area': {
            if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !wp_verify_nonce($_POST['_wpnonce'] ?? '', $nonce_action)) {
                kop_mfm_json(array('success' => false, 'error' => 'POST with a valid nonce required (use ?action=cutover).'), 400);
            }
            $areas = kop_v2_areas();
            $area = (string)($_POST['area'] ?? '');
            $to = (string)($_POST['to'] ?? '');
            if (!isset($areas[$area]) || !in_array($to, array('v1', 'v2'), true)) {
                kop_mfm_json(array('success' => false, 'error' => 'Unknown area or target.'), 400);
            }
            if ($to === 'v2' && !kop_migration_state_get($pdo, $prefix, 'applied', false)) {
                kop_mfm_json(array('success' => false, 'error' => 'Apply the migration first (?action=run).'), 409);
            }
            $on = array_values(array_intersect(array_keys($areas), (array)get_option('kop_data_model_areas', array())));
            $on = $to === 'v2' ? array_values(array_unique(array_merge($on, array($area)))) : array_values(array_diff($on, array($area)));
            update_option('kop_data_model_areas', $on, true);
            // Cached copies of the old output would hide the switch.
            delete_transient('kop_home_numbers');
            do_action('litespeed_purge_all');
            header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?') . '?action=cutover&switched=' . rawurlencode($area) . '&to=' . $to, true, 303);
            exit;
        }

        case 'cutover': {
            $nonce = wp_create_nonce($nonce_action);
            $self = strtok($_SERVER['REQUEST_URI'], '?');
            $on = (array)get_option('kop_data_model_areas', array());
            $all_on = get_option('kop_data_model') === 'v2';
            $applied = kop_migration_tables_exist($pdo, $prefix) && kop_migration_state_get($pdo, $prefix, 'applied', false);

            $page_url = function ($template, $fallback) {
                $pages = get_posts(array('post_type' => 'page', 'post_status' => 'publish', 'meta_key' => '_wp_page_template', 'meta_value' => $template, 'numberposts' => 1));
                return $pages ? get_permalink($pages[0]) : home_url($fallback);
            };
            $utah = get_page_by_path('utah');
            $profiles = get_posts(array('post_type' => 'any', 'post_status' => 'publish', 'meta_key' => '_wp_page_template', 'meta_value' => 'templates/single-facility-profile.php', 'numberposts' => 1));
            $previews = array(
                'location_pages'    => array(
                    'Utah page' => $utah ? add_query_arg('model', 'v2', get_permalink($utah)) : '',
                    'Utah data' => rest_url('kop/v1/state/utah') . '?model=v2',
                ),
                'program_index'     => array('Program index' => add_query_arg('model', 'v2', $page_url('templates/page-tti-program-index.php', '/tti-program-index/'))),
                'facility_profiles' => $profiles ? array(get_the_title($profiles[0]) => add_query_arg('model', 'v2', get_permalink($profiles[0]))) : array(),
                'search'            => array('Search "academy"' => add_query_arg(array('s' => 'academy', 'model' => 'v2'), home_url('/'))),
                'homepage_stats'    => array('Homepage' => add_query_arg('model', 'v2', home_url('/'))),
            );
            header('Content-Type: text/html; charset=utf-8');
            ?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Facility model: switch pages</title>
<style>
  body { font: 15px/1.5 system-ui, sans-serif; max-width: 900px; margin: 2rem auto; padding: 0 1rem; color: #000435; }
  table { width: 100%; border-collapse: collapse; margin: 1rem 0; }
  th, td { text-align: left; padding: .6rem .5rem; border-bottom: 1px solid #F2EEDF; vertical-align: top; }
  .state { font-weight: 600; }
  .new { color: #1d7a4f; }
  .old { color: #6b6b6b; }
  button { font: inherit; padding: .35rem .8rem; border: 0; border-radius: 4px; cursor: pointer; color: #fff; background: #000080; }
  button.back { background: #6b6b6b; }
  .note { background: #F2EEDF; padding: .75rem 1rem; border-radius: 6px; }
  a { color: #000080; }
</style>
</head>
<body>
<h1>Facility model: switch pages to the new tables</h1>
<?php if (!$applied): ?>
  <p class="note">The migration has not been applied yet. Run it first: <a href="<?php echo esc_url($self . '?action=run'); ?>">Apply</a>.</p>
<?php endif; ?>
<?php if (!empty($_GET['switched'])): ?>
  <p class="note">Switched <strong><?php echo esc_html(kop_v2_areas()[$_GET['switched']] ?? $_GET['switched']); ?></strong> to <?php echo $_GET['to'] === 'v2' ? 'the new tables' : 'the old tables'; ?>. The page cache was cleared.</p>
<?php endif; ?>
<p>Switch one area at a time. Open its preview first: a preview shows exactly what visitors will see after switching. Switching back is instant.</p>
<?php if ($all_on): ?>
  <p class="note">The <code>kop_data_model</code> option is set to <code>v2</code>, so every area reads the new tables regardless of the switches below.</p>
<?php endif; ?>
<table>
  <tr><th>Area</th><th>Now reading</th><th>Preview</th><th></th></tr>
  <?php foreach (kop_v2_areas() as $key => $label): $is_on = $all_on || in_array($key, $on, true); ?>
  <tr>
    <td><?php echo esc_html($label); ?></td>
    <td class="state <?php echo $is_on ? 'new' : 'old'; ?>"><?php echo $is_on ? 'New tables' : 'Old tables'; ?></td>
    <td>
      <?php foreach ($previews[$key] as $text => $url): if (!$url) continue; ?>
        <a href="<?php echo esc_url($url); ?>" target="_blank" rel="noopener"><?php echo esc_html($text); ?></a><br>
      <?php endforeach; ?>
    </td>
    <td>
      <form method="post" action="<?php echo esc_url($self); ?>">
        <input type="hidden" name="action" value="set_area">
        <input type="hidden" name="area" value="<?php echo esc_attr($key); ?>">
        <input type="hidden" name="to" value="<?php echo $is_on ? 'v1' : 'v2'; ?>">
        <input type="hidden" name="_wpnonce" value="<?php echo esc_attr($nonce); ?>">
        <button type="submit" class="<?php echo $is_on ? 'back' : ''; ?>" <?php disabled(!$applied && !$is_on); ?>><?php echo $is_on ? 'Switch back' : 'Switch to new'; ?></button>
      </form>
    </td>
  </tr>
  <?php endforeach; ?>
</table>
<p><?php if (kop_v2_writes_active($pdo, $prefix)): ?>The admin data form saves to the new tables.<?php else: ?>The admin data form keeps editing the old tables. Edits reach the new tables within about a minute.<?php endif; ?>
<a href="<?php echo esc_url($self . '?action=writes'); ?>">Admin saves</a> &middot;
<a href="<?php echo esc_url($self . '?action=status'); ?>">Status</a></p>
</body>
</html>
            <?php
            exit;
        }

        case 'set_writes': {
            if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !wp_verify_nonce($_POST['_wpnonce'] ?? '', $nonce_action)) {
                kop_mfm_json(array('success' => false, 'error' => 'POST with a valid nonce required (use ?action=writes).'), 400);
            }
            $to = (string)($_POST['to'] ?? '');
            if (!in_array($to, array('v2', 'legacy'), true)) {
                kop_mfm_json(array('success' => false, 'error' => 'Unknown target.'), 400);
            }
            if ($to === 'v2' && kop_v2_write_switch_blockers()) {
                kop_mfm_json(array('success' => false, 'error' => 'Not ready: some code still uses the old tables.', 'blockers' => kop_v2_write_switch_blockers()), 409);
            }
            if ($to === 'legacy' && ($_POST['confirm'] ?? '') !== 'discard') {
                kop_mfm_json(array('success' => false, 'error' => 'Switching back needs confirm=discard.'), 400);
            }
            kop_v2_set_writes($pdo, $prefix, $to);
            do_action('litespeed_purge_all');
            header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?') . '?action=writes&switched=' . $to, true, 303);
            exit;
        }

        case 'writes': {
            $nonce = wp_create_nonce($nonce_action);
            $self = strtok($_SERVER['REQUEST_URI'], '?');
            $applied = kop_migration_tables_exist($pdo, $prefix) && kop_migration_state_get($pdo, $prefix, 'applied', false);
            $state = $applied ? kop_migration_state_get($pdo, $prefix, 'writes') : null;
            $on = is_array($state) && ($state['mode'] ?? '') === 'v2';
            $blockers = kop_v2_write_switch_blockers();
            $areas_on = get_option('kop_data_model') === 'v2' ? array_keys(kop_v2_areas()) : (array)get_option('kop_data_model_areas', array());
            $areas_off = array_diff(array_keys(kop_v2_areas()), $areas_on);
            $edits = $on ? kop_v2_edits_since_switch($pdo, $prefix) : 0;
            $legacy_changed = $on && ($state['frozen_fingerprint'] ?? '') !== kop_v2_frozen_fingerprint($pdo);
            header('Content-Type: text/html; charset=utf-8');
            ?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Facility model: admin saves</title>
<style>
  body { font: 15px/1.5 system-ui, sans-serif; max-width: 820px; margin: 2rem auto; padding: 0 1rem; color: #000435; }
  button { font: inherit; padding: .45rem .9rem; border: 0; border-radius: 4px; cursor: pointer; color: #fff; background: #000080; }
  button.back { background: #6b6b6b; }
  button[disabled] { opacity: .5; cursor: default; }
  .note { background: #F2EEDF; padding: .75rem 1rem; border-radius: 6px; }
  .state { font-weight: 600; }
  li { margin: .25rem 0; }
  a { color: #000080; }
</style>
</head>
<body>
<h1>Facility model: where admin saves go</h1>
<?php if (!empty($_GET['switched'])): ?>
  <p class="note">Admin saves now go to <?php echo $_GET['switched'] === 'v2' ? 'the new tables' : 'the old tables'; ?>.</p>
<?php endif; ?>
<?php if (!$applied): ?>
  <p class="note">The migration has not been applied yet. <a href="<?php echo esc_url($self . '?action=run'); ?>">Apply</a> it first.</p>
<?php endif; ?>
<p>Now: <span class="state"><?php echo $on ? 'the new tables (facilities_v2 and its link tables)' : 'the old tables (facilities_master, locations_master); the new tables are re-derived every 10 minutes'; ?></span>.</p>

<?php if (!$on): ?>
  <p>Switching sends the admin data form, suggestion approvals and the other facility editors to the new tables. At the switch the new tables are brought up to date one last time, the news and lawsuit links recorded on split facilities move to the facility they are about, and the old facility tables stop changing. They stay as a backup until phase 5.</p>
  <?php if ($areas_off): ?>
    <p class="note">Switch every public area first (<a href="<?php echo esc_url($self . '?action=cutover'); ?>">pages</a>). Still on the old tables: <?php echo esc_html(implode(', ', $areas_off)); ?>.</p>
  <?php endif; ?>
  <?php if ($blockers): ?>
    <p class="note">Not ready yet. This code still uses the old tables and has to be moved first:</p>
    <ul><?php foreach ($blockers as $b): ?><li><?php echo esc_html($b); ?></li><?php endforeach; ?></ul>
  <?php endif; ?>
  <form method="post" action="<?php echo esc_url($self); ?>">
    <input type="hidden" name="action" value="set_writes">
    <input type="hidden" name="to" value="v2">
    <input type="hidden" name="_wpnonce" value="<?php echo esc_attr($nonce); ?>">
    <button type="submit" <?php disabled(!$applied || $blockers || $areas_off); ?>>Send admin saves to the new tables</button>
  </form>
<?php else: ?>
  <p>Switched <?php echo esc_html($state['at'] ?? ''); ?>. Facilities saved since: <strong><?php echo (int)$edits; ?></strong>.
  Links moved at the switch: <?php echo (int)($state['link_repoints']['moved'] ?? 0); ?>.</p>
  <?php if ($legacy_changed): ?>
    <p class="note">facilities_master changed after the switch. Something still writes the old facility table; check the error log.</p>
  <?php endif; ?>
  <p>Switching back sends saves to the old tables again and restarts the 10-minute sync, which rebuilds the new tables from the old ones. <strong>The <?php echo (int)$edits; ?> facility edit(s) made since the switch are not in the old tables and would be lost.</strong></p>
  <form method="post" action="<?php echo esc_url($self); ?>" onsubmit="return confirm('Discard the edits made since the switch and go back to the old tables?');">
    <input type="hidden" name="action" value="set_writes">
    <input type="hidden" name="to" value="legacy">
    <input type="hidden" name="confirm" value="discard">
    <input type="hidden" name="_wpnonce" value="<?php echo esc_attr($nonce); ?>">
    <button type="submit" class="back">Switch back to the old tables</button>
  </form>
<?php endif; ?>
<p><a href="<?php echo esc_url($self . '?action=cutover'); ?>">Public pages</a> &middot; <a href="<?php echo esc_url($self . '?action=status'); ?>">Status</a></p>
</body>
</html>
            <?php
            exit;
        }

        case 'run': {
            $nonce = wp_create_nonce($nonce_action);
            $self = strtok($_SERVER['REQUEST_URI'], '?');
            header('Content-Type: text/html; charset=utf-8');
            ?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Facility model migration</title>
<style>
  body { font: 15px/1.5 system-ui, sans-serif; max-width: 760px; margin: 2rem auto; padding: 0 1rem; color: #000435; }
  button { font: inherit; padding: .5rem 1rem; background: #000080; color: #fff; border: 0; border-radius: 4px; cursor: pointer; }
  button[disabled] { opacity: .5; cursor: default; }
  pre { background: #F2EEDF; padding: 1rem; overflow-x: auto; white-space: pre-wrap; }
  .bar { height: 10px; background: #F2EEDF; border-radius: 5px; overflow: hidden; margin: 1rem 0; }
  .bar > div { height: 100%; width: 0; background: #33A7B5; transition: width .3s; }
</style>
</head>
<body>
<h1>Facility model migration: apply</h1>
<p>Writes the v2 tables from the legacy data. Legacy tables and link tables are not changed.
Safe to run again. Take the phase 0 backup first (<code>api/backup-data-tables.php</code>).</p>
<p><a href="<?php echo esc_url($self . '?action=dry_run'); ?>">Dry run summary</a> &middot;
<a href="<?php echo esc_url($self . '?action=status'); ?>">Status</a></p>
<button id="go">Apply</button>
<div class="bar"><div id="fill"></div></div>
<pre id="log">Not started.</pre>
<script>
(function () {
  var nonce = <?php echo json_encode($nonce); ?>;
  var url = <?php echo json_encode($self); ?>;
  var log = document.getElementById('log');
  var fill = document.getElementById('fill');
  var btn = document.getElementById('go');
  function write(line) { log.textContent += '\n' + line; }
  function step(stage, offset) {
    var body = new FormData();
    body.append('action', 'apply');
    body.append('stage', stage);
    body.append('offset', offset);
    body.append('batch', 500);
    body.append('_wpnonce', nonce);
    return fetch(url, { method: 'POST', body: body, credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (res) {
        if (!res.success) throw new Error(res.error || 'failed');
        if (res.stage === 'facilities') {
          write('facilities ' + res.offset + '-' + res.next_offset + ' of ' + res.total + ': written ' + res.written + ', unchanged ' + res.unchanged);
          fill.style.width = Math.round(100 * res.next_offset / res.total) + '%';
          return step(res.next_stage, res.next_offset);
        }
        fill.style.width = '100%';
        write('finished: operators, operator links, ' + res.removed + ' stale rows removed');
        write(JSON.stringify(res.summary, null, 2));
      });
  }
  btn.addEventListener('click', function () {
    btn.disabled = true;
    log.textContent = 'Starting...';
    step('facilities', 0).catch(function (e) { write('ERROR: ' + e.message + ' - press Apply to resume.'); btn.disabled = false; });
  });
})();
</script>
</body>
</html>
            <?php
            exit;
        }

        default:
            kop_mfm_json(array('success' => false, 'error' => 'Unknown action.'), 400);
    }
} catch (Throwable $e) {
    kop_mfm_json(array('success' => false, 'error' => $e->getMessage()), 500);
}
