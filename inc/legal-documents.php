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
        global $wpdb;

        if (!isset($wpdb) || !is_object($wpdb) || !method_exists($wpdb, 'prepare')
            || !method_exists($wpdb, 'get_results') || !function_exists('get_permalink')) {
            return null;
        }

        $page_url = get_permalink($post_id);
        if (!$page_url) {
            return null;
        }

        $like = '%' . (method_exists($wpdb, 'esc_like') ? $wpdb->esc_like($page_url) : addcslashes($page_url, '_%\\')) . '%';
        $rows = $wpdb->get_results(
            $wpdb->prepare('SELECT * FROM lawsuits WHERE document_urls LIKE %s ORDER BY id DESC', $like),
            ARRAY_A
        );

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
