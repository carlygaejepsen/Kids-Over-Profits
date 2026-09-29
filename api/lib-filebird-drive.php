<?php
/**
 * Google Drive through FileBird Cloud's connection (its njfb_cloud_settings
 * token), shared by the CLI tools that read the Drive FileBird folder:
 * sync-drive-documents.php and sync-inspection-archive.php. Needs WordPress
 * loaded and FileBird Cloud active.
 */

function kop_sd_token() {
    $token = njfb_cloud_get_settings_by_key('google_drive', 'token', array());
    if (!is_array($token) || empty($token['refresh_token'])) {
        throw new RuntimeException('FileBird Cloud has no Google Drive connection - connect it under FileBird > Cloud.');
    }
    if (\NjFbCloud\Src\Classes\GoogleDriveApi::isExpired($token)) {
        $fresh = \NjFbCloud\Src\Classes\GoogleDriveApi::refreshToken($token['refresh_token']);
        if (!is_array($fresh) || empty($fresh['access_token'])) {
            throw new RuntimeException('Google refused to refresh the token - reconnect under FileBird > Cloud.');
        }
        $fresh = njfb_cloud_append_refresh_token('google_drive', $fresh);
        njfb_cloud_set_settings_by_key('google_drive', 'token', $fresh);
        $token = $fresh;
    }
    return $token['access_token'];
}

function kop_sd_drive_get($path, $query) {
    static $token = null, $token_at = 0;
    if ($token === null || time() - $token_at > 1800) {
        $token = kop_sd_token();
        $token_at = time();
    }
    $url = add_query_arg(array_map('rawurlencode', $query), 'https://www.googleapis.com/drive/v3/' . $path);
    for ($attempt = 1; $attempt <= 3; $attempt++) {
        $res = wp_remote_get($url, array('timeout' => 60, 'headers' => array('Authorization' => 'Bearer ' . $token)));
        $code = is_wp_error($res) ? 0 : (int)wp_remote_retrieve_response_code($res);
        if ($code === 200) {
            return json_decode(wp_remote_retrieve_body($res), true);
        }
        if ($code === 401) {
            $token = kop_sd_token();
            $token_at = time();
        }
        sleep(2 * $attempt);
    }
    throw new RuntimeException('Drive API ' . $path . ' failed: '
        . (is_wp_error($res) ? $res->get_error_message() : wp_remote_retrieve_body($res)));
}

function kop_sd_download($file_id, $dest) {
    $token = kop_sd_token();
    $res = wp_remote_get('https://www.googleapis.com/drive/v3/files/' . rawurlencode($file_id) . '?alt=media', array(
        'timeout'  => 600,
        'stream'   => true,
        'filename' => $dest,
        'headers'  => array('Authorization' => 'Bearer ' . $token),
    ));
    if (is_wp_error($res)) {
        throw new RuntimeException('download failed: ' . $res->get_error_message());
    }
    if ((int)wp_remote_retrieve_response_code($res) !== 200) {
        throw new RuntimeException('download failed: HTTP ' . wp_remote_retrieve_response_code($res));
    }
}
