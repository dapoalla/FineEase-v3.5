<?php
require_once '../config/config.php';
require_once '../includes/functions.php';
require_login();
header('Content-Type: application/json');

$project_type_id = intval($_GET['project_type_id'] ?? 0);
if ($project_type_id <= 0) {
    echo json_encode(['error' => 'Invalid project type']);
    exit;
}

$stmt = $pdo->prepare('SELECT id, field_name, field_label, field_type, is_required FROM project_survey_fields WHERE project_type_id = ?');
$stmt->execute([$project_type_id]);
$fields = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo json_encode(['fields' => $fields]);
?>
