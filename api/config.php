<?php
// Database configuration

// Message from Tom: This is hardcoded and is actually a really big security risk.
// We need to get this from an .env file. Right now anyone who can access this file can see the password.
// If you get hacked, this is the first thing they will try to access.
$db_host = 'localhost';
$db_name = 'kidsover_suggestions';
$db_user = 'kidsover_dani';
$db_pass = 'Xk4&z9!pT#vR7bN@';

// Backwards-compatible variable names used by older scripts
// Some files expect $host/$dbname/$username/$password — define aliases so both styles work
$host = $db_host;
$dbname = $db_name;
$username = $db_user;
$password = $db_pass;

try {
    $pdo = new PDO("mysql:host=$db_host;dbname=$db_name;charset=utf8mb4", $db_user, $db_pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Database connection failed', 'details' => $e->getMessage()]);
    exit;
}
