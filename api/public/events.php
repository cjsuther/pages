<?php
require_once '../config.php';
require_once __DIR__ . '/../Database.php';

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET');

$database = new Database();
$db = $database->connect();

$startDate = $_GET['start'] ?? date('Y-m-d');
$endDate = $_GET['end'] ?? date('Y-m-d', strtotime('+30 days'));

$stmt = $db->prepare('
  SELECT
    l.id,
    l.text as title,
    l.description,
    l.event_date,
    l.event_time,
    l.event_address as location,
    p.url_slug as slug,
    p.title as page_title,
    u.name as owner_name,
    u.email as owner_email
  FROM links l
  JOIN link_groups lg ON l.group_id = lg.id
  JOIN pages p ON lg.page_id = p.id
  JOIN users u ON p.user_id = u.id
  WHERE lg.type = "eventos"
  AND l.event_date IS NOT NULL
  AND l.event_date BETWEEN ? AND ?
  ORDER BY l.event_date ASC, l.event_time ASC
');

$stmt->execute([$startDate, $endDate]);
$events = $stmt->fetchAll();

echo json_encode(['events' => $events]);
