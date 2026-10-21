<?php
declare(strict_types=1);

function loadEnvironmentFile(): void
{
    static $loaded = false;
    if ($loaded) {
        return;
    }
    $loaded = true;

    $path = __DIR__ . '/.env';
    if (!is_file($path) || !is_readable($path)) {
        return;
    }

    $lines = file($path, FILE_IGNORE_NEW_LINES);
    if ($lines === false) {
        return;
    }

    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) {
            continue;
        }

        $separator = strpos($line, '=');
        if ($separator === false) {
            continue;
        }

        $name = trim(substr($line, 0, $separator));
        $value = trim(substr($line, $separator + 1));
        if ($name === '' || preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $name) !== 1) {
            continue;
        }

        $length = strlen($value);
        if ($length >= 2 && ($value[0] === '"' || $value[0] === "'") && $value[$length - 1] === $value[0]) {
            $value = substr($value, 1, -1);
        }

        if (getenv($name) !== false) {
            continue;
        }

        putenv($name . '=' . $value);
        $_ENV[$name] = $value;
    }
}

loadEnvironmentFile();

function databasePath(): string
{
    return __DIR__ . '/data/iptv.sqlite';
}

function database(): PDO
{
    $directory = dirname(databasePath());
    if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
        throw new RuntimeException('Unable to create the data directory.');
    }

    $db = new PDO('sqlite:' . databasePath());
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $db->exec('PRAGMA foreign_keys = ON');
    return $db;
}

function initializeDatabase(PDO $db): void
{
    $db->exec(
        'CREATE TABLE IF NOT EXISTS admins (
            username TEXT PRIMARY KEY,
            password_hash TEXT NOT NULL
        );
        CREATE TABLE IF NOT EXISTS channels (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            source_id TEXT NOT NULL UNIQUE,
            name TEXT NOT NULL,
            tvg_id TEXT NOT NULL DEFAULT \'\',
            tvg_name TEXT NOT NULL DEFAULT \'\',
            tvg_language TEXT NOT NULL DEFAULT \'\',
            tvg_type TEXT NOT NULL DEFAULT \'\',
            group_title TEXT NOT NULL DEFAULT \'\',
            logo_url TEXT NOT NULL DEFAULT \'\',
            stream_url TEXT NOT NULL DEFAULT \'\',
            license_key_url TEXT NOT NULL DEFAULT \'\',
            attributes_json TEXT NOT NULL DEFAULT \'{}\',
            properties_json TEXT NOT NULL DEFAULT \'{}\',
            url_fields_json TEXT NOT NULL DEFAULT \'{}\',
            enabled INTEGER NOT NULL DEFAULT 1,
            drm_type TEXT NOT NULL DEFAULT \'\',
            drm_key_id TEXT NOT NULL DEFAULT \'\',
            drm_key TEXT NOT NULL DEFAULT \'\',
            drm_headers TEXT NOT NULL DEFAULT \'\',
            updated_at TEXT NOT NULL
        );
        CREATE INDEX IF NOT EXISTS channels_name_idx ON channels(name);
        CREATE INDEX IF NOT EXISTS channels_category_idx ON channels(group_title);
        CREATE TABLE IF NOT EXISTS app_meta (
            name TEXT PRIMARY KEY,
            value TEXT NOT NULL
        );
        CREATE TABLE IF NOT EXISTS epg_programs (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            channel_id INTEGER REFERENCES channels(id) ON DELETE CASCADE,
            epg_channel_id TEXT NOT NULL,
            start_ts INTEGER NOT NULL,
            stop_ts INTEGER NOT NULL,
            title TEXT NOT NULL DEFAULT \'\',
            description TEXT NOT NULL DEFAULT \'\',
            category TEXT NOT NULL DEFAULT \'\'
        );
        CREATE INDEX IF NOT EXISTS epg_programs_channel_idx ON epg_programs(channel_id, start_ts);
        CREATE INDEX IF NOT EXISTS epg_programs_epg_channel_idx ON epg_programs(epg_channel_id);
        CREATE INDEX IF NOT EXISTS epg_programs_stop_idx ON epg_programs(stop_ts);
        CREATE TABLE IF NOT EXISTS feeds (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            channel_id INTEGER NOT NULL REFERENCES channels(id) ON DELETE CASCADE,
            label TEXT NOT NULL DEFAULT \'\',
            stream_url TEXT NOT NULL DEFAULT \'\',
            license_key_url TEXT NOT NULL DEFAULT \'\',
            drm_type TEXT NOT NULL DEFAULT \'\',
            drm_key_id TEXT NOT NULL DEFAULT \'\',
            drm_key TEXT NOT NULL DEFAULT \'\',
            drm_headers TEXT NOT NULL DEFAULT \'\',
            properties_json TEXT NOT NULL DEFAULT \'{}\',
            visibility TEXT NOT NULL DEFAULT \'both\',
            enabled INTEGER NOT NULL DEFAULT 1,
            sort_order INTEGER NOT NULL DEFAULT 0,
            updated_at TEXT NOT NULL DEFAULT \'\'
        );
        CREATE INDEX IF NOT EXISTS feeds_channel_idx ON feeds(channel_id);
        CREATE UNIQUE INDEX IF NOT EXISTS feeds_channel_stream_idx ON feeds(channel_id, stream_url);'
    );

    $statement = $db->prepare('INSERT OR IGNORE INTO admins (username, password_hash) VALUES (?, ?)');
    $statement->execute(['admin', password_hash('admin', PASSWORD_DEFAULT)]);

    ensureChannelColumn($db, 'drm_type', "TEXT NOT NULL DEFAULT ''");
    ensureChannelColumn($db, 'drm_key_id', "TEXT NOT NULL DEFAULT ''");
    ensureChannelColumn($db, 'drm_key', "TEXT NOT NULL DEFAULT ''");
    ensureChannelColumn($db, 'drm_headers', "TEXT NOT NULL DEFAULT ''");

    migrateChannelFeeds($db);
}

