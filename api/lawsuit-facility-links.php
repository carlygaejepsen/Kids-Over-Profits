<?php
/**
 * Shared helpers for connecting lawsuits to facilities_master rows and for
 * filing lawsuit documents into the right FileBird folder.
 *
 * Mirrors the news linking stack (news-mentions.php / news_facility_links):
 *   - lawsuit_facility_links join table (lawsuit_id, facility_id, link_type)
 *   - names in lawsuits.facilities_mentioned are resolved to facility ids via
 *     the shared alias index in facility-aliases.php; unmatched names stay
 *     text-only.
 *
 * PDO-only except the FileBird/attachment helpers, which need WordPress
 * ($wpdb + attachment functions) and no-op gracefully without it.
 */

require_once __DIR__ . '/news-mentions.php';  // kop_normalize_facility_mentions + facility-aliases.php

if (!function_exists('kop_lawsuit_links_ensure_table')) {
    /** Create the join table on first use (older environments won't have it). */
    function kop_lawsuit_links_ensure_table(PDO $pdo): void {
        static $done = false;
        if ($done) return;
        $pdo->exec("CREATE TABLE IF NOT EXISTS `lawsuit_facility_links` (
          `lawsuit_id` int(11) NOT NULL COMMENT 'FK -> lawsuits.id',
          `facility_id` int(11) NOT NULL COMMENT 'FK -> facilities_master.id',
          `link_type` enum('mentioned','primary','related') NOT NULL DEFAULT 'mentioned',
          `created_by` varchar(255) DEFAULT NULL,
          `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
          PRIMARY KEY (`lawsuit_id`,`facility_id`),
          KEY `by_facility` (`facility_id`,`lawsuit_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Structured links between lawsuits and facilities'");
        $done = true;
    }
}

if (!function_exists('kop_sync_lawsuit_facility_links')) {
    /**
     * Sync lawsuit_facility_links rows from a lawsuit's facilities_mentioned
     * value (JSON string, array of names, or array of {name, facility_id}).
     *
     * Same contract as kop_sync_news_facility_links: this sync owns only
     * link_type 'mentioned'; primary/related links created by an admin survive
     * re-saves. Returns the full set of linked facility ids (all link types).
     *
     * @return int[] facility ids now linked to this lawsuit
     */
    function kop_sync_lawsuit_facility_links(PDO $pdo, int $lawsuitId, $mentionsValue, ?string $createdBy = null): array {
        kop_lawsuit_links_ensure_table($pdo);

        $desiredIds = [];
        $unresolvedNames = [];
        foreach (kop_normalize_facility_mentions($mentionsValue) as $m) {
            if (!empty($m['facility_id'])) {
                $desiredIds[(int)$m['facility_id']] = true;
            } elseif (!empty($m['name'])) {
                $unresolvedNames[$m['name']] = true;
            }
        }

        if (!empty($unresolvedNames)) {
            static $aliasIndex = null;
            if ($aliasIndex === null) {
                $aliasIndex = kop_build_facility_alias_index($pdo);
            }
            foreach (array_keys($unresolvedNames) as $name) {
                $fid = kop_resolve_mention_to_facility((string)$name, $aliasIndex);
                if ($fid !== null) {
                    $desiredIds[$fid] = true;
                }
            }
        }

        $desiredIds = array_keys($desiredIds);

        if (empty($desiredIds)) {
            $stmt = $pdo->prepare("DELETE FROM lawsuit_facility_links WHERE lawsuit_id = ? AND link_type = 'mentioned'");
            $stmt->execute([$lawsuitId]);
        } else {
            $placeholders = implode(',', array_fill(0, count($desiredIds), '?'));
            $stmt = $pdo->prepare(
                "DELETE FROM lawsuit_facility_links WHERE lawsuit_id = ? AND link_type = 'mentioned' AND facility_id NOT IN ($placeholders)"
            );
            $stmt->execute(array_merge([$lawsuitId], $desiredIds));

            $ins = $pdo->prepare(
                "INSERT IGNORE INTO lawsuit_facility_links (lawsuit_id, facility_id, link_type, created_by)
                 VALUES (?, ?, 'mentioned', ?)"
            );
            foreach ($desiredIds as $fid) {
                $ins->execute([$lawsuitId, $fid, $createdBy]);
            }
        }

        $all = $pdo->prepare("SELECT facility_id FROM lawsuit_facility_links WHERE lawsuit_id = ?");
        $all->execute([$lawsuitId]);
        return array_map('intval', $all->fetchAll(PDO::FETCH_COLUMN));
    }
}

if (!function_exists('kop_lawsuit_resolve_facility_folder')) {
    /**
     * Find the FileBird folder for one of the linked facilities: the first
     * fbv folder (type 0) whose normalized name matches a facility's
     * unique_name. Requires WordPress ($wpdb) for the fbv table; returns null
     * when WP isn't loaded or nothing matches.
     */
    function kop_lawsuit_resolve_facility_folder(PDO $pdo, array $facilityIds): ?int {
        global $wpdb;
        if (empty($facilityIds) || !isset($wpdb) || !is_object($wpdb)) return null;

        $placeholders = implode(',', array_fill(0, count($facilityIds), '?'));
        $stmt = $pdo->prepare("SELECT id, unique_name FROM facilities_master WHERE id IN ($placeholders) ORDER BY FIELD(id, $placeholders)");
        $stmt->execute(array_merge(array_map('intval', $facilityIds), array_map('intval', $facilityIds)));
        $facilities = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (empty($facilities)) return null;

        static $folderIndex = null;
        if ($folderIndex === null) {
            $folderIndex = [];
            $fbv = $wpdb->prefix . 'fbv';
            foreach ((array)$wpdb->get_results("SELECT id, name FROM {$fbv} WHERE type = 0") as $f) {
                $key = kop_normalize_name_key((string)$f->name);
                if ($key !== '' && !isset($folderIndex[$key])) {
                    $folderIndex[$key] = (int)$f->id;
                }
            }
        }

        foreach ($facilities as $fac) {
            $key = kop_normalize_name_key((string)$fac['unique_name']);
            if ($key !== '' && isset($folderIndex[$key])) {
                return $folderIndex[$key];
            }
        }
        return null;
    }
}

if (!function_exists('kop_lawsuit_file_documents')) {
    /**
     * File a lawsuit's uploaded documents into a FileBird folder.
     *
     * For each document_urls entry that points into this site's uploads dir:
     *   - register it as a media-library attachment if it isn't one already
     *     (public submissions upload raw files without an attachment row)
     *   - put the attachment in $folderId (skipped when the attachment already
     *     has a folder, so admin re-filing isn't clobbered)
     *
     * Requires WordPress; silently returns 0 without it.
     *
     * @return int number of documents now in the folder
     */
    function kop_lawsuit_file_documents(array $documentUrls, int $folderId, ?string $title = null): int {
        global $wpdb;
        if ($folderId <= 0 || empty($documentUrls) || !function_exists('wp_upload_dir') || !isset($wpdb)) return 0;

        if (!function_exists('wp_generate_attachment_metadata')) {
            require_once ABSPATH . 'wp-admin/includes/image.php';
            require_once ABSPATH . 'wp-admin/includes/file.php';
            require_once ABSPATH . 'wp-admin/includes/media.php';
        }

        $uploads  = wp_upload_dir();
        $base_url = rtrim((string)$uploads['baseurl'], '/');
        $base_dir = rtrim((string)$uploads['basedir'], '/');
        $fbv_rel  = $wpdb->prefix . 'fbv_attachment_folder';
        $filed    = 0;

        foreach ($documentUrls as $url) {
            if (!is_string($url) || strpos($url, $base_url . '/') !== 0) continue;

            $relative = substr($url, strlen($base_url) + 1);
            $path = $base_dir . '/' . $relative;

            $attachment_id = (int)attachment_url_to_postid($url);
            if (!$attachment_id) {
                if (!file_exists($path)) continue;
                $filetype = wp_check_filetype(basename($path));
                if (empty($filetype['type'])) continue;
                $attachment_id = wp_insert_attachment([
                    'post_mime_type' => $filetype['type'],
                    'post_title'     => $title !== null && $title !== ''
                        ? $title
                        : preg_replace('/\.[^.]+$/', '', basename($path)),
                    'post_content'   => '',
                    'post_status'    => 'inherit',
                ], $path);
                if (is_wp_error($attachment_id) || !$attachment_id) continue;
                wp_update_attachment_metadata($attachment_id, wp_generate_attachment_metadata($attachment_id, $path));
            }

            // Respect an existing folder assignment; only file the homeless.
            $existing = $wpdb->get_var($wpdb->prepare(
                "SELECT folder_id FROM {$fbv_rel} WHERE attachment_id = %d LIMIT 1", $attachment_id
            ));
            if ($existing === null) {
                $wpdb->insert($fbv_rel, ['folder_id' => $folderId, 'attachment_id' => $attachment_id], ['%d', '%d']);
            }
            $filed++;
        }
        return $filed;
    }
}
