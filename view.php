<?php
include 'db.php';

$id = (int)($_GET['id'] ?? 0);
$res = $conn->query("SELECT * FROM resources WHERE id=$id")->fetch_assoc();

if (!$res) {
    die("Resource not found");
}

if ($res['type'] === 'link') {
    header("Location: " . $res['link']);
    exit;
}

if (!$res['filename']) {
    die("No file attached");
}

$filePath = __DIR__ . "/uploads/" . $res['folder'] . "/" . $res['filename'];

if (!is_file($filePath)) {
    die("File not found on server");
}

$ext = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));

$mimeTypes = [
    'pdf'  => 'application/pdf',
    'doc'  => 'application/msword',
    'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
    'xls'  => 'application/vnd.ms-excel',
    'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
    'ppt'  => 'application/vnd.ms-powerpoint',
    'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
    'jpg'  => 'image/jpeg',
    'jpeg' => 'image/jpeg',
    'png'  => 'image/png',
    'gif'  => 'image/gif',
    'txt'  => 'text/plain',
];

$mime = $mimeTypes[$ext] ?? 'application/octet-stream';

// For images → show inline
// For PDF → try inline (but browser setting can override)
// For Office docs → almost always download anyway
// Update this line in view.php
$inline_types = ['pdf', 'jpg', 'jpeg', 'png', 'gif', 'txt'];
$disposition = in_array($ext, $inline_types) ? 'inline' : 'attachment';
header('Content-Type: ' . $mime);
header('Content-Disposition: ' . $disposition . '; filename="' . basename($res['filename']) . '"');
header('Content-Length: ' . filesize($filePath));
header('Cache-Control: private, max-age=0, must-revalidate');
header('Pragma: public');
header('Accept-Ranges: bytes');

readfile($filePath);
exit;