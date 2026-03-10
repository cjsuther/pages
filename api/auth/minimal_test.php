<?php
require_once '../config.php';
$data = json_decode(file_get_contents('php://input'), true);
echo json_encode(['received' => $data, 'method' => $_SERVER['REQUEST_METHOD']]);
