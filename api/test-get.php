<?php
// Simulate a GET request to list submissions
$_SERVER['REQUEST_METHOD'] = 'GET';
$_GET['limit'] = 5;
$_GET['offset'] = 0;

// Capture output
ob_start();
include __DIR__ . '/save-wiki-submission.php';
$output = ob_get_clean();

echo $output;
