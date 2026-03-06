<?php

require_once '../config.php';
require_once '../Database.php';
require_once '../JWT.php';

$user = JWT::getUserFromToken();
if (!$user) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit();
}

if (!isset($_FILES['image'])) {
    http_response_code(400);
    echo json_encode(['error' => 'No image file provided']);
    exit();
}

$file = $_FILES['image'];

$allowedTypes = ['image/jpeg', 'image/jpg', 'image/png', 'image/gif', 'image/webp'];
if (!in_array($file['type'], $allowedTypes)) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid file type. Only JPG, PNG, GIF, and WebP are allowed']);
    exit();
}

$maxSize = 5 * 1024 * 1024;
if ($file['size'] > $maxSize) {
    http_response_code(400);
    echo json_encode(['error' => 'File size too large. Maximum 5MB allowed']);
    exit();
}

$imageInfo = getimagesize($file['tmp_name']);
if ($imageInfo === false) {
    http_response_code(400);
    echo json_encode(['error' => 'File is not a valid image']);
    exit();
}

$uploadDir = dirname(__DIR__) . '/uploads/';
if (!file_exists($uploadDir)) {
    mkdir($uploadDir, 0755, true);
}

$extension = pathinfo($file['name'], PATHINFO_EXTENSION);
$filename = uniqid() . '_' . time() . '.' . $extension;
$destination = $uploadDir . $filename;

if (!move_uploaded_file($file['tmp_name'], $destination)) {
    http_response_code(500);
    echo json_encode(['error' => 'Failed to upload file']);
    exit();
}

$imageUrl = UPLOAD_URL . '/uploads/' . $filename;

echo json_encode(['url' => $imageUrl]);
