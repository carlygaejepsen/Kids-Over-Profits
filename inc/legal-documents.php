<?php
/** Data lookups shared by legal-document page templates. */

if (!function_exists('kop_legal_document_lawsuit')) {
    /**
     * Find the lawsuit whose document_urls contains this page's URL.
     *
     * @param int $post_id WordPress page ID.
     * @return array|null
     */
    function kop_legal_document_lawsuit($post_id) {
        // The lawsuits table is in the records database api/config.php
        // connects to, not the WordPress one, so $wpdb cannot see it.
        $pdo = function_exists('kop_seed_pdo') ? kop_seed_pdo() : null;
        if (!$pdo instanceof PDO || !function_exists('get_permalink')) {
            return null;
        }

        $page_url = get_permalink($post_id);
        if (!$page_url) {
            return null;
        }

        // document_urls is JSON, so "/" is stored escaped as "\/"; match on
        // the slug, then compare decoded paths below.
        $slug = basename(untrailingslashit((string) wp_parse_url($page_url, PHP_URL_PATH)));
        if ($slug === '') {
            return null;
        }
        try {
            $stmt = $pdo->prepare(
                "SELECT * FROM lawsuits WHERE document_urls LIKE ?
                   AND publication_status IN ('approved','published') ORDER BY id DESC"
            );
            // A "_" wildcard in the slug only over-matches; the path check below is exact.
            $stmt->execute(array('%' . $slug . '%'));
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            return null;
        }

        $page_path = wp_parse_url($page_url, PHP_URL_PATH);
        $page_path = untrailingslashit((string) $page_path);
        foreach ((array) $rows as $row) {
            $urls = json_decode(isset($row['document_urls']) ? $row['document_urls'] : '', true);
            if (!is_array($urls)) {
                continue;
            }
            foreach ($urls as $url) {
                if (!is_string($url)) {
                    continue;
                }
                $url_path = wp_parse_url($url, PHP_URL_PATH);
                if (untrailingslashit((string) $url_path) === $page_path) {
                    return $row;
                }
            }
        }

        return null;
    }
}

if (!function_exists('kop_legal_document_source_pdf')) {
    /** Return an attached PDF source file, if the page has one. */
    function kop_legal_document_source_pdf($post_id) {
        if (!function_exists('get_children')) {
            return '';
        }

        $files = get_children(array(
            'post_parent' => (int) $post_id,
            'post_type' => 'attachment',
            'post_mime_type' => 'application/pdf',
            'post_status' => 'inherit',
            'numberposts' => 1,
            'orderby' => 'menu_order ID',
            'order' => 'ASC',
        ));
        if (!$files) {
            return '';
        }

        $file = reset($files);
        return $file && function_exists('wp_get_attachment_url')
            ? (string) wp_get_attachment_url($file->ID)
            : '';
    }
}
