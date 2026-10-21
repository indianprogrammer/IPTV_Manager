<?php
declare(strict_types=1);

require_once __DIR__ . '/lib.php';

$file = resolveLogoFile((string) ($_GET['f'] ?? ''));
if ($file === null) {
    http_response_code(404);
    exit;
}

$extensions = logoMimeExtensions();
$mime = (string) (new finfo(FILEINFO_MIME_TYPE))->file($file);
if (!isset($extensions[$mime])) {
    $mime = 'application/octet-stream';
}

header('Content-Type: ' . $mime);
header('Content-Length: ' . filesize($file));
header('Cache-Control: public, max-age=86400');
header('X-Content-Type-Options: nosniff');
readfile($file);
