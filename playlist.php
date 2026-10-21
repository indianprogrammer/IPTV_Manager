<?php
declare(strict_types=1);

require_once __DIR__ . '/lib.php';

$db = database();
initializeDatabase($db);

$clearOnly = $GLOBALS['playlistClearOnly'] ?? false;

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