function migrateChannelFeeds(PDO $db): void
{
    if (metaValue($db, 'feeds_migrated') === '1') {
        return;
    }

    $existingFeeds = (int) $db->query('SELECT COUNT(*) FROM feeds')->fetchColumn();
    if ($existingFeeds === 0) {
        $now = gmdate('Y-m-d H:i:s');
        $insert = $db->prepare(
            'INSERT INTO feeds (channel_id, label, stream_url, license_key_url, drm_type, drm_key_id, drm_key, '
            . 'drm_headers, properties_json, visibility, enabled, sort_order, updated_at) '
            . "VALUES (:channel_id, '', :stream_url, :license_key_url, :drm_type, :drm_key_id, :drm_key, "
            . ':drm_headers, :properties, :visibility, :enabled, 0, :updated_at)'
        );
        $db->beginTransaction();
        try {
            $channels = $db->query(
                "SELECT id, stream_url, license_key_url, drm_type, drm_key_id, drm_key, drm_headers, "
                . "properties_json, enabled FROM channels WHERE stream_url != ''"
            )->fetchAll();
            foreach ($channels as $channel) {
                $insert->execute([
                    'channel_id' => (int) $channel['id'],
                    'stream_url' => (string) $channel['stream_url'],
                    'license_key_url' => (string) $channel['license_key_url'],
                    'drm_type' => (string) $channel['drm_type'],
                    'drm_key_id' => (string) $channel['drm_key_id'],
                    'drm_key' => (string) $channel['drm_key'],
                    'drm_headers' => (string) $channel['drm_headers'],
                    'properties' => (string) $channel['properties_json'],
                    'visibility' => channelHasDrm($channel) ? 'playlist' : 'both',
                    'enabled' => (int) $channel['enabled'],
                    'updated_at' => $now,
                ]);
            }
            $db->commit();
        } catch (Throwable $error) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            throw $error;
        }
    }

    setMetaValue($db, 'feeds_migrated', '1');
}

function ensureChannelColumn(PDO $db, string $column, string $definition): void
{
    foreach ($db->query('PRAGMA table_info(channels)') as $info) {
        if (($info['name'] ?? '') === $column) {
            return;
        }
    }
    $db->exec('ALTER TABLE channels ADD COLUMN ' . $column . ' ' . $definition);
}

function csrfToken(): string
{
    if (!isset($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return (string) $_SESSION['csrf'];
}

function urlLink(?string $url): string
{
    if (!$url) {
        return '<span class="empty-value">—</span>';
    }
    $escaped = htmlspecialchars($url, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    return '<a href="' . $escaped . '" target="_blank" rel="noopener noreferrer" title="' . $escaped . '">' . $escaped . '</a>';
}

function cacheDirectory(): string
{
    $directory = __DIR__ . '/data/cache';
    if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
        throw new RuntimeException('Unable to create the cache directory.');
    }
    return $directory;
}

function databaseSignature(): string
{
    clearstatcache(true);
    $parts = [];
    foreach ([databasePath(), databasePath() . '-wal'] as $file) {
        if (is_file($file)) {
            $parts[] = $file . ':' . filemtime($file) . ':' . filesize($file);
        }
    }
    return sha1(implode('|', $parts));
}

function cacheVersion(PDO $db): string
{
    $version = $db->query("SELECT value FROM app_meta WHERE name = 'playlist_cache_version'")->fetchColumn();
    return is_string($version) ? $version : '0';
}

function bumpCacheVersion(PDO $db): void
{
    $statement = $db->prepare(
        "INSERT INTO app_meta (name, value) VALUES ('playlist_cache_version', '1') "
        . "ON CONFLICT(name) DO UPDATE SET value = CAST(CAST(value AS INTEGER) + 1 AS TEXT)"
    );
    $statement->execute();
}

function metaValue(PDO $db, string $name, string $default = ''): string
{
    $statement = $db->prepare('SELECT value FROM app_meta WHERE name = :name');
    $statement->execute(['name' => $name]);
    $value = $statement->fetchColumn();
    return is_string($value) ? $value : $default;
}

function setMetaValue(PDO $db, string $name, string $value): void
{
    $statement = $db->prepare(
        'INSERT INTO app_meta (name, value) VALUES (:name, :value) '
        . 'ON CONFLICT(name) DO UPDATE SET value = excluded.value'
    );
    $statement->execute(['name' => $name, 'value' => $value]);
}

function purgeCache(): int
{
    $directory = cacheDirectory();
    $removed = 0;
    foreach (glob($directory . '/*') ?: [] as $file) {
        if (is_file($file) && @unlink($file)) {
            $removed++;
        }
    }
    return $removed;
}

function databaseCache(PDO $db, string $key, callable $callback, int $ttl = 300): mixed
{
    $path = null;
    $signature = databaseSignature() . ':' . cacheVersion($db);
    $store = ['signature' => $signature, 'entries' => []];

    try {
        $path = cacheDirectory() . '/database.json';
        if (is_file($path)) {
            $decoded = json_decode((string) file_get_contents($path), true);
            if (is_array($decoded) && ($decoded['signature'] ?? '') === $signature && is_array($decoded['entries'] ?? null)) {
                $store = $decoded;
            }
        }
        $entry = $store['entries'][$key] ?? null;
        if (is_array($entry) && ($entry['expires'] ?? 0) >= time() && array_key_exists('value', $entry)) {
            return $entry['value'];
        }
    } catch (Throwable) {
        // Rebuild below when the cache is unavailable.
    }

    $value = $callback();
    $store['signature'] = $signature;
    $store['entries'][$key] = ['expires' => time() + $ttl, 'value' => $value];

    if ($path !== null) {
        try {
            $encoded = json_encode($store);
            if ($encoded !== false) {
                writeCacheFile($path, $encoded);
            }
        } catch (Throwable) {
            // Caching is best-effort.
        }
    }

    return $value;
}

function writeCacheFile(string $path, string $contents): void
{
    $temporary = $path . '.' . bin2hex(random_bytes(6)) . '.tmp';
    if (file_put_contents($temporary, $contents, LOCK_EX) === false) {
        throw new RuntimeException('Unable to write the cache file.');
    }
    @chmod($temporary, 0600);
    if (!rename($temporary, $path)) {
        @unlink($temporary);
        throw new RuntimeException('Unable to replace the cache file.');
    }
}

function parseDrmHeaders(string $input): array
{
    $headers = [];
    foreach (preg_split('/\R/', $input) ?: [] as $line) {
        $line = trim($line);
        if ($line === '') {
            continue;
        }
        if (!str_contains($line, ':')) {
            throw new RuntimeException('Each DRM header must use the Name: value format.');
        }
        [$name, $value] = explode(':', $line, 2);
        $name = trim($name);
        if ($name === '') {
            throw new RuntimeException('DRM header names cannot be empty.');
        }
        $headers[$name] = trim($value);
    }
    return $headers;
}

function channelDrmSettings(array $channel): array
{
    $type = strtolower(trim((string) ($channel['drm_type'] ?? '')));
    $license = trim((string) ($channel['license_key_url'] ?? ''));
    $keyId = trim((string) ($channel['drm_key_id'] ?? ''));
    $key = trim((string) ($channel['drm_key'] ?? ''));
    $properties = json_decode((string) ($channel['properties_json'] ?? ''), true);
    if (!is_array($properties)) {
        $properties = [];
    }

    if ($type === '') {
        $licenseType = strtolower(trim((string) ($properties['inputstream.adaptive.license_type'] ?? '')));
        $type = match ($licenseType) {
            'com.widevine.alpha' => 'widevine',
            'com.microsoft.playready' => 'playready',
            'org.w3.clearkey', 'clearkey' => 'clearkey',
            default => '',
        };
    }

    if ($license === '' && isset($properties['inputstream.adaptive.license_key']) && is_string($properties['inputstream.adaptive.license_key'])) {
        $licenseValue = trim($properties['inputstream.adaptive.license_key']);
        if (str_starts_with($licenseValue, 'http://') || str_starts_with($licenseValue, 'https://')) {
            $license = explode('|', $licenseValue, 2)[0];
        } elseif ($type === 'clearkey' && str_contains($licenseValue, ':')) {
            [$propertyKeyId, $propertyKey] = explode(':', $licenseValue, 2);
            if ($keyId === '') {
                $keyId = trim($propertyKeyId);
            }
            if ($key === '') {
                $key = trim($propertyKey);
            }
        }
    }

    if (!in_array($type, ['', 'clearkey', 'widevine', 'playready'], true)) {
        $type = '';
    }

    return ['type' => $type, 'license' => $license, 'key_id' => $keyId, 'key' => $key];
}

function channelHasDrm(array $channel): bool
{
    $settings = channelDrmSettings($channel);
    return $settings['type'] !== ''
        || $settings['license'] !== ''
        || $settings['key_id'] !== ''
        || $settings['key'] !== '';
}

function feedVisibilityOptions(): array
{
    return ['playlist', 'clear', 'both'];
}

function normalizeFeedVisibility(?string $value): string
{
    $visibility = strtolower(trim((string) $value));
    if (!in_array($visibility, feedVisibilityOptions(), true)) {
        throw new RuntimeException('Unknown feed visibility. Choose playlist, clear, or both.');
    }
    return $visibility;
}

function feedsForChannel(PDO $db, int $channelId): array
{
    $statement = $db->prepare('SELECT * FROM feeds WHERE channel_id = :channel_id ORDER BY sort_order, id');
    $statement->execute(['channel_id' => $channelId]);
    return $statement->fetchAll();
}

function insertFeed(PDO $db, int $channelId, array $feed): int
{
    $statement = $db->prepare(
        'INSERT INTO feeds (channel_id, label, stream_url, license_key_url, drm_type, drm_key_id, drm_key, '
        . 'drm_headers, properties_json, visibility, enabled, sort_order, updated_at) '
        . 'VALUES (:channel_id, :label, :stream_url, :license_key_url, :drm_type, :drm_key_id, :drm_key, '
        . ':drm_headers, :properties, :visibility, :enabled, :sort_order, :updated_at)'
    );
    $statement->execute([
        'channel_id' => $channelId,
        'label' => (string) ($feed['label'] ?? ''),
        'stream_url' => (string) ($feed['stream_url'] ?? ''),
        'license_key_url' => (string) ($feed['license'] ?? ''),
        'drm_type' => (string) ($feed['drm']['type'] ?? ''),
        'drm_key_id' => (string) ($feed['drm']['key_id'] ?? ''),
        'drm_key' => (string) ($feed['drm']['key'] ?? ''),
        'drm_headers' => (string) ($feed['drm']['headers'] ?? ''),
        'properties' => (string) (json_encode((object) ($feed['properties'] ?? []), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}'),
        'visibility' => normalizeFeedVisibility((string) ($feed['visibility'] ?? 'both')),
        'enabled' => (int) ($feed['enabled'] ?? 1),
        'sort_order' => (int) ($feed['sort_order'] ?? 0),
        'updated_at' => gmdate('Y-m-d H:i:s'),
    ]);
    return (int) $db->lastInsertId();
}

function updateFeed(PDO $db, int $feedId, array $feed): void
{
    $statement = $db->prepare(
        'UPDATE feeds SET label = :label, stream_url = :stream_url, license_key_url = :license_key_url, '
        . 'drm_type = :drm_type, drm_key_id = :drm_key_id, drm_key = :drm_key, drm_headers = :drm_headers, '
        . 'properties_json = :properties, visibility = :visibility, updated_at = :updated_at '
        . 'WHERE id = :id'
    );
    $statement->execute([
        'label' => (string) ($feed['label'] ?? ''),
        'stream_url' => (string) ($feed['stream_url'] ?? ''),
        'license_key_url' => (string) ($feed['license'] ?? ''),
        'drm_type' => (string) ($feed['drm']['type'] ?? ''),
        'drm_key_id' => (string) ($feed['drm']['key_id'] ?? ''),
        'drm_key' => (string) ($feed['drm']['key'] ?? ''),
        'drm_headers' => (string) ($feed['drm']['headers'] ?? ''),
        'properties' => (string) (json_encode((object) ($feed['properties'] ?? []), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}'),
        'visibility' => normalizeFeedVisibility((string) ($feed['visibility'] ?? 'both')),
        'updated_at' => gmdate('Y-m-d H:i:s'),
        'id' => $feedId,
    ]);
}

function drmPlaylistProperties(array $channel): array
{
    $type = strtolower(trim((string) ($channel['drm_type'] ?? '')));
    $types = [
        'clearkey' => 'org.w3.clearkey',
        'widevine' => 'com.widevine.alpha',
        'playready' => 'com.microsoft.playready',
    ];
    if (!isset($types[$type])) {
        return [];
    }

    $license = trim((string) ($channel['license_key_url'] ?? ''));
    $keyId = trim((string) ($channel['drm_key_id'] ?? ''));
    $key = trim((string) ($channel['drm_key'] ?? ''));
    $properties = ['inputstream.adaptive.license_type' => $types[$type]];

    if ($type === 'clearkey' && $license === '' && $keyId !== '' && $key !== '') {
        $properties['inputstream.adaptive.license_key'] = $keyId . ':' . $key;
    } elseif ($license !== '') {
        $headerPairs = [];
        try {
            foreach (parseDrmHeaders((string) ($channel['drm_headers'] ?? '')) as $name => $value) {
                $headerPairs[] = rawurlencode($name) . '=' . rawurlencode($value);
            }
        } catch (RuntimeException) {
            $headerPairs = [];
        }
        $properties['inputstream.adaptive.license_key'] = $license
            . ($headerPairs ? '|' . implode('&', $headerPairs) : '');
    }

    return $properties;
}

function buildPlaylist(PDO $db, bool $clearOnly = false): string
{
    $epgUrl = $db->query("SELECT value FROM app_meta WHERE name = 'epg_url'")->fetchColumn();
    $visibility = $clearOnly ? 'clear' : 'playlist';
    $statement = $db->prepare(
        'SELECT c.id AS channel_id, c.name AS channel_name, c.tvg_id, c.tvg_name, c.tvg_language, c.tvg_type, '
        . 'c.group_title, c.logo_url, f.label AS feed_label, f.stream_url, f.properties_json, '
        . 'f.license_key_url, f.drm_type, f.drm_key_id, f.drm_key, f.drm_headers '
        . 'FROM feeds f JOIN channels c ON c.id = f.channel_id '
        . 'WHERE c.enabled = 1 AND f.enabled = 1 AND (f.visibility = \'both\' OR f.visibility = :visibility) '
        . 'ORDER BY c.group_title COLLATE NOCASE, c.name COLLATE NOCASE, f.sort_order, f.id'
    );
    $statement->execute(['visibility' => $visibility]);
    $feeds = $statement->fetchAll();

    $feedCounts = [];
    foreach ($feeds as $feed) {
        $channelId = (int) $feed['channel_id'];
        $feedCounts[$channelId] = ($feedCounts[$channelId] ?? 0) + 1;
    }

    $headerAttributes = [];
    if (is_string($epgUrl) && $epgUrl !== '') {
        $headerAttributes[] = 'x-tvg-url="' . m3uEscape($epgUrl) . '"';
    }
    $output = '#EXTM3U' . ($headerAttributes ? ' ' . implode(' ', $headerAttributes) : '') . "\n";

    $emittedCounts = [];
    foreach ($feeds as $feed) {
        $channelId = (int) $feed['channel_id'];
        $position = ($emittedCounts[$channelId] ?? 0) + 1;
        $emittedCounts[$channelId] = $position;

        $name = (string) $feed['channel_name'];
        if ($feedCounts[$channelId] > 1) {
            $label = trim((string) $feed['feed_label']);
            $name .= ' (' . ($label !== '' ? $label : 'Feed ' . $position) . ')';
        }

        $attributes = [
            'tvg-id' => $feed['tvg_id'],
            'tvg-name' => $feed['tvg_name'],
            'tvg-logo' => $feed['logo_url'],
            'tvg-language' => $feed['tvg_language'],
            'tvg-type' => $feed['tvg_type'],
            'group-title' => $feed['group_title'],
        ];
        $formattedAttributes = [];
        foreach ($attributes as $attrName => $value) {
            if ($value !== '') {
                $formattedAttributes[] = $attrName . '="' . m3uEscape($value) . '"';
            }
        }
        $output .= '#EXTINF:-1 ' . implode(' ', $formattedAttributes) . ',' . $name . "\n";

        $properties = json_decode($feed['properties_json'], true);
        if (!is_array($properties)) {
            $properties = [];
        }
        $properties = array_merge($properties, drmPlaylistProperties($feed));
        foreach ($properties as $propertyName => $value) {
            if (is_string($propertyName) && is_string($value) && !str_contains($propertyName, "\n") && !str_contains($value, "\n")) {
                $output .= '#KODIPROP:' . $propertyName . '=' . $value . "\n";
            }
        }
        $output .= $feed['stream_url'] . "\n";
    }

    return $output;
}

function cachedPlaylist(PDO $db, bool $clearOnly = false): array
{
    $signature = databaseSignature() . ':' . cacheVersion($db) . ($clearOnly ? ':clear-v2' : ':all-v2');
    $contentFile = null;
    $metaFile = null;

    try {
        $directory = cacheDirectory();
        $contentFile = $directory . '/' . ($clearOnly ? 'playlist-clear.m3u' : 'playlist.m3u');
        $metaFile = $directory . '/' . ($clearOnly ? 'playlist-clear.meta.json' : 'playlist.meta.json');
        if (is_file($contentFile) && is_file($metaFile)) {
            $meta = json_decode((string) file_get_contents($metaFile), true);
            $content = file_get_contents($contentFile);
            if (is_array($meta) && is_string($content) && ($meta['signature'] ?? '') === $signature) {
                return [
                    'content' => $content,
                    'generated_at' => (int) ($meta['generated_at'] ?? filemtime($contentFile)),
                    'cached' => true,
                ];
            }
        }
    } catch (Throwable) {
        // Fall through and rebuild when the cache is unavailable.
    }

    $content = buildPlaylist($db, $clearOnly);
    $generatedAt = time();

    if ($contentFile !== null && $metaFile !== null) {
        try {
            writeCacheFile($contentFile, $content);
            writeCacheFile($metaFile, (string) json_encode([
                'signature' => $signature,
                'generated_at' => $generatedAt,
            ]));
        } catch (Throwable) {
            // Serving fresh playlist data matters more than writing the cache.
        }
    }

    return [
        'content' => $content,
        'generated_at' => $generatedAt,
        'cached' => false,
    ];
}

function m3uEscape(string $value): string
{
    return str_replace(['\\', '"', "\r", "\n"], ['\\\\', '\\"', '', ''], $value);
}

function normalizePlaylistUrl(string $url): string
{
    $url = trim($url);
    $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
    if ($url === '' || filter_var($url, FILTER_VALIDATE_URL) === false || !in_array($scheme, ['http', 'https'], true)) {
        throw new RuntimeException('Enter a valid http or https playlist URL.');
    }
    return $url;
}

function maybeDecompressGzip(string $body): string
{
    if (!str_starts_with($body, "\x1f\x8b")) {
        return $body;
    }
    $decoded = @gzdecode($body);
    if ($decoded === false) {
        throw new RuntimeException('Unable to decompress the gzip playlist.');
    }
    return $decoded;
}

function downloadPlaylist(string $url): string
{
    $url = normalizePlaylistUrl($url);

    $limit = 50 * 1024 * 1024;
    $tooLarge = false;
    $curl = curl_init($url);
    if ($curl === false) {
        throw new RuntimeException('Unable to start the playlist download.');
    }

    $options = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 5,
        CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_TIMEOUT => 120,
        CURLOPT_USERAGENT => 'IPTV-Manager/1.0',
        CURLOPT_FAILONERROR => true,
        CURLOPT_ENCODING => '',
        CURLOPT_NOPROGRESS => false,
        CURLOPT_PROGRESSFUNCTION => static function ($resource, $downloadSize, $downloaded) use ($limit, &$tooLarge): int {
            if ($downloaded > $limit) {
                $tooLarge = true;
                return 1;
            }
            return 0;
        },
    ];
    if (defined('CURLOPT_PROTOCOLS_STR')) {
        $options[CURLOPT_PROTOCOLS_STR] = 'http,https';
        $options[CURLOPT_REDIR_PROTOCOLS_STR] = 'http,https';
    }
    curl_setopt_array($curl, $options);
    $body = curl_exec($curl);
    $error = curl_error($curl);
    $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    curl_close($curl);

    if ($tooLarge) {
        throw new RuntimeException('The playlist file is larger than 50 MB.');
    }
    if ($body === false) {
        throw new RuntimeException('Unable to download the playlist: ' . ($error !== '' ? $error : 'request failed'));
    }
    if ($status < 200 || $status >= 300) {
        throw new RuntimeException('The playlist server returned HTTP ' . $status . '.');
    }

    $body = maybeDecompressGzip((string) $body);
    if (trim($body) === '') {
        throw new RuntimeException('The downloaded playlist was empty.');
    }
    return $body;
}

function nameFromStreamUrl(string $url): string
{
    $path = parse_url($url, PHP_URL_PATH);
    $name = is_string($path) ? basename($path) : '';
    return $name !== '' ? $name : $url;
}

function parseExtinfLine(string $line): array
{
    $entry = ['name' => '', 'attributes' => [], 'group_title' => '', 'properties' => [], 'stream_url' => ''];
    $payload = substr($line, strlen('#EXTINF:'));

    $length = strlen($payload);
    $inQuotes = false;
    $escaped = false;
    $attributeEnd = null;
    for ($position = 0; $position < $length; $position++) {
        $character = $payload[$position];
        if ($escaped) {
            $escaped = false;
        } elseif ($character === '\\' && $inQuotes) {
            $escaped = true;
        } elseif ($character === '"') {
            $inQuotes = !$inQuotes;
        } elseif ($character === ',' && !$inQuotes) {
            $attributeEnd = $position;
            break;
        }
    }

    if ($attributeEnd === null) {
        $entry['name'] = trim($payload);
        return $entry;
    }

    $attributePart = substr($payload, 0, $attributeEnd);
    $entry['name'] = trim(substr($payload, $attributeEnd + 1));
    if (preg_match_all('/([\w.-]+)=("(?:[^"\\\\]|\\\\.)*"|[^"\s]+)/', $attributePart, $matches, PREG_SET_ORDER)) {
        foreach ($matches as $match) {
            $value = $match[2];
            if (strlen($value) >= 2 && $value[0] === '"' && $value[strlen($value) - 1] === '"') {
                $value = str_replace(['\\"', '\\\\'], ['"', '\\'], substr($value, 1, -1));
            }
            $entry['attributes'][$match[1]] = $value;
        }
    }
    $entry['group_title'] = (string) ($entry['attributes']['group-title'] ?? '');
    return $entry;
}

function parseM3uPlaylist(string $content): array
{
    $content = ltrim($content, "\xEF\xBB\xBF");
    $entries = [];
    $pending = null;

    foreach (preg_split('/\R/', $content) ?: [] as $rawLine) {
        $line = trim($rawLine);
        if ($line === '') {
            continue;
        }

        if (str_starts_with($line, '#EXTINF:')) {
            if ($pending !== null && $pending['stream_url'] !== '') {
                $entries[] = $pending;
            }
            $pending = parseExtinfLine($line);
            continue;
        }

        if ($line[0] === '#') {
            if ($pending === null) {
                continue;
            }
            if (stripos($line, '#KODIPROP:') === 0) {
                $pair = substr($line, strlen('#KODIPROP:'));
                $separator = strpos($pair, '=');
                if ($separator !== false) {
                    $name = trim(substr($pair, 0, $separator));
                    if ($name !== '') {
                        $pending['properties'][$name] = trim(substr($pair, $separator + 1));
                    }
                }
                continue;
            }
            if (stripos($line, '#EXTGRP:') === 0) {
                $group = trim(substr($line, strlen('#EXTGRP:')));
                if ($group !== '' && $pending['group_title'] === '') {
                    $pending['group_title'] = $group;
                }
            }
            continue;
        }

        if ($pending === null) {
            $pending = ['name' => '', 'attributes' => [], 'group_title' => '', 'properties' => [], 'stream_url' => ''];
        }
        if ($pending['stream_url'] === '') {
            $pending['stream_url'] = $line;
            if ($pending['name'] === '') {
                $pending['name'] = nameFromStreamUrl($line);
            }
            $entries[] = $pending;
            $pending = null;
        }
    }
    if ($pending !== null && $pending['stream_url'] !== '') {
        $entries[] = $pending;
    }

    return $entries;
}

function drmFieldsFromProperties(array $properties): array
{
    $channel = [
        'properties_json' => (string) json_encode((object) $properties),
        'license_key_url' => '',
        'drm_type' => '',
        'drm_key_id' => '',
        'drm_key' => '',
        'drm_headers' => '',
    ];
    $settings = channelDrmSettings($channel);

    $headers = '';
    $licenseProperty = $properties['inputstream.adaptive.license_key'] ?? '';
    if (is_string($licenseProperty)) {
        $segments = explode('|', $licenseProperty);
        if (isset($segments[1]) && $segments[1] !== '') {
            parse_str($segments[1], $parsed);
            $lines = [];
            foreach ($parsed as $name => $value) {
                if (is_string($name) && is_string($value) && $name !== '') {
                    $lines[] = $name . ': ' . $value;
                }
            }
            $headers = implode("\n", $lines);
        }
    }

    return [
        'type' => $settings['type'],
        'license' => $settings['license'],
        'key_id' => $settings['key_id'],
        'key' => $settings['key'],
        'headers' => $headers,
    ];
}

function importPlaylist(PDO $db, string $content): array
{
    $entries = parseM3uPlaylist($content);
    if ($entries === []) {
        throw new RuntimeException('No channels found in the playlist. Expected an M3U playlist with #EXTINF entries.');
    }

    $findChannelBySourceId = $db->prepare('SELECT id FROM channels WHERE source_id = :source_id');
    $findChannelsByName = $db->prepare('SELECT id FROM channels WHERE name = :name');
    $insertChannel = $db->prepare(
        'INSERT INTO channels (source_id, name, tvg_id, tvg_name, group_title, tvg_language, tvg_type, '
        . 'logo_url, attributes_json, properties_json, url_fields_json, enabled, updated_at) '
        . 'VALUES (:source_id, :name, :tvg_id, :tvg_name, :group_title, :language, :type, '
        . ':logo_url, :attributes, :properties, :url_fields, :enabled, :updated_at) '
        . 'ON CONFLICT(source_id) DO UPDATE SET '
        . 'name = excluded.name, tvg_id = excluded.tvg_id, tvg_name = excluded.tvg_name, '
        . 'group_title = excluded.group_title, tvg_language = excluded.tvg_language, tvg_type = excluded.tvg_type, '
        . 'logo_url = excluded.logo_url, attributes_json = excluded.attributes_json, '
        . 'updated_at = excluded.updated_at'
    );
    $insertFeed = $db->prepare(
        'INSERT INTO feeds (channel_id, label, stream_url, license_key_url, drm_type, drm_key_id, drm_key, '
        . 'drm_headers, properties_json, visibility, enabled, sort_order, updated_at) '
        . "VALUES (:channel_id, '', :stream_url, :license_key_url, :drm_type, :drm_key_id, :drm_key, "
        . ':drm_headers, :properties, :visibility, 1, :sort_order, :updated_at) '
        . 'ON CONFLICT(channel_id, stream_url) DO UPDATE SET '
        . 'license_key_url = excluded.license_key_url, drm_type = excluded.drm_type, '
        . 'drm_key_id = excluded.drm_key_id, drm_key = excluded.drm_key, drm_headers = excluded.drm_headers, '
        . 'properties_json = excluded.properties_json, updated_at = excluded.updated_at'
    );
    $updateChannel = $db->prepare(
        'UPDATE channels SET name = :name, tvg_id = :tvg_id, tvg_name = :tvg_name, '
        . 'group_title = :group_title, tvg_language = :language, tvg_type = :type, '
        . 'logo_url = :logo_url, attributes_json = :attributes, updated_at = :updated_at '
        . 'WHERE id = :id'
    );

    $groups = [];
    $skipped = 0;
    foreach ($entries as $entry) {
        $streamUrl = trim($entry['stream_url']);
        $name = cleanGuideText($entry['name'] !== '' ? $entry['name'] : nameFromStreamUrl($streamUrl));
        if ($streamUrl === '' || $name === '' || preg_match('#^[a-z][a-z0-9+.\-]*://#i', $streamUrl) !== 1) {
            $skipped++;
            continue;
        }
        $groups[$name][] = ['entry' => $entry, 'stream_url' => $streamUrl];
    }
    if ($groups === []) {
        throw new RuntimeException('No importable channels were found in the playlist.');
    }

    $added = 0;
    $updated = 0;
    $now = gmdate('Y-m-d H:i:s');

    $db->beginTransaction();
    try {
        foreach ($groups as $name => $group) {
            $firstEntry = $group[0]['entry'];

            $channelId = null;
            $canonicalSourceId = 'm3u-' . sha1($name);
            $findChannelBySourceId->execute(['source_id' => $canonicalSourceId]);
            $canonicalMatch = $findChannelBySourceId->fetchColumn();
            if ($canonicalMatch) {
                $channelId = (int) $canonicalMatch;
            }
            if ($channelId === null) {
                foreach ($group as $item) {
                    $findChannelBySourceId->execute(['source_id' => 'm3u-' . sha1($name . "\0" . $item['stream_url'])]);
                    $match = $findChannelBySourceId->fetchColumn();
                    if ($match) {
                        $channelId = (int) $match;
                        break;
                    }
                }
            }
            if ($channelId === null) {
                $findChannelsByName->execute(['name' => $name]);
                $nameMatches = array_map(static fn (array $row): int => (int) $row['id'], $findChannelsByName->fetchAll());
                if (count($nameMatches) === 1) {
                    $channelId = $nameMatches[0];
                }
            }

            $channelProperties = json_encode((object) $firstEntry['properties'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}';
            $channelAttributes = (string) (json_encode((object) $firstEntry['attributes'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}');

            if ($channelId === null) {
                $insertChannel->execute([
                    'source_id' => $canonicalSourceId,
                    'name' => $name,
                    'tvg_id' => (string) ($firstEntry['attributes']['tvg-id'] ?? ''),
                    'tvg_name' => (string) ($firstEntry['attributes']['tvg-name'] ?? ''),
                    'group_title' => $firstEntry['group_title'],
                    'language' => (string) ($firstEntry['attributes']['tvg-language'] ?? ''),
                    'type' => (string) ($firstEntry['attributes']['tvg-type'] ?? ''),
                    'logo_url' => (string) ($firstEntry['attributes']['tvg-logo'] ?? ''),
                    'attributes' => $channelAttributes,
                    'properties' => $channelProperties,
                    'url_fields' => '{}',
                    'enabled' => 1,
                    'updated_at' => $now,
                ]);
                $channelId = (int) $db->lastInsertId();
                $added++;
            } else {
                $updateChannel->execute([
                    'name' => $name,
                    'tvg_id' => (string) ($firstEntry['attributes']['tvg-id'] ?? ''),
                    'tvg_name' => (string) ($firstEntry['attributes']['tvg-name'] ?? ''),
                    'group_title' => $firstEntry['group_title'],
                    'language' => (string) ($firstEntry['attributes']['tvg-language'] ?? ''),
                    'type' => (string) ($firstEntry['attributes']['tvg-type'] ?? ''),
                    'logo_url' => (string) ($firstEntry['attributes']['tvg-logo'] ?? ''),
                    'attributes' => $channelAttributes,
                    'updated_at' => $now,
                    'id' => $channelId,
                ]);
                $updated++;
            }

            foreach ($group as $position => $item) {
                $entry = $item['entry'];
                $drm = drmFieldsFromProperties($entry['properties']);
                $insertFeed->execute([
                    'channel_id' => $channelId,
                    'stream_url' => $item['stream_url'],
                    'license_key_url' => $drm['license'],
                    'drm_type' => $drm['type'],
                    'drm_key_id' => $drm['key_id'],
                    'drm_key' => $drm['key'],
                    'drm_headers' => $drm['headers'],
                    'properties' => (string) (json_encode((object) $entry['properties'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}'),
                    'visibility' => 'both',
                    'sort_order' => $position,
                    'updated_at' => $now,
                ]);
            }
        }
        $db->commit();
    } catch (Throwable $error) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        throw $error;
    }

    if ($added === 0 && $updated === 0) {
        throw new RuntimeException('No importable channels were found in the playlist.');
    }

    return ['added' => $added, 'updated' => $updated, 'skipped' => $skipped, 'total' => count($entries)];
}

function normalizeEpgUrl(string $url): string
{
    $url = trim($url);
    $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
    if ($url === '' || filter_var($url, FILTER_VALIDATE_URL) === false || !in_array($scheme, ['http', 'https'], true)) {
        throw new RuntimeException('Enter a valid http or https XMLTV guide URL.');
    }
    return $url;
}

function importEpg(PDO $db, string $url): array
{
    $url = normalizeEpgUrl($url);

    $download = tempnam(sys_get_temp_dir(), 'iptv-guide-');
    if ($download === false) {
        throw new RuntimeException('Unable to create a temporary guide file.');
    }
    $xmlFile = $download . '.xml';

    try {
        downloadEpg($url, $download);
        clearstatcache(true, $download);
        $handle = fopen($download, 'rb');
        $magic = $handle !== false ? (string) fread($handle, 2) : '';
        if ($handle !== false) {
            fclose($handle);
        }

        if ($magic === "\x1f\x8b") {
            decompressEpgGzip($download, $xmlFile);
        } elseif (!rename($download, $xmlFile) && !copy($download, $xmlFile)) {
            throw new RuntimeException('Unable to prepare the guide file.');
        }

        clearstatcache(true, $xmlFile);
        if (!is_file($xmlFile) || filesize($xmlFile) === 0) {
            throw new RuntimeException('The downloaded guide file is empty.');
        }

        $epgChannels = collectEpgChannels($xmlFile);
        $result = importEpgProgrammes($db, $xmlFile, $epgChannels);
        setMetaValue($db, 'epg_last_import', (string) time());
        setMetaValue($db, 'epg_program_count', (string) $result['programs']);
        setMetaValue($db, 'epg_channel_count', (string) $result['channels']);

        return [
            'programs' => $result['programs'],
            'channels' => $result['channels'],
            'source_channels' => count($epgChannels),
        ];
    } finally {
        @unlink($download);
        @unlink($xmlFile);
    }
}

function downloadEpg(string $url, string $destination): void
{
    $handle = fopen($destination, 'wb');
    if ($handle === false) {
        throw new RuntimeException('Unable to write the guide download.');
    }

    $limit = 300 * 1024 * 1024;
    $tooLarge = false;
    $curl = curl_init($url);
    if ($curl === false) {
        fclose($handle);
        throw new RuntimeException('Unable to start the guide download.');
    }

    $options = [
        CURLOPT_FILE => $handle,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 5,
        CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_TIMEOUT => 180,
        CURLOPT_USERAGENT => 'IPTV-Manager/1.0',
        CURLOPT_FAILONERROR => true,
        CURLOPT_ENCODING => '',
        CURLOPT_NOPROGRESS => false,
        CURLOPT_PROGRESSFUNCTION => static function ($resource, $downloadSize, $downloaded) use ($limit, &$tooLarge): int {
            if ($downloaded > $limit) {
                $tooLarge = true;
                return 1;
            }
            return 0;
        },
    ];
    if (defined('CURLOPT_PROTOCOLS_STR')) {
        $options[CURLOPT_PROTOCOLS_STR] = 'http,https';
        $options[CURLOPT_REDIR_PROTOCOLS_STR] = 'http,https';
    }
    curl_setopt_array($curl, $options);
    $success = curl_exec($curl);
    $error = curl_error($curl);
    $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    curl_close($curl);
    fclose($handle);

    if ($tooLarge) {
        throw new RuntimeException('The guide file is larger than 300 MB.');
    }
    if ($success === false) {
        throw new RuntimeException('Unable to download the guide: ' . ($error !== '' ? $error : 'request failed'));
    }
    if ($status < 200 || $status >= 300) {
        throw new RuntimeException('The guide server returned HTTP ' . $status . '.');
    }

    clearstatcache(true, $destination);
    if (!is_file($destination) || filesize($destination) === 0) {
        throw new RuntimeException('The guide download was empty.');
    }
}

function decompressEpgGzip(string $source, string $destination): void
{
    $input = gzopen($source, 'rb');
    $output = fopen($destination, 'wb');
    if ($input === false || $output === false) {
        if ($input !== false) {
            gzclose($input);
        }
        if ($output !== false) {
            fclose($output);
        }
        throw new RuntimeException('Unable to decompress the guide file.');
    }

    $limit = 512 * 1024 * 1024;
    $written = 0;
    try {
        while (!gzeof($input)) {
            $chunk = gzread($input, 1048576);
            if ($chunk === false) {
                throw new RuntimeException('Unable to decompress the guide file.');
            }
            if ($chunk === '') {
                break;
            }
            $written += strlen($chunk);
            if ($written > $limit) {
                throw new RuntimeException('The decompressed guide file is larger than 512 MB.');
            }
            if (fwrite($output, $chunk) === false) {
                throw new RuntimeException('Unable to write the decompressed guide file.');
            }
        }
    } finally {
        gzclose($input);
        fclose($output);
    }
}

function collectEpgChannels(string $file): array
{
    $previous = libxml_use_internal_errors(true);
    libxml_clear_errors();
    $reader = new XMLReader();
    if (!$reader->open($file, null, LIBXML_NONET | LIBXML_COMPACT)) {
        libxml_use_internal_errors($previous);
        throw new RuntimeException('Unable to read the guide file.');
    }

    $channels = [];
    $rootSeen = false;
    try {
        while ($reader->read()) {
            if ($reader->nodeType !== XMLReader::ELEMENT) {
                continue;
            }
            if ($reader->name === 'tv') {
                $rootSeen = true;
                continue;
            }
            if ($reader->name !== 'channel') {
                continue;
            }
            $id = trim((string) $reader->getAttribute('id'));
            if ($id === '') {
                continue;
            }
            $names = [];
            $outer = $reader->readOuterXml();
            if ($outer !== '') {
                $node = simplexml_load_string($outer, 'SimpleXMLElement', LIBXML_NONET | LIBXML_NOCDATA);
                if ($node !== false) {
                    foreach ($node->{'display-name'} as $displayName) {
                        $name = cleanGuideText((string) $displayName);
                        if ($name !== '') {
                            $names[] = $name;
                        }
                    }
                }
            }
            $channels[$id] = $names;
        }

        $errors = array_values(array_filter(
            libxml_get_errors(),
            static fn (LibXMLError $error): bool => $error->level >= LIBXML_ERR_ERROR
        ));
        if ($errors !== []) {
            throw new RuntimeException('The guide file is not valid XMLTV: ' . trim($errors[0]->message));
        }
    } finally {
        $reader->close();
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
    }

    if (!$rootSeen) {
        throw new RuntimeException('The downloaded file is not an XMLTV guide.');
    }

    return $channels;
}

function importEpgProgrammes(PDO $db, string $file, array $epgChannels): array
{
    $mapping = buildEpgChannelMap($db, $epgChannels);
    if ($mapping === []) {
        throw new RuntimeException('No channels matched the guide. Check the TVG IDs and channel names.');
    }

    $previous = libxml_use_internal_errors(true);
    libxml_clear_errors();
    $reader = new XMLReader();
    if (!$reader->open($file, null, LIBXML_NONET | LIBXML_COMPACT)) {
        libxml_use_internal_errors($previous);
        throw new RuntimeException('Unable to read the guide file.');
    }

    $db->beginTransaction();
    try {
        $db->exec('DELETE FROM epg_programs');
        $insert = $db->prepare(
            'INSERT INTO epg_programs (channel_id, epg_channel_id, start_ts, stop_ts, title, description, category) '
            . 'VALUES (:channel_id, :epg_channel_id, :start_ts, :stop_ts, :title, :description, :category)'
        );

        $inserted = 0;
        while ($reader->read()) {
            if ($reader->nodeType !== XMLReader::ELEMENT || $reader->name !== 'programme') {
                continue;
            }
            $epgId = trim((string) $reader->getAttribute('channel'));
            $channelId = $mapping[$epgId] ?? null;
            if ($channelId === null) {
                continue;
            }
            $start = parseGuideTime((string) $reader->getAttribute('start'));
            if ($start === null) {
                continue;
            }
            $stop = parseGuideTime((string) $reader->getAttribute('stop'));
            if ($stop === null || $stop <= $start) {
                $stop = $start + 1800;
            }
            $outer = $reader->readOuterXml();
            if ($outer === '') {
                continue;
            }
            $node = simplexml_load_string($outer, 'SimpleXMLElement', LIBXML_NONET | LIBXML_NOCDATA);
            if ($node === false) {
                continue;
            }
            $title = cleanGuideText((string) $node->title);
            if ($title === '') {
                $title = 'Untitled programme';
            }
            $insert->execute([
                'channel_id' => $channelId,
                'epg_channel_id' => $epgId,
                'start_ts' => $start,
                'stop_ts' => $stop,
                'title' => $title,
                'description' => cleanGuideText((string) $node->desc),
                'category' => cleanGuideText((string) $node->category),
            ]);
            $inserted++;
        }

        if ($inserted === 0) {
            throw new RuntimeException('No programmes matched your channels. Check the guide source and TVG IDs.');
        }

        $db->prepare('DELETE FROM epg_programs WHERE stop_ts < :cutoff')->execute(['cutoff' => time() - 86400]);
        $db->commit();
    } catch (Throwable $error) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        throw $error;
    } finally {
        $reader->close();
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
    }

    $programs = (int) $db->query('SELECT COUNT(*) FROM epg_programs')->fetchColumn();
    $channels = (int) $db->query('SELECT COUNT(DISTINCT channel_id) FROM epg_programs')->fetchColumn();

    return ['programs' => $programs, 'channels' => $channels];
}

function buildEpgChannelMap(PDO $db, array $epgChannels): array
{
    if ($epgChannels === []) {
        return [];
    }

    $epgIds = [];
    $nameMap = [];
    foreach ($epgChannels as $epgId => $names) {
        $epgId = (string) $epgId;
        $epgIds[$epgId] = true;
        $key = normalizeGuideName($epgId);
        if ($key !== '' && !isset($nameMap[$key])) {
            $nameMap[$key] = $epgId;
        }
        foreach ($names as $name) {
            $key = normalizeGuideName((string) $name);
            if ($key !== '' && !isset($nameMap[$key])) {
                $nameMap[$key] = $epgId;
            }
        }
    }

    $mapping = [];
    foreach ($db->query('SELECT id, tvg_id, tvg_name, name FROM channels ORDER BY id') as $channel) {
        $epgId = null;
        $tvgId = trim((string) $channel['tvg_id']);
        if ($tvgId !== '' && isset($epgIds[$tvgId])) {
            $epgId = $tvgId;
        } else {
            foreach ([$channel['tvg_name'], $channel['name']] as $candidate) {
                $key = normalizeGuideName((string) $candidate);
                if ($key !== '' && isset($nameMap[$key])) {
                    $epgId = $nameMap[$key];
                    break;
                }
            }
        }
        if ($epgId !== null && !isset($mapping[$epgId])) {
            $mapping[$epgId] = (int) $channel['id'];
        }
    }

    return $mapping;
}

function parseGuideTime(string $value): ?int
{
    $value = trim($value);
    if ($value === '') {
        return null;
    }
    foreach (['!YmdHis O', '!YmdHisO', '!YmdHis'] as $format) {
        $date = DateTimeImmutable::createFromFormat($format, $value);
        $errors = DateTimeImmutable::getLastErrors();
        if ($date instanceof DateTimeImmutable && ($errors === false || ($errors['warning_count'] === 0 && $errors['error_count'] === 0))) {
            return $date->getTimestamp();
        }
    }
    return null;
}

function cleanGuideText(string $text): string
{
    $text = trim($text);
    if ($text === '') {
        return '';
    }
    $collapsed = preg_replace('/\s+/u', ' ', $text);
    return is_string($collapsed) ? $collapsed : $text;
}

function normalizeGuideName(string $name): string
{
    $name = function_exists('mb_strtolower') ? mb_strtolower($name, 'UTF-8') : strtolower($name);
    $normalized = preg_replace('/[^\p{L}\p{N}]+/u', '', $name);
    return is_string($normalized) ? $normalized : '';
}

function clearEpg(PDO $db): int
{
    $removed = (int) $db->exec('DELETE FROM epg_programs');
    setMetaValue($db, 'epg_last_import', '0');
    setMetaValue($db, 'epg_program_count', '0');
    setMetaValue($db, 'epg_channel_count', '0');
    return $removed;
}
