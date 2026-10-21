<?php
declare(strict_types=1);

require_once __DIR__ . '/lib.php';

/*
 * The auto port picks the playlist variant from the matched user-agent rule:
 *
 *   clear     -> the clear playlist
 *   playlist  -> the full playlist
 *   serve     -> the default playlist of this port (Security > Auto port)
 *   block     -> 404 Not Found
 *   no match  -> 404 Not Found (access is denied by default)
 */
$GLOBALS['playlistClearOnly'] = userAgentAutoDefault() === 'clear';

require __DIR__ . '/playlist.php';
