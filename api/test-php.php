<?php
header('Content-Type: application/json');
echo json_encode(['status' => 'PHP is working', 'time' => date('Y-m-d H:i:s')]);
