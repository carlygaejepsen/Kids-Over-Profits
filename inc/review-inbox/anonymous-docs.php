<?php
/**
 * Review inbox source: documents sent through the anonymous portal
 * (AnonymousDocPortal in inc/features.php). Each is a sealed file in
 * uploads/anonymous-submissions/ that this server cannot open; the card
 * links the same encrypted download the Anonymous Docs screen gives. There
 * is no status of their own, so "Mark handled" keeps the file name in the
 * kop_anon_docs_handled option (Undo: "Mark not handled").
 */

if (!defined('ABSPATH')) {
    exit;
}

kop_rinbox_register('anonymous-docs', function () {
    if (!class_exists('AnonymousDocPortal')) return null;
    // What the Anonymous Docs screen says above its list: the key, scanning, older unencrypted files.
    $portal = $GLOBALS['kop_anon_doc_portal'] ?? null;
    $status = $portal instanceof AnonymousDocPortal ? $portal->status() : null;
    $notes = '';
    $tools = array();
    if ($status) {
        if ($status['key'] === '') {
            $notes .= ' No public key is set up, so the upload page is refusing documents: run scripts/anon-portal-keygen.php and deploy inc/anonymous-portal-public.key.';
        } else {
            $notes .= ' Locked with key ' . $status['key'] . '.';
            if (!$status['scanning']) $notes .= ' Malware scanning is not set up (no CLOUDMERSIVE_API_KEY), so the upload page is refusing documents.';
        }
        if ($status['plaintext'] > 0) {
            $notes .= ' ' . $status['plaintext'] . ' document(s) from before encryption are stored unlocked.';
            if ($status['key'] !== '') {
                $tools[] = array('id' => 'encrypt', 'label' => 'Lock the older documents now', 'style' => 'approve',
                    'help' => 'Encrypts the documents stored before encryption existed and deletes their unlocked copies.',
                    'confirm' => 'Encrypt the older documents and delete their unlocked copies?');
            }
        }
    }
    return array(
        'label'    => 'Anonymous documents',
        'group'    => 'Sent in by readers',
        'help'     => 'Documents sent through the anonymous upload page. They are locked: download one and open it on your own computer with scripts/anon-portal-decrypt.php and your private key. Mark it handled once you have read it.' . $notes,
        'views'    => array('new' => 'New', 'handled' => 'Handled'),
        'view_counts' => function (array $q = array()) {
            $handled = kop_rinbox_anon_handled();
            $out = array('new' => 0, 'handled' => 0);
            foreach (kop_rinbox_anon_files() as $f) $out[isset($handled[$f['name']]) ? 'handled' : 'new']++;
            return $out;
        },
        'tools'    => $tools,
        'tool'     => function ($id, array $params) {
            $portal = $GLOBALS['kop_anon_doc_portal'] ?? null;
            if ($id !== 'encrypt' || !($portal instanceof AnonymousDocPortal)) throw new RuntimeException('Unknown tool.');
            $done = $portal->encrypt_existing();
            return array('message' => $done . ' older document(s) locked; their unlocked copies were deleted. Reload the page to see them in the list.');
        },
        'tool_url' => admin_url('admin.php?page=anonymous-docs'),
        'count'    => function () {
            $handled = kop_rinbox_anon_handled();
            return count(array_filter(kop_rinbox_anon_files(), function ($f) use ($handled) { return !isset($handled[$f['name']]); }));
        },
        'list'     => 'kop_rinbox_anon_list',
        'get'      => function ($key) {
            foreach (kop_rinbox_anon_files() as $f) {
                if ($f['name'] === (string) $key) return kop_rinbox_anon_item($f);
            }
            return null;
        },
        'act'      => 'kop_rinbox_anon_act',
    );
});

function kop_rinbox_anon_dir() {
    $up = wp_upload_dir();
    return $up['basedir'] . '/anonymous-submissions/';
}

