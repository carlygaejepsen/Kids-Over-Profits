<?php
// This local suggestions endpoint has been removed. The app now calls the
// live API at /wp-content/themes/child/api/get-suggestions.php on the remote host.
http_response_code(410);
header('Content-Type: application/json');
echo json_encode(['success' => false, 'error' => 'Local suggestions endpoint removed. Use the live API.']);
exit;

?>
