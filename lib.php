<?php
declare(strict_types=1);

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
        CREATE INDEX IF NOT EXISTS epg_programs_stop_idx ON epg_programs(stop_ts);'
    );

    $statement = $db->prepare('INSERT OR IGNORE INTO admins (username, password_hash) VALUES (?, ?)');
    $statement->execute(['admin', password_hash('admin', PASSWORD_DEFAULT)]);

    ensureChannelColumn($db, 'drm_type', "TEXT NOT NULL DEFAULT ''");
    ensureChannelColumn($db, 'drm_key_id', "TEXT NOT NULL DEFAULT ''");
    ensureChannelColumn($db, 'drm_key', "TEXT NOT NULL DEFAULT ''");
    ensureChannelColumn($db, 'drm_headers', "TEXT NOT NULL DEFAULT ''");
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

function buildPlaylist(PDO $db): string
{
    $epgUrl = $db->query("SELECT value FROM app_meta WHERE name = 'epg_url'")->fetchColumn();
    $channels = $db->query(
        'SELECT name, tvg_id, tvg_name, tvg_language, tvg_type, group_title, logo_url, stream_url, properties_json, '
        . 'license_key_url, drm_type, drm_key_id, drm_key, drm_headers '
        . 'FROM channels WHERE enabled = 1 ORDER BY group_title COLLATE NOCASE, name COLLATE NOCASE'
    )->fetchAll();

    $headerAttributes = [];
    if (is_string($epgUrl) && $epgUrl !== '') {
        $headerAttributes[] = 'x-tvg-url="' . m3uEscape($epgUrl) . '"';
    }
    $output = '#EXTM3U' . ($headerAttributes ? ' ' . implode(' ', $headerAttributes) : '') . "\n";

    foreach ($channels as $channel) {
        $attributes = [
            'tvg-id' => $channel['tvg_id'],
            'tvg-name' => $channel['tvg_name'],
            'tvg-logo' => $channel['logo_url'],
            'tvg-language' => $channel['tvg_language'],
            'tvg-type' => $channel['tvg_type'],
            'group-title' => $channel['group_title'],
        ];
        $formattedAttributes = [];
        foreach ($attributes as $name => $value) {
            if ($value !== '') {
                $formattedAttributes[] = $name . '="' . m3uEscape($value) . '"';
            }
        }
        $output .= '#EXTINF:-1 ' . implode(' ', $formattedAttributes) . ',' . $channel['name'] . "\n";

        $properties = json_decode($channel['properties_json'], true);
        if (!is_array($properties)) {
            $properties = [];
        }
        $properties = array_merge($properties, drmPlaylistProperties($channel));
        foreach ($properties as $name => $value) {
            if (is_string($name) && is_string($value) && !str_contains($name, "\n") && !str_contains($value, "\n")) {
                $output .= '#KODIPROP:' . $name . '=' . $value . "\n";
            }
        }
        $output .= $channel['stream_url'] . "\n";
    }

    return $output;
}

function cachedPlaylist(PDO $db): array
{
    $signature = databaseSignature() . ':' . cacheVersion($db);
    $contentFile = null;
    $metaFile = null;

    try {
        $directory = cacheDirectory();
        $contentFile = $directory . '/playlist.m3u';
        $metaFile = $directory . '/playlist.meta.json';
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

    $content = buildPlaylist($db);
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