/** The sealed files, newest first: [name, size, time]. */
function kop_rinbox_anon_files() {
    $dir = kop_rinbox_anon_dir();
    $out = array();
    foreach (is_dir($dir) ? (array) scandir($dir) : array() as $name) {
        if (!preg_match('/^(' . AnonymousDocPortal::ID_PATTERN . ')\.sealed$/', (string) $name) || !is_file($dir . $name)) continue;
        $out[] = array('name' => $name, 'size' => (int) filesize($dir . $name), 'time' => (int) filemtime($dir . $name));
    }
    usort($out, function ($a, $b) { return $b['time'] <=> $a['time'] ?: strcmp($b['name'], $a['name']); });
    return $out;
}

/** File name => array(by, at) for the files someone marked handled. */
function kop_rinbox_anon_handled() {
    $h = get_option('kop_anon_docs_handled', array());
    return is_array($h) ? $h : array();
}

function kop_rinbox_anon_list(array $q) {
    $handled = kop_rinbox_anon_handled();
    $want = $q['view'] === 'handled';
    $files = array_values(array_filter(kop_rinbox_anon_files(), function ($f) use ($handled, $want, $q) {
        return isset($handled[$f['name']]) === $want && ($q['search'] === '' || stripos($f['name'], $q['search']) !== false);
    }));
    return array('items' => array_map('kop_rinbox_anon_item', array_slice($files, (int) $q['offset'], (int) $q['limit'])), 'total' => count($files));
}

function kop_rinbox_anon_item(array $f) {
    $handled = kop_rinbox_anon_handled();
    $h = $handled[$f['name']] ?? null;
    $size = function_exists('size_format') ? size_format($f['size']) : number_format($f['size'] / 1024, 1) . ' KB';
    // The Anonymous Docs screen's download link (admin-post kop_anon_download, nonce per file).
    $download = add_query_arg('_wpnonce', wp_create_nonce('kop_anon_download_' . $f['name']),
        admin_url('admin-post.php?action=kop_anon_download&file=' . rawurlencode($f['name'])));
    return array(
        'key'          => $f['name'],
        'title'        => $f['name'],
        'subtitle'     => $size . ' · received ' . gmdate('Y-m-d H:i', $f['time']) . ' UTC',
        'text'         => 'Encrypted. Download it and open it on your own computer with scripts/anon-portal-decrypt.php and your private key.'
            . ($h ? "\nMarked handled by " . $h['by'] . ', ' . $h['at'] . ' UTC.' : ''),
        'created'      => gmdate('Y-m-d H:i:s', $f['time']),
        'status'       => $h ? 'handled' : 'new',
        'status_label' => $h ? 'Handled' : 'New',
        'fields'       => array(),
        'actions'      => array($h
            ? array('id' => 'unhandled', 'label' => 'Mark not handled', 'style' => 'undo',
                'help' => 'Nothing on the site changes; the document goes back with the new ones.')
            : array('id' => 'handled', 'label' => 'Mark handled', 'style' => 'approve',
                'help' => 'Moves the document to Handled. The encrypted file stays on the server and nothing is published.')),
        'links'        => array(array('label' => 'Download (encrypted)', 'url' => $download)),
    );
}

function kop_rinbox_anon_act($key, $action, array $params) {
    $name = (string) $key;
    $found = false;
    foreach (kop_rinbox_anon_files() as $f) {
        if ($f['name'] === $name) $found = true;
    }
    if (!$found) throw new RuntimeException('That file is gone.');
    $handled = kop_rinbox_anon_handled();
    // Names of files that are gone drop out, so the option stays small.
    $names = array_column(kop_rinbox_anon_files(), 'name');
    $handled = array_intersect_key($handled, array_flip($names));
    if ($action === 'handled') {
        $handled[$name] = array('by' => kop_rinbox_reviewer(), 'at' => gmdate('Y-m-d H:i'));
        $msg = 'Marked handled. The file stays on the server; Mark not handled (on the Handled tab) puts it back with the new ones.';
    } elseif ($action === 'unhandled') {
        unset($handled[$name]);
        $msg = 'Back with the new documents.';
    } else {
        throw new RuntimeException('Unknown action.');
    }
    update_option('kop_anon_docs_handled', $handled, false);
    kop_rinbox_flush_counts();
    return array('message' => $msg);
}
