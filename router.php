<?php
declare(strict_types=1);

$requestPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$requestQuery = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_QUERY);
$playlistPort = (int) (getenv('IPTV_PLAYLIST_PORT') ?: 8001);
$clearPort = (int) (getenv('IPTV_CLEAR_PORT') ?: 8002);
$webPort = (int) (getenv('IPTV_PORT') ?: 8000);
$serverPort = (int) ($_SERVER['SERVER_PORT'] ?? 0);

if (!is_string($requestPath) || str_starts_with($requestPath, '/data/') || preg_match('~(?:^|/)\.~', $requestPath)) {
    http_response_code(404);
    exit;
}

if ($serverPort === $clearPort) {
    if ($requestPath !== '/' || $requestQuery !== null) {
        header('Location: /', true, 302);
        exit;
    }

    require __DIR__ . '/playlist-clear.php';
    exit;
}

if ($serverPort === $playlistPort) {
    if ($requestPath !== '/' || $requestQuery !== null) {
        header('Location: /', true, 302);
        exit;
    }

    require __DIR__ . '/playlist.php';
    exit;
}

if ($serverPort === $webPort && !in_array($requestPath, ['/', '/index.php', '/style.css'], true)) {
    header('Location: /', true, 302);
    exit;
}

if ($serverPort === $webPort && $requestPath === '/style.css') {
    $stylesheet = __DIR__ . '/style.css';
    if (!is_file($stylesheet)) {
        http_response_code(404);
        exit;
    }
    $modifiedAt = (int) filemtime($stylesheet);
    $etag = '"' . sha1_file($stylesheet) . '"';
    header('Content-Type: text/css; charset=utf-8');
    header('Cache-Control: no-cache');
    header('ETag: ' . $etag);
    header('Last-Modified: ' . gmdate('D, d M Y H:i:s', $modifiedAt) . ' GMT');

    $ifNoneMatch = trim((string) ($_SERVER['HTTP_IF_NONE_MATCH'] ?? ''));
    if ($ifNoneMatch !== '') {
        $tags = array_map('trim', explode(',', $ifNoneMatch));
        if (in_array($etag, $tags, true) || in_array('W/' . $etag, $tags, true)) {
            http_response_code(304);
            exit;
        }
    } else {
        $ifModifiedSince = (string) ($_SERVER['HTTP_IF_MODIFIED_SINCE'] ?? '');
        if ($ifModifiedSince !== '') {
            $since = strtotime($ifModifiedSince);
            if ($since !== false && $modifiedAt <= $since) {
                http_response_code(304);
                exit;
            }
        }
    }

    header('Content-Length: ' . filesize($stylesheet));
    readfile($stylesheet);
    exit;
}

return false;
