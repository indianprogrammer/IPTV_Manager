<?php
declare(strict_types=1);

require_once __DIR__ . '/lib.php';

$db = database();
initializeDatabase($db);

$clearOnly = $GLOBALS['playlistClearOnly'] ?? false;

$decision = evaluateUserAgent((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''));
if ($decision['rule'] !== null) {
    $rulePattern = preg_replace('/[^\x20-\x7E]/', '.', (string) $decision['rule']['pattern']);
    header('X-UA-Rule: ' . $decision['action'] . ' ' . $rulePattern);
    requestNote('ua:' . $decision['action'] . ':' . $decision['rule']['pattern']);
    $clearOnly = $decision['action'] === 'clear';
} else {
    requestNote('ua:deny');
}

if ($decision['action'] === 'block') {
    header('Cache-Control: no-store');
    http_response_code(404);
    exit;
}

$playlist = cachedPlaylist($db, $clearOnly);
$content = $playlist['content'];
$generatedAt = (int) $playlist['generated_at'];
$etag = '"' . sha1($content) . '"';

header('Content-Type: audio/x-mpegurl; charset=utf-8');
header('Content-Disposition: inline; filename="' . ($clearOnly ? 'iptv-manager-clear.m3u' : 'iptv-manager.m3u') . '"');
header('Cache-Control: public, max-age=60, must-revalidate');
header('ETag: ' . $etag);
header('Last-Modified: ' . gmdate('D, d M Y H:i:s', $generatedAt) . ' GMT');
header('X-Playlist-Cache: ' . ($playlist['cached'] ? 'HIT' : 'MISS'));

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
        if ($since !== false && $generatedAt <= $since) {
            http_response_code(304);
            exit;
        }
    }
}

echo $content;
