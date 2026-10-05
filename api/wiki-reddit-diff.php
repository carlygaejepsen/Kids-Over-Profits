<?php
/**
 * Which program wiki entries differ from their r/troubledteens wiki page
 * (api/lib-wiki-contact.php). Read by the wiki editor's index to mark rows.
 *
 * GET /api/wiki-reddit-diff.php  ->  { success, entries: { "<lowercase program name>": { slug, reddit_url, differs, edited } } }
 */

header('Content-Type: application/json');
header('X-Content-Type-Options: nosniff');

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/lib-wiki-contact.php';

try {
    if (!isset($pdo) || !($pdo instanceof PDO)) {
        throw new RuntimeException('Database unavailable');
    }
    echo json_encode(array('success' => true, 'entries' => (object) kop_wiki_reddit_compare_all($pdo)));
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(array('success' => false, 'error' => 'Could not compare the wiki entries'));
}
