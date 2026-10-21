<?php
declare(strict_types=1);

require_once __DIR__ . '/lib.php';

session_name('iptv_manager');
session_set_cookie_params([
    'httponly' => true,
    'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
    'samesite' => 'Lax',
]);
session_start();

$db = database();
initializeDatabase($db);

function redirect(string $path = 'index.php'): never
{
    header('Location: ' . $path);
    exit;
}

function flash(string $message, string $kind = 'success'): void
{
    $_SESSION['flash'] = ['message' => $message, 'kind' => $kind];
}

function requireCsrf(): void
{
    $posted = $_POST['csrf'] ?? '';
    if (!is_string($posted) || !hash_equals(csrfToken(), $posted)) {
        http_response_code(400);
        exit('Invalid request token. Reload the page and try again.');
    }
}

function parseChannelProperties(string $input): array
{
    $properties = [];
    $lines = preg_split('/\R/', $input) ?: [];
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '') {
            continue;
        }
        if (!str_contains($line, '=')) {
            throw new RuntimeException('Each KODIPROP line must use the name=value format.');
        }
        [$name, $value] = explode('=', $line, 2);
        $name = trim($name);
        if ($name === '') {
            throw new RuntimeException('KODIPROP names cannot be empty.');
        }
        $properties[$name] = trim($value);
    }
    return $properties;
}

function readDrmFields(array $source, string $licenseKeyUrl): array
{
    $type = strtolower(trim((string) ($source['drm_type'] ?? '')));
    if (!in_array($type, ['', 'clearkey', 'widevine', 'playready'], true)) {
        throw new RuntimeException('Unknown DRM type.');
    }

    $keyId = strtolower((string) preg_replace('/\s+/', '', (string) ($source['drm_key_id'] ?? '')));
    $key = strtolower((string) preg_replace('/\s+/', '', (string) ($source['drm_key'] ?? '')));
    if ($type !== 'clearkey') {
        $keyId = '';
        $key = '';
    }
    if (($keyId === '') !== ($key === '')) {
        throw new RuntimeException('Enter both the ClearKey key ID and key.');
    }
    if (($keyId !== '' && !preg_match('/^[0-9a-f]+$/', $keyId)) || ($key !== '' && !preg_match('/^[0-9a-f]+$/', $key))) {
        throw new RuntimeException('ClearKey IDs and keys must be hex-encoded.');
    }
    if (in_array($type, ['widevine', 'playready'], true) && $licenseKeyUrl === '') {
        throw new RuntimeException('A DRM license URL is required for Widevine or PlayReady.');
    }
    if ($type === 'clearkey' && $licenseKeyUrl === '' && ($keyId === '' || $key === '')) {
        throw new RuntimeException('Enter a ClearKey license URL or a key ID and key.');
    }

    $headers = trim((string) ($source['drm_headers'] ?? ''));
    parseDrmHeaders($headers);

    return ['type' => $type, 'key_id' => $keyId, 'key' => $key, 'headers' => $headers];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'login') {
        requireCsrf();
        $username = trim((string) ($_POST['username'] ?? ''));
        $password = (string) ($_POST['password'] ?? '');
        $statement = $db->prepare('SELECT password_hash FROM admins WHERE username = :username');
        $statement->execute(['username' => $username]);
        $admin = $statement->fetch();

        if ($admin && password_verify($password, $admin['password_hash'])) {
            session_regenerate_id(true);
            $_SESSION['admin'] = $username;
            flash('Welcome back.');
            redirect();
        }

        flash('Incorrect username or password.', 'error');
        redirect('index.php?login=1');
    }

    if ($action === 'logout') {
        requireCsrf();
        $_SESSION = [];
        session_destroy();
        redirect('index.php?login=1');
    }

    if (!isset($_SESSION['admin'])) {
        redirect('index.php?login=1');
    }
    requireCsrf();

    try {
        if ($action === 'purge_cache') {
            purgeCache();
            flash('Cache purged.');
        } elseif ($action === 'toggle') {
            $statement = $db->prepare('UPDATE channels SET enabled = CASE enabled WHEN 1 THEN 0 ELSE 1 END WHERE id = :id');
            $statement->execute(['id' => filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT)]);
            flash('Channel status updated.');
        } elseif ($action === 'delete') {
            $statement = $db->prepare('DELETE FROM channels WHERE id = :id');
            $statement->execute(['id' => filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT)]);
            flash('Channel deleted.');
        } elseif ($action === 'delete_selected') {
            $postedIds = $_POST['ids'] ?? [];
            $ids = [];
            if (is_array($postedIds)) {
                foreach ($postedIds as $postedId) {
                    $id = (int) $postedId;
                    if ($id > 0) {
                        $ids[$id] = true;
                    }
                }
            }
            $ids = array_keys($ids);
            if ($ids === []) {
                throw new RuntimeException('Select at least one channel to delete.');
            }
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $statement = $db->prepare('DELETE FROM channels WHERE id IN (' . $placeholders . ')');
            $statement->execute($ids);
            $count = $statement->rowCount();
            flash('Deleted ' . number_format($count) . ' channel' . ($count === 1 ? '' : 's') . '.');
        } elseif ($action === 'create') {
            $name = trim((string) ($_POST['name'] ?? ''));
            if ($name === '') {
                throw new RuntimeException('Channel name is required.');
            }
            $streamUrl = trim((string) ($_POST['stream_url'] ?? ''));
            if ($streamUrl === '') {
                throw new RuntimeException('Stream URL is required.');
            }
            $properties = parseChannelProperties((string) ($_POST['properties'] ?? ''));
            $licenseKeyUrl = trim((string) ($_POST['license_key_url'] ?? ''));
            $drm = readDrmFields($_POST, $licenseKeyUrl);
            $encodedProperties = json_encode((object) $properties, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            if ($encodedProperties === false) {
                throw new RuntimeException('Unable to encode the KODIPROP properties.');
            }
            $statement = $db->prepare(
                'INSERT INTO channels (source_id, name, tvg_id, tvg_name, group_title, tvg_language, tvg_type, '
                . 'logo_url, stream_url, license_key_url, drm_type, drm_key_id, drm_key, drm_headers, '
                . 'attributes_json, properties_json, url_fields_json, enabled, updated_at) '
                . 'VALUES (:source_id, :name, :tvg_id, :tvg_name, :group_title, :language, :type, '
                . ':logo_url, :stream_url, :license_key_url, :drm_type, :drm_key_id, :drm_key, :drm_headers, '
                . ':attributes, :properties, :url_fields, :enabled, :updated_at)'
            );
            $statement->execute([
                'source_id' => 'manual-' . bin2hex(random_bytes(8)),
                'name' => $name,
                'tvg_id' => trim((string) ($_POST['tvg_id'] ?? '')),
                'tvg_name' => trim((string) ($_POST['tvg_name'] ?? '')),
                'group_title' => trim((string) ($_POST['group_title'] ?? '')),
                'language' => trim((string) ($_POST['tvg_language'] ?? '')),
                'type' => trim((string) ($_POST['tvg_type'] ?? '')),
                'logo_url' => trim((string) ($_POST['logo_url'] ?? '')),
                'stream_url' => $streamUrl,
                'license_key_url' => $licenseKeyUrl,
                'drm_type' => $drm['type'],
                'drm_key_id' => $drm['key_id'],
                'drm_key' => $drm['key'],
                'drm_headers' => $drm['headers'],
                'attributes' => '{}',
                'properties' => $encodedProperties,
                'url_fields' => '{}',
                'enabled' => isset($_POST['enabled']) ? 1 : 0,
                'updated_at' => gmdate('Y-m-d H:i:s'),
            ]);
            bumpCacheVersion($db);
            flash('Channel added.');
            redirect();
        } elseif ($action === 'edit') {
            $name = trim((string) ($_POST['name'] ?? ''));
            if ($name === '') {
                throw new RuntimeException('Channel name is required.');
            }
            $properties = parseChannelProperties((string) ($_POST['properties'] ?? ''));
            $licenseKeyUrl = trim((string) ($_POST['license_key_url'] ?? ''));
            $drm = readDrmFields($_POST, $licenseKeyUrl);
            $statement = $db->prepare(
                'UPDATE channels SET name = :name, tvg_id = :tvg_id, tvg_name = :tvg_name, '
                . 'group_title = :group_title, tvg_language = :language, tvg_type = :type, '
                . 'logo_url = :logo_url, stream_url = :stream_url, license_key_url = :license_key_url, '
                . 'drm_type = :drm_type, drm_key_id = :drm_key_id, drm_key = :drm_key, drm_headers = :drm_headers, '
                . 'properties_json = :properties, enabled = :enabled, updated_at = :updated_at '
                . 'WHERE id = :id'
            );
            $statement->execute([
                'name' => $name,
                'tvg_id' => trim((string) ($_POST['tvg_id'] ?? '')),
                'tvg_name' => trim((string) ($_POST['tvg_name'] ?? '')),
                'group_title' => trim((string) ($_POST['group_title'] ?? '')),
                'language' => trim((string) ($_POST['tvg_language'] ?? '')),
                'type' => trim((string) ($_POST['tvg_type'] ?? '')),
                'logo_url' => trim((string) ($_POST['logo_url'] ?? '')),
                'stream_url' => trim((string) ($_POST['stream_url'] ?? '')),
                'license_key_url' => $licenseKeyUrl,
                'drm_type' => $drm['type'],
                'drm_key_id' => $drm['key_id'],
                'drm_key' => $drm['key'],
                'drm_headers' => $drm['headers'],
                'properties' => json_encode((object) $properties, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}',
                'enabled' => isset($_POST['enabled']) ? 1 : 0,
                'updated_at' => gmdate('Y-m-d H:i:s'),
                'id' => filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT),
            ]);
            flash('Channel details saved.');
        } elseif ($action === 'category_rename') {
            $oldName = trim((string) ($_POST['old_name'] ?? ''));
            $newName = trim((string) ($_POST['name'] ?? ''));
            if ($oldName === '' || $newName === '') {
                throw new RuntimeException('Category names cannot be empty.');
            }
            $statement = $db->prepare('UPDATE channels SET group_title = :new_name WHERE group_title = :old_name');
            $statement->execute(['new_name' => $newName, 'old_name' => $oldName]);
            if ($statement->rowCount() === 0) {
                throw new RuntimeException('Category not found.');
            }
            flash('Category renamed to "' . $newName . '".');
        } elseif ($action === 'category_delete') {
            $name = trim((string) ($_POST['name'] ?? ''));
            if ($name === '') {
                throw new RuntimeException('Category name is required.');
            }
            $countStatement = $db->prepare('SELECT COUNT(*) FROM channels WHERE group_title = :name');
            $countStatement->execute(['name' => $name]);
            $channelCount = (int) $countStatement->fetchColumn();
            if ($channelCount > 0) {
                throw new RuntimeException(
                    'This category still has ' . $channelCount . ' channel' . ($channelCount === 1 ? '' : 's')
                    . '. Move them to another category before deleting it.'
                );
            }
            $statement = $db->prepare("UPDATE channels SET group_title = '' WHERE group_title = :name");
            $statement->execute(['name' => $name]);
            if ($statement->rowCount() === 0) {
                throw new RuntimeException('Category not found.');
            }
            flash('Category deleted.');
        } elseif ($action === 'epg_import') {
            $epgUrl = normalizeEpgUrl((string) ($_POST['epg_url'] ?? ''));
            setMetaValue($db, 'epg_url', $epgUrl);
            if (function_exists('set_time_limit')) {
                @set_time_limit(300);
            }
            $result = importEpg($db, $epgUrl);
            flash(
                'Guide imported: ' . number_format($result['programs']) . ' programmes across '
                . number_format($result['channels']) . ' channels.'
            );
        } elseif ($action === 'epg_clear') {
            clearEpg($db);
            flash('Guide data cleared.');
        } elseif ($action === 'playlist_import') {
            if (function_exists('set_time_limit')) {
                @set_time_limit(300);
            }
            $uploaded = $_FILES['playlist_file'] ?? null;
            if (is_array($uploaded) && (int) ($uploaded['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
                if ((int) $uploaded['error'] !== UPLOAD_ERR_OK) {
                    throw new RuntimeException('The playlist upload failed (error code ' . (int) $uploaded['error'] . ').');
                }
                $temporary = (string) ($uploaded['tmp_name'] ?? '');
                if ($temporary === '' || !is_uploaded_file($temporary)) {
                    throw new RuntimeException('The uploaded playlist file is not readable.');
                }
                $size = (int) ($uploaded['size'] ?? 0);
                if ($size <= 0 || $size > 50 * 1024 * 1024) {
                    throw new RuntimeException('The uploaded playlist must be between 1 byte and 50 MB.');
                }
                $content = file_get_contents($temporary);
                if ($content === false) {
                    throw new RuntimeException('Unable to read the uploaded playlist file.');
                }
                $content = maybeDecompressGzip($content);
                $sourceLabel = 'file "' . (string) ($uploaded['name'] ?? 'playlist') . '"';
            } else {
                $postedUrl = trim((string) ($_POST['playlist_url'] ?? ''));
                if ($postedUrl === '') {
                    throw new RuntimeException('Choose a playlist file to upload or enter a playlist URL.');
                }
                $content = downloadPlaylist($postedUrl);
                $sourceLabel = 'URL ' . $postedUrl;
            }
            $result = importPlaylist($db, $content);
            flash(sprintf(
                'Playlist imported from %s: %d added, %d updated, %d skipped.',
                $sourceLabel,
                $result['added'],
                $result['updated'],
                $result['skipped']
            ));
        } else {
            throw new RuntimeException('Unknown action.');
        }
        bumpCacheVersion($db);
    } catch (Throwable $error) {
        flash($error->getMessage(), 'error');
    }

    $query = http_build_query(array_filter([
        'q' => $_GET['q'] ?? null,
        'category' => $_GET['category'] ?? null,
        'page' => $_GET['page'] ?? null,
        'categories' => $_GET['categories'] ?? null,
        'add' => $_GET['add'] ?? null,
        'import' => $_GET['import'] ?? null,
        'guide' => $_GET['guide'] ?? null,
        'date' => $_GET['date'] ?? null,
        'guide_category' => $_GET['guide_category'] ?? null,
        'guide_page' => $_GET['guide_page'] ?? null,
    ], static fn ($value): bool => is_string($value) && $value !== ''));
    redirect('index.php' . ($query !== '' ? '?' . $query : ''));
}

$loggedIn = isset($_SESSION['admin']);
$guideMode = $loggedIn && isset($_GET['guide']);
$flashMessage = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

function h(?string $value): string
{
    return htmlspecialchars($value ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function postButton(string $action, int $id, string $label, string $class = '', string $icon = ''): string
{
    $attributes = $icon !== '' ? ' title="' . h($label) . '" aria-label="' . h($label) . '"' : '';
    $content = $icon !== '' ? $icon : h($label);
    return '<form method="post" class="inline-form" onsubmit="return '
        . ($action === 'delete' ? "confirm('Delete this channel?')" : 'true')
        . '"><input type="hidden" name="csrf" value="' . h(csrfToken()) . '">'
        . '<input type="hidden" name="action" value="' . h($action) . '">'
        . '<input type="hidden" name="id" value="' . $id . '">'
        . '<button class="button ' . h($class) . '" type="submit"' . $attributes . '>' . $content . '</button></form>';
}

function editIcon(): string
{
    return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M12 20h9"/><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4Z"/></svg>';
}

function deleteIcon(): string
{
    return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M3 6h18"/><path d="M8 6V4h8v2"/><path d="M19 6l-1 14H6L5 6"/><path d="M10 11v5"/><path d="M14 11v5"/></svg>';
}

function playIcon(): string
{
    return '<svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true" focusable="false"><path d="M8 5v14l11-7Z"/></svg>';
}

function channelPreviewData(array $channel): array
{
    $drm = channelDrmSettings($channel);
    $headers = [];
    try {
        $headers = parseDrmHeaders((string) ($channel['drm_headers'] ?? ''));
    } catch (RuntimeException) {
        $headers = [];
    }
    if ($headers === []) {
        $properties = json_decode((string) ($channel['properties_json'] ?? ''), true);
        $licenseProperty = is_array($properties) ? ($properties['inputstream.adaptive.license_key'] ?? '') : '';
        if (is_string($licenseProperty)) {
            $segments = explode('|', $licenseProperty);
            if (isset($segments[1]) && $segments[1] !== '') {
                parse_str($segments[1], $parsedHeaders);
                foreach ($parsedHeaders as $name => $value) {
                    if (is_string($name) && is_string($value)) {
                        $headers[$name] = $value;
                    }
                }
            }
        }
    }

    $path = parse_url((string) ($channel['stream_url'] ?? ''), PHP_URL_PATH);
    $extension = strtolower(pathinfo(is_string($path) ? $path : '', PATHINFO_EXTENSION));
    $mime = match ($extension) {
        'm3u8' => 'application/x-mpegURL',
        'mpd' => 'application/dash+xml',
        'mp4', 'm4v' => 'video/mp4',
        'webm' => 'video/webm',
        'ogg', 'ogv' => 'video/ogg',
        default => '',
    };

    return [
        'name' => (string) ($channel['name'] ?? ''),
        'src' => (string) ($channel['stream_url'] ?? ''),
        'mime' => $mime,
        'drmType' => $drm['type'],
        'licenseUrl' => $drm['license'],
        'keyId' => $drm['key_id'],
        'key' => $drm['key'],
        'headers' => (object) $headers,
    ];
}

function statusButton(int $id, bool $enabled): string
{
    $label = $enabled ? 'Disable channel' : 'Enable channel';
    return '<form method="post" class="status-form">'
        . '<input type="hidden" name="csrf" value="' . h(csrfToken()) . '">'
        . '<input type="hidden" name="action" value="toggle">'
        . '<input type="hidden" name="id" value="' . $id . '">'
        . '<button class="status-dot ' . ($enabled ? 'enabled' : 'disabled') . '" type="submit" title="' . h($label) . '" aria-label="' . h($label) . '"></button>'
        . '</form>';
}

?><!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= $loggedIn ? ($guideMode ? 'TV Guide · IPTV Manager' : 'Channels · IPTV Manager') : 'Sign in · IPTV Manager' ?></title>
    <link rel="stylesheet" href="style.css">
</head>
<body>
<?php if (!$loggedIn): ?>
    <main class="login-shell">
        <section class="login-card">
            <div class="brand-mark" aria-hidden="true">▶</div>
            <p class="eyebrow">IPTV ADMINISTRATION</p>
            <h1>Welcome back</h1>
            <p class="muted">Sign in to manage your playlist and channels.</p>
            <?php if ($flashMessage): ?>
                <div class="notice <?= h($flashMessage['kind']) ?>"><?= h($flashMessage['message']) ?></div>
            <?php endif; ?>
            <form method="post" class="login-form">
                <input type="hidden" name="csrf" value="<?= h(csrfToken()) ?>">
                <input type="hidden" name="action" value="login">
                <label>Username<input name="username" autocomplete="username" required autofocus></label>
                <label>Password<input type="password" name="password" autocomplete="current-password" required></label>
                <button class="button primary full-width" type="submit">Sign in</button>
            </form>
            <p class="login-hint">Default sign-in: <strong>admin / admin</strong></p>
        </section>
    </main>
<?php else: ?>
    <?php
    $search = trim((string) ($_GET['q'] ?? ''));
    $category = trim((string) ($_GET['category'] ?? ''));
    $page = max(1, (int) ($_GET['page'] ?? 1));
    $pageSize = 50;
    $where = [];
    $parameters = [];
    if ($search !== '') {
        $where[] = '(name LIKE :search OR tvg_id LIKE :search OR group_title LIKE :search OR stream_url LIKE :search)';
        $parameters['search'] = '%' . $search . '%';
    }
    if ($category !== '') {
        $where[] = 'group_title = :category';
        $parameters['category'] = $category;
    }
    $whereSql = $where ? ' WHERE ' . implode(' AND ', $where) : '';
    $listData = databaseCache(
        $db,
        'v1:channels:' . sha1((string) json_encode([$search, $category, $page, $pageSize])),
        static function () use ($db, $whereSql, $parameters, $page, $pageSize): array {
            $countStatement = $db->prepare('SELECT COUNT(*) FROM channels' . $whereSql);
            $countStatement->execute($parameters);
            $totalFiltered = (int) $countStatement->fetchColumn();
            $totalPages = max(1, (int) ceil($totalFiltered / $pageSize));
            $page = min($page, $totalPages);
            $statement = $db->prepare(
                'SELECT * FROM channels' . $whereSql . ' ORDER BY group_title COLLATE NOCASE, name COLLATE NOCASE '
                . 'LIMIT :limit OFFSET :offset'
            );
            foreach ($parameters as $key => $value) {
                $statement->bindValue(':' . $key, $value, PDO::PARAM_STR);
            }
            $statement->bindValue(':limit', $pageSize, PDO::PARAM_INT);
            $statement->bindValue(':offset', ($page - 1) * $pageSize, PDO::PARAM_INT);
            $statement->execute();

            return [
                'total_filtered' => $totalFiltered,
                'total_pages' => $totalPages,
                'page' => $page,
                'channels' => $statement->fetchAll(),
            ];
        }
    );
    $totalFiltered = (int) $listData['total_filtered'];
    $totalPages = (int) $listData['total_pages'];
    $page = (int) $listData['page'];
    $channels = $listData['channels'];

    $categoryRows = databaseCache(
        $db,
        'v1:category_rows',
        static fn (): array => $db->query(
            "SELECT group_title, COUNT(*) AS channel_count FROM channels WHERE group_title != '' "
            . 'GROUP BY group_title ORDER BY group_title COLLATE NOCASE'
        )->fetchAll()
    );
    $categories = array_map(static fn (array $row): string => (string) $row['group_title'], $categoryRows);
    $uncategorizedCount = (int) databaseCache(
        $db,
        'v1:uncategorized_count',
        static fn (): int => (int) $db->query("SELECT COUNT(*) FROM channels WHERE group_title = ''")->fetchColumn()
    );
    $addChannel = isset($_GET['add']);
    $importPlaylist = isset($_GET['import']);
    if ($guideMode) {
        $epgUrl = metaValue($db, 'epg_url');
        $epgLastImport = (int) metaValue($db, 'epg_last_import', '0');
        $epgProgramCount = (int) metaValue($db, 'epg_program_count', '0');
        $epgChannelCount = (int) metaValue($db, 'epg_channel_count', '0');

        $guideDateValue = trim((string) ($_GET['date'] ?? ''));
        $guideDate = DateTimeImmutable::createFromFormat('!Y-m-d', $guideDateValue);
        if (!$guideDate instanceof DateTimeImmutable || $guideDate->format('Y-m-d') !== $guideDateValue) {
            $guideDate = new DateTimeImmutable('today');
        }
        $guideCategory = trim((string) ($_GET['guide_category'] ?? ''));
        $guidePageSize = 20;
        $guideRequestedPage = max(1, (int) ($_GET['guide_page'] ?? 1));
        $guideDayStart = $guideDate->getTimestamp();
        $guideDayEnd = $guideDate->modify('+1 day')->getTimestamp();
        $guideRange = max(1, $guideDayEnd - $guideDayStart);
        $guideParams = ['guide' => '1', 'date' => $guideDate->format('Y-m-d')];
        if ($guideCategory !== '') {
            $guideParams['guide_category'] = $guideCategory;
        }

        $guideData = databaseCache(
            $db,
            'v1:guide:' . sha1((string) json_encode([$guideDayStart, $guideDayEnd, $guideCategory, $guideRequestedPage, $guidePageSize])),
            static function () use ($db, $guideDayStart, $guideDayEnd, $guideCategory, $guideRequestedPage, $guidePageSize): array {
                $where = [
                    'c.enabled = 1',
                    'EXISTS (SELECT 1 FROM epg_programs p WHERE p.channel_id = c.id AND p.stop_ts > :day_start AND p.start_ts < :day_end)',
                ];
                $whereParameters = [':day_start' => $guideDayStart, ':day_end' => $guideDayEnd];
                if ($guideCategory !== '') {
                    $where[] = 'c.group_title = :category';
                    $whereParameters[':category'] = $guideCategory;
                }
                $whereSql = ' WHERE ' . implode(' AND ', $where);

                $countStatement = $db->prepare('SELECT COUNT(*) FROM channels c' . $whereSql);
                foreach ($whereParameters as $key => $value) {
                    $countStatement->bindValue($key, $value, $key === ':category' ? PDO::PARAM_STR : PDO::PARAM_INT);
                }
                $countStatement->execute();
                $total = (int) $countStatement->fetchColumn();
                $totalPages = max(1, (int) ceil($total / $guidePageSize));
                $page = min($guideRequestedPage, $totalPages);

                $statement = $db->prepare(
                    'SELECT c.id, c.name, c.tvg_id, c.group_title, c.logo_url FROM channels c' . $whereSql
                    . ' ORDER BY c.group_title COLLATE NOCASE, c.name COLLATE NOCASE LIMIT :limit OFFSET :offset'
                );
                foreach ($whereParameters as $key => $value) {
                    $statement->bindValue($key, $value, $key === ':category' ? PDO::PARAM_STR : PDO::PARAM_INT);
                }
                $statement->bindValue(':limit', $guidePageSize, PDO::PARAM_INT);
                $statement->bindValue(':offset', ($page - 1) * $guidePageSize, PDO::PARAM_INT);
                $statement->execute();
                $guideChannels = $statement->fetchAll();

                $guideProgrammes = [];
                if ($guideChannels !== []) {
                    $ids = array_map(static fn (array $channel): int => (int) $channel['id'], $guideChannels);
                    $placeholders = implode(',', array_fill(0, count($ids), '?'));
                    $programmeStatement = $db->prepare(
                        'SELECT channel_id, start_ts, stop_ts, title, description, category FROM epg_programs '
                        . 'WHERE channel_id IN (' . $placeholders . ') AND stop_ts > ? AND start_ts < ? '
                        . 'ORDER BY channel_id, start_ts'
                    );
                    $programmeStatement->execute([...$ids, $guideDayStart, $guideDayEnd]);
                    foreach ($programmeStatement as $programme) {
                        $guideProgrammes[(int) $programme['channel_id']][] = $programme;
                    }
                }

                return [
                    'channels' => $guideChannels,
                    'programmes' => $guideProgrammes,
                    'total' => $total,
                    'total_pages' => $totalPages,
                    'page' => $page,
                ];
            }
        );

        $guideChannels = $guideData['channels'];
        $guideProgrammes = $guideData['programmes'];
        $guideTotal = (int) $guideData['total'];
        $guideTotalPages = (int) $guideData['total_pages'];
        $guidePage = (int) $guideData['page'];
        $guideIsToday = $guideDate->format('Y-m-d') === date('Y-m-d');
        $guideNow = time();
        $guideNowLeft = (($guideNow - $guideDayStart) / $guideRange) * 100;
        $guideUrl = static function (array $overrides = []) use ($guideParams): string {
            $params = array_merge($guideParams, $overrides);
            foreach ($params as $key => $value) {
                if ($value === '' || $value === null) {
                    unset($params[$key]);
                }
            }
            return 'index.php?' . http_build_query($params);
        };
    }
    $manageCategories = isset($_GET['categories']);
    $editChannel = null;
    $editId = filter_input(INPUT_GET, 'edit', FILTER_VALIDATE_INT);
    if ($editId) {
        $editStatement = $db->prepare('SELECT * FROM channels WHERE id = :id');
        $editStatement->execute(['id' => $editId]);
        $editChannel = $editStatement->fetch() ?: null;
        if ($editChannel === null) {
            flash('Channel not found.', 'error');
            redirect('index.php');
        }
    }
    $stats = databaseCache(
        $db,
        'v1:stats',
        static function () use ($db): array {
            $stats = $db->query(
                'SELECT COUNT(*) AS total, SUM(CASE WHEN enabled = 1 THEN 1 ELSE 0 END) AS active, '
                . 'COUNT(DISTINCT group_title) AS groups FROM channels'
            )->fetch();
            return is_array($stats) ? $stats : ['total' => 0, 'active' => 0, 'groups' => 0];
        }
    );
    $urlColumns = databaseCache(
        $db,
        'v1:url_columns',
        static function () use ($db): array {
            $columns = [];
            foreach ($db->query('SELECT url_fields_json FROM channels') as $row) {
                $fields = json_decode($row['url_fields_json'], true);
                if (is_array($fields)) {
                    foreach (array_keys($fields) as $field) {
                        $columns[$field] = true;
                    }
                }
            }
            $columns = array_keys($columns);
            sort($columns, SORT_NATURAL | SORT_FLAG_CASE);
            return $columns;
        }
    );
    $visibleUrlColumns = array_values(array_diff($urlColumns, ['tvg-logo', 'inputstream.adaptive.license_key']));
    $requestHost = parse_url('http://' . ($_SERVER['HTTP_HOST'] ?? '127.0.0.1'), PHP_URL_HOST);
    $playlistHost = is_string($requestHost) ? $requestHost : '127.0.0.1';
    if (str_contains($playlistHost, ':')) {
        $playlistHost = '[' . $playlistHost . ']';
    }
    $playlistPort = (int) (getenv('IPTV_PLAYLIST_PORT') ?: 8001);
    $playlistUrl = 'http://' . $playlistHost . ':' . $playlistPort . '/';
    $clearPort = (int) (getenv('IPTV_CLEAR_PORT') ?: 8002);
    $clearUrl = 'http://' . $playlistHost . ':' . $clearPort . '/';
    $baseQuery = array_filter(['q' => $search, 'category' => $category], static fn ($value): bool => $value !== '');
    $listParams = $baseQuery;
    if ($page > 1) {
        $listParams['page'] = $page;
    }
    $listUrl = 'index.php' . ($listParams !== [] ? '?' . http_build_query($listParams) : '');
    $editProperties = '';
    $editDrm = ['type' => '', 'license' => '', 'key_id' => '', 'key' => '', 'headers' => ''];
    if ($editChannel) {
        $decodedProperties = json_decode((string) $editChannel['properties_json'], true);
        if (is_array($decodedProperties)) {
            foreach ($decodedProperties as $propertyName => $propertyValue) {
                if (is_string($propertyName) && is_string($propertyValue)) {
                    $editProperties .= $propertyName . '=' . $propertyValue . "\n";
                }
            }
        }
        $editProperties = rtrim($editProperties);
        $editDrm = channelDrmSettings($editChannel);
        $editDrm['headers'] = (string) ($editChannel['drm_headers'] ?? '');
    }
    ?>
    <header class="topbar">
        <a class="brand" href="index.php"><span class="brand-mark small">▶</span><span>IPTV <b>Manager</b></span></a>
        <div class="topbar-right">
            <span class="admin-label"><span class="online-dot"></span><?= h((string) $_SESSION['admin']) ?></span>
            <a class="button text-button" href="index.php?guide=1"<?= $guideMode ? ' aria-current="page"' : '' ?>>TV Guide</a>
            <a class="button text-button" href="index.php?add=1"<?= $addChannel ? ' aria-current="page"' : '' ?>>Add channel</a>
            <a class="button text-button" href="index.php?import=1"<?= $importPlaylist ? ' aria-current="page"' : '' ?>>Import playlist</a>
            <a class="button text-button categories-link" href="index.php?categories=1"<?= $manageCategories ? ' aria-current="page"' : '' ?>>Manage categories</a>
            <a class="button text-button" href="<?= h($playlistUrl) ?>" download="iptv-manager.m3u">Download M3U</a>
            <a class="button text-button" href="<?= h($clearUrl) ?>" download="iptv-manager-clear.m3u">Clear M3U</a>
            <form method="post" class="purge-cache-form">
                <input type="hidden" name="csrf" value="<?= h(csrfToken()) ?>">
                <input type="hidden" name="action" value="purge_cache">
                <button class="button text-button" type="submit">Purge cache</button>
            </form>
            <form method="post">
                <input type="hidden" name="csrf" value="<?= h(csrfToken()) ?>">
                <input type="hidden" name="action" value="logout">
                <button class="button text-button" type="submit">Sign out</button>
            </form>
        </div>
    </header>
    <main class="page">
        <?php if ($flashMessage): ?>
            <div class="notice <?= h($flashMessage['kind']) ?>"><?= h($flashMessage['message']) ?></div>
        <?php endif; ?>
        <?php if ($guideMode): ?>
            <section class="form-card guide-source-card">
                <div class="form-card-head">
                    <div>
                        <p class="eyebrow">TV GUIDE SOURCE</p>
                        <h2>XMLTV guide</h2>
                        <p class="muted">Import an XMLTV guide to populate the TV guide. Channels are matched by TVG ID, then by name. Importing a large guide can take a moment.</p>
                    </div>
                    <span class="guide-source-status muted">
                        <?php if ($epgProgramCount > 0): ?>
                            <?= number_format($epgProgramCount) ?> programmes · <?= number_format($epgChannelCount) ?> channels
                            <?php if ($epgLastImport > 0): ?><br>Updated <?= h(date('M j, Y H:i', $epgLastImport)) ?><?php endif; ?>
                        <?php else: ?>
                            No guide data imported yet.
                        <?php endif; ?>
                    </span>
                </div>
                <div class="guide-source-actions">
                    <form method="post" action="index.php?guide=1" class="guide-source-form">
                        <input type="hidden" name="csrf" value="<?= h(csrfToken()) ?>">
                        <input type="hidden" name="action" value="epg_import">
                        <label class="guide-url-field"><span>XMLTV URL</span><input type="url" name="epg_url" value="<?= h($epgUrl) ?>" placeholder="https://example.com/epg.xml.gz" required></label>
                        <button class="button primary" type="submit">Save &amp; import</button>
                    </form>
                    <?php if ($epgProgramCount > 0): ?>
                        <form method="post" action="index.php?guide=1" onsubmit="return confirm('Clear all imported guide data?')">
                            <input type="hidden" name="csrf" value="<?= h(csrfToken()) ?>">
                            <input type="hidden" name="action" value="epg_clear">
                            <button class="button secondary" type="submit">Clear guide data</button>
                        </form>
                    <?php endif; ?>
                </div>
            </section>

            <section class="table-card guide-card">
                <div class="table-toolbar guide-toolbar">
                    <div>
                        <h2>TV guide</h2>
                        <p class="muted"><?= h($guideDate->format('l, F j, Y')) ?> · <?= number_format($guideTotal) ?> <?= $guideTotal === 1 ? 'channel' : 'channels' ?> · times in <?= h(date('T')) ?></p>
                    </div>
                    <form method="get" class="filters guide-filters">
                        <input type="hidden" name="guide" value="1">
                        <select name="guide_category" aria-label="Filter guide by category">
                            <option value="">All categories</option>
                            <?php foreach ($categories as $item): ?>
                                <option value="<?= h((string) $item) ?>" <?= $guideCategory === $item ? 'selected' : '' ?>><?= h((string) $item) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <input type="date" name="date" value="<?= h($guideDate->format('Y-m-d')) ?>" aria-label="Guide date">
                        <button class="button secondary" type="submit">Go</button>
                    </form>
                </div>
                <div class="guide-daynav">
                    <a class="button secondary small-button" href="<?= h($guideUrl(['date' => $guideDate->modify('-1 day')->format('Y-m-d')])) ?>">← Previous day</a>
                    <a class="button secondary small-button" href="<?= h($guideUrl(['date' => date('Y-m-d')])) ?>">Today</a>
                    <a class="button secondary small-button" href="<?= h($guideUrl(['date' => $guideDate->modify('+1 day')->format('Y-m-d')])) ?>">Next day →</a>
                </div>
                <?php if (!$guideChannels): ?>
                    <div class="guide-empty">
                        <strong>No programmes for this selection</strong>
                        <span><?= $epgProgramCount > 0 ? 'Try another date or category. The imported guide may not cover this day.' : 'Add an XMLTV guide URL above and import it to populate the guide.' ?></span>
                    </div>
                <?php else: ?>
                    <div class="guide-scroll">
                        <div class="guide-grid">
                            <div class="guide-row guide-head">
                                <div class="guide-channel">Channel</div>
                                <div class="guide-track">
                                    <?php for ($hour = 0; $hour < 24; $hour += 2): ?>
                                        <span class="guide-hour" style="left: <?= ($hour / 24) * 100 ?>%"><?= str_pad((string) $hour, 2, '0', STR_PAD_LEFT) ?>:00</span>
                                    <?php endfor; ?>
                                </div>
                            </div>
                            <?php foreach ($guideChannels as $channel): ?>
                                <?php $channelProgrammes = $guideProgrammes[(int) $channel['id']] ?? []; ?>
                                <div class="guide-row">
                                    <div class="guide-channel">
                                        <?php if ($channel['logo_url']): ?><img class="guide-logo" src="<?= h($channel['logo_url']) ?>" alt="" loading="lazy" referrerpolicy="no-referrer"><?php else: ?><span class="guide-logo placeholder">▶</span><?php endif; ?>
                                        <div><strong><?= h($channel['name']) ?></strong><small><?= h($channel['group_title'] ?: $channel['tvg_id']) ?></small></div>
                                    </div>
                                    <div class="guide-track">
                                        <?php foreach ($channelProgrammes as $programme): ?>
                                            <?php
                                            $programmeStart = max($guideDayStart, (int) $programme['start_ts']);
                                            $programmeStop = min($guideDayEnd, (int) $programme['stop_ts']);
                                            if ($programmeStop <= $programmeStart) {
                                                continue;
                                            }
                                            $programmeLeft = (($programmeStart - $guideDayStart) / $guideRange) * 100;
                                            $programmeWidth = max(0.25, (($programmeStop - $programmeStart) / $guideRange) * 100);
                                            $isCurrent = $guideIsToday && (int) $programme['start_ts'] <= $guideNow && (int) $programme['stop_ts'] > $guideNow;
                                            $programmeTooltip = date('H:i', (int) $programme['start_ts']) . '–' . date('H:i', (int) $programme['stop_ts']) . ' · ' . $programme['title'];
                                            if ($programme['description'] !== '') {
                                                $programmeTooltip .= ' — ' . $programme['description'];
                                            }
                                            ?>
                                            <div class="guide-program<?= $isCurrent ? ' current' : '' ?>" style="left: <?= number_format($programmeLeft, 3, '.', '') ?>%; width: <?= number_format($programmeWidth, 3, '.', '') ?>%" title="<?= h($programmeTooltip) ?>">
                                                <span class="guide-title"><?= h($programme['title']) ?></span>
                                            </div>
                                        <?php endforeach; ?>
                                        <?php if ($guideIsToday && $guideNow >= $guideDayStart && $guideNow < $guideDayEnd): ?>
                                            <span class="guide-now" style="left: <?= number_format($guideNowLeft, 3, '.', '') ?>%" aria-hidden="true"></span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <?php if ($guideTotalPages > 1): ?>
                        <nav class="pagination" aria-label="Guide pagination">
                            <span>Page <?= $guidePage ?> of <?= $guideTotalPages ?></span>
                            <div>
                                <?php if ($guidePage > 1): ?><a class="button secondary" href="<?= h($guideUrl(['guide_page' => $guidePage - 1])) ?>">Previous</a><?php endif; ?>
                                <?php if ($guidePage < $guideTotalPages): ?><a class="button secondary" href="<?= h($guideUrl(['guide_page' => $guidePage + 1])) ?>">Next</a><?php endif; ?>
                            </div>
                        </nav>
                    <?php endif; ?>
                <?php endif; ?>
            </section>
        <?php elseif ($addChannel): ?>
            <section class="form-card">
                <div class="form-card-head">
                    <div>
                        <p class="eyebrow">ADD CHANNEL</p>
                        <h2>New channel</h2>
                        <p class="muted">Add a channel manually. TVG fields and KODIPROP properties are written to the generated playlist.</p>
                    </div>
                    <a class="button secondary" href="index.php">Back to channels</a>
                </div>
                <form method="post" action="index.php?add=1" class="channel-edit-form">
                    <input type="hidden" name="csrf" value="<?= h(csrfToken()) ?>">
                    <input type="hidden" name="action" value="create">
                    <div class="form-grid">
                        <label>Name<input name="name" required autofocus></label>
                        <label>Category<input name="group_title" list="category-options"></label>
                        <label>TVG ID<input name="tvg_id"></label>
                        <label>TVG name<input name="tvg_name"></label>
                        <label>Language<input name="tvg_language"></label>
                        <label>Type<input name="tvg_type"></label>
                        <label class="span-2">Logo URL<input name="logo_url"></label>
                        <label class="span-2">Stream URL<input name="stream_url" required></label>
                        <label class="span-2">License key URL<input name="license_key_url"></label>
                        <label>DRM type<select name="drm_type">
                            <option value="">None / KODIPROP only</option>
                            <option value="clearkey">ClearKey</option>
                            <option value="widevine">Widevine</option>
                            <option value="playready">PlayReady</option>
                        </select><small>Widevine and PlayReady require a license URL.</small></label>
                        <label>DRM license headers<textarea name="drm_headers" rows="2" spellcheck="false" placeholder="Authorization: Bearer token"></textarea><small>One Name: value header per line, sent with license requests.</small></label>
                        <label>ClearKey key ID<input name="drm_key_id" placeholder="32 hex characters"></label>
                        <label>ClearKey key<input name="drm_key" placeholder="32 hex characters"></label>
                        <label class="span-2">KODIPROP properties<textarea name="properties" rows="6" spellcheck="false" placeholder="inputstream.adaptive.manifest_type=mpd"></textarea><small>One name=value pair per line. These become #KODIPROP entries in the playlist.</small></label>
                        <label class="checkbox-label"><input type="checkbox" name="enabled" value="1" checked> Include in playlist</label>
                    </div>
                    <div class="form-actions">
                        <button class="button primary" type="submit">Add channel</button>
                        <a class="button secondary" href="index.php">Cancel</a>
                    </div>
                </form>
                <datalist id="category-options">
                    <?php foreach ($categories as $item): ?>
                        <option value="<?= h((string) $item) ?>"></option>
                    <?php endforeach; ?>
                </datalist>
            </section>
        <?php elseif ($importPlaylist): ?>
            <section class="form-card">
                <div class="form-card-head">
                    <div>
                        <p class="eyebrow">IMPORT PLAYLIST</p>
                        <h2>Import M3U playlist</h2>
                        <p class="muted">Upload an M3U/M3U8 file or enter a playlist URL. Channels with the same name and stream URL are updated in place; channels you disabled stay disabled. #KODIPROP DRM settings are mapped to the dedicated DRM fields automatically.</p>
                    </div>
                    <a class="button secondary" href="index.php">Back to channels</a>
                </div>
                <form method="post" action="index.php?import=1" enctype="multipart/form-data" class="channel-edit-form">
                    <input type="hidden" name="csrf" value="<?= h(csrfToken()) ?>">
                    <input type="hidden" name="action" value="playlist_import">
                    <div class="form-grid">
                        <label class="span-2">Playlist file<input type="file" name="playlist_file" accept=".m3u,.m3u8,.txt"></label>
                        <label class="span-2">Playlist URL<input type="url" name="playlist_url" placeholder="https://example.com/playlist.m3u"></label>
                    </div>
                    <p class="muted">Fill in the file or the URL — if both are given, the file is used. Gzip-compressed playlists are supported.</p>
                    <div class="form-actions">
                        <button class="button primary" type="submit">Import playlist</button>
                        <a class="button secondary" href="index.php">Cancel</a>
                    </div>
                </form>
            </section>
        <?php elseif ($editChannel): ?>
            <section class="form-card">
                <div class="form-card-head">
                    <div>
                        <p class="eyebrow">EDIT CHANNEL</p>
                        <h2><?= h($editChannel['name']) ?></h2>
                        <p class="muted">Update every property written to the generated playlist.</p>
                    </div>
                    <a class="button secondary" href="<?= h($listUrl) ?>">Back to channels</a>
                </div>
                <form method="post" action="<?= h($listUrl) ?>" class="channel-edit-form">
                    <input type="hidden" name="csrf" value="<?= h(csrfToken()) ?>">
                    <input type="hidden" name="action" value="edit">
                    <input type="hidden" name="id" value="<?= (int) $editChannel['id'] ?>">
                    <div class="form-grid">
                        <label>Name<input name="name" value="<?= h($editChannel['name']) ?>" required></label>
                        <label>Category<input name="group_title" list="category-options" value="<?= h($editChannel['group_title']) ?>"></label>
                        <label>TVG ID<input name="tvg_id" value="<?= h($editChannel['tvg_id']) ?>"></label>
                        <label>TVG name<input name="tvg_name" value="<?= h($editChannel['tvg_name']) ?>"></label>
                        <label>Language<input name="tvg_language" value="<?= h($editChannel['tvg_language']) ?>"></label>
                        <label>Type<input name="tvg_type" value="<?= h($editChannel['tvg_type']) ?>"></label>
                        <label class="span-2">Logo URL<input name="logo_url" value="<?= h($editChannel['logo_url']) ?>"></label>
                        <label class="span-2">Stream URL<input name="stream_url" value="<?= h($editChannel['stream_url']) ?>"></label>
                        <label class="span-2">License key URL<input name="license_key_url" value="<?= h($editDrm['license']) ?>"></label>
                        <label>DRM type<select name="drm_type">
                            <option value="" <?= $editDrm['type'] === '' ? 'selected' : '' ?>>None / KODIPROP only</option>
                            <option value="clearkey" <?= $editDrm['type'] === 'clearkey' ? 'selected' : '' ?>>ClearKey</option>
                            <option value="widevine" <?= $editDrm['type'] === 'widevine' ? 'selected' : '' ?>>Widevine</option>
                            <option value="playready" <?= $editDrm['type'] === 'playready' ? 'selected' : '' ?>>PlayReady</option>
                        </select><small>Widevine and PlayReady require a license URL.</small></label>
                        <label>DRM license headers<textarea name="drm_headers" rows="2" spellcheck="false" placeholder="Authorization: Bearer token"><?= h($editDrm['headers']) ?></textarea><small>One Name: value header per line, sent with license requests.</small></label>
                        <label>ClearKey key ID<input name="drm_key_id" value="<?= h($editDrm['key_id']) ?>" placeholder="32 hex characters"></label>
                        <label>ClearKey key<input name="drm_key" value="<?= h($editDrm['key']) ?>" placeholder="32 hex characters"></label>
                        <label class="span-2">KODIPROP properties<textarea name="properties" rows="6" spellcheck="false" placeholder="inputstream.adaptive.manifest_type=mpd"><?= h($editProperties) ?></textarea><small>One name=value pair per line. These become #KODIPROP entries in the playlist. Dedicated DRM fields override matching keys.</small></label>
                        <label class="checkbox-label"><input type="checkbox" name="enabled" value="1" <?= $editChannel['enabled'] ? 'checked' : '' ?>> Include in playlist</label>
                    </div>
                    <div class="form-actions">
                        <button class="button primary" type="submit">Save changes</button>
                        <a class="button secondary" href="<?= h($listUrl) ?>">Cancel</a>
                    </div>
                </form>
                <datalist id="category-options">
                    <?php foreach ($categories as $item): ?>
                        <option value="<?= h((string) $item) ?>"></option>
                    <?php endforeach; ?>
                </datalist>
            </section>
        <?php elseif ($manageCategories): ?>
            <section class="form-card">
                <div class="form-card-head">
                    <div>
                        <p class="eyebrow">CATEGORY MANAGEMENT</p>
                        <h2>Channel categories</h2>
                        <p class="muted">Rename a category to move its channels. A category can only be deleted when it no longer contains any channels. Renaming to an existing name merges the two categories.</p>
                    </div>
                    <a class="button secondary" href="<?= h($listUrl) ?>">Back to channels</a>
                </div>
                <?php if (!$categoryRows): ?>
                    <p class="category-empty muted">No categories yet. Assign one from a channel's edit page.</p>
                <?php endif; ?>
                <div class="category-list">
                    <?php foreach ($categoryRows as $row): ?>
                        <div class="category-row">
                            <form method="post" action="index.php?categories=1" class="category-rename">
                                <input type="hidden" name="csrf" value="<?= h(csrfToken()) ?>">
                                <input type="hidden" name="action" value="category_rename">
                                <input type="hidden" name="old_name" value="<?= h((string) $row['group_title']) ?>">
                                <input name="name" value="<?= h((string) $row['group_title']) ?>" aria-label="Category name" required>
                                <button class="button secondary small-button" type="submit">Rename</button>
                            </form>
                            <span class="category-count"><?= (int) $row['channel_count'] ?> <?= ((int) $row['channel_count'] === 1) ? 'channel' : 'channels' ?></span>
                            <?php if ((int) $row['channel_count'] === 0): ?>
                                <form method="post" action="index.php?categories=1" onsubmit="return confirm('Delete this category?')">
                                    <input type="hidden" name="csrf" value="<?= h(csrfToken()) ?>">
                                    <input type="hidden" name="action" value="category_delete">
                                    <input type="hidden" name="name" value="<?= h((string) $row['group_title']) ?>">
                                    <button class="button small-button danger-button" type="submit">Delete</button>
                                </form>
                            <?php else: ?>
                                <button class="button small-button danger-button" type="button" disabled title="Move all channels out of this category first">Delete</button>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
                <?php if ($uncategorizedCount > 0): ?>
                    <p class="category-note muted"><?= (int) $uncategorizedCount ?> uncategorized <?= $uncategorizedCount === 1 ? 'channel is' : 'channels are' ?> not listed here. Assign them a category from each channel's edit page.</p>
                <?php endif; ?>
            </section>
        <?php else: ?>
        <section class="stats-grid" aria-label="Playlist statistics">
            <article class="stat-card"><span class="stat-icon blue">▤</span><div><span class="stat-label">Total channels</span><strong><?= (int) $stats['total'] ?></strong></div></article>
            <article class="stat-card"><span class="stat-icon green">●</span><div><span class="stat-label">Enabled</span><strong><?= (int) $stats['active'] ?></strong></div></article>
            <article class="stat-card"><span class="stat-icon purple">▦</span><div><span class="stat-label">Categories</span><strong><?= (int) $stats['groups'] ?></strong></div></article>
        </section>

        <section class="table-card">
            <div class="table-toolbar">
                <div><h2>Channel library</h2><p class="muted"><?= number_format($totalFiltered) ?> matching channels</p></div>
                <form method="get" class="filters">
                    <label class="search-box"><span aria-hidden="true">⌕</span><input type="search" name="q" value="<?= h($search) ?>" placeholder="Search channels, IDs, URLs"></label>
                    <select name="category" aria-label="Filter by category">
                        <option value="">All categories</option>
                        <?php foreach ($categories as $item): ?>
                            <option value="<?= h((string) $item) ?>" <?= $category === $item ? 'selected' : '' ?>><?= h((string) $item) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <button class="button secondary" type="submit">Filter</button>
                    <?php if ($search !== '' || $category !== ''): ?><a class="clear-link" href="index.php">Clear</a><?php endif; ?>
                </form>
                <form method="post" id="bulk-delete-form" class="bulk-delete-form" onsubmit="return confirm('Delete ' + document.querySelectorAll('.bulk-checkbox:checked').length + ' selected channels?')">
                    <input type="hidden" name="csrf" value="<?= h(csrfToken()) ?>">
                    <input type="hidden" name="action" value="delete_selected">
                    <button class="button danger-button" type="submit">Delete selected</button>
                </form>
            </div>
            <div class="table-wrap">
                <table>
                    <thead><tr>
                        <th class="select-cell"><input type="checkbox" class="bulk-select-all" title="Select all channels on this page" aria-label="Select all channels on this page"></th>
                        <th>Channel</th><th>ID</th><th>Category</th><th>Language</th><th>Type</th>
                        <th>Logo URL</th><th>Stream URL</th><th>License URL</th>
                        <?php foreach ($visibleUrlColumns as $field): ?>
                            <th><?= h($field) ?></th>
                        <?php endforeach; ?>
                        <th>Manage</th>
                    </tr></thead>
                    <tbody>
                    <?php if (!$channels): ?>
                        <tr><td colspan="<?= 10 + count($visibleUrlColumns) ?>" class="empty-state">
                            <strong>No channels found</strong>
                            <span><?= (int) $stats['total'] === 0 ? 'Add a channel to start building your playlist.' : 'Try a different search or category.' ?></span>
                            <?php if ((int) $stats['total'] === 0): ?><a class="button secondary small-button empty-action" href="index.php?add=1">Add channel</a><?php endif; ?>
                        </td></tr>
                    <?php endif; ?>
                    <?php foreach ($channels as $channel): ?>
                        <?php $urls = json_decode($channel['url_fields_json'], true) ?: []; ?>
                        <tr>
                            <td class="select-cell"><input type="checkbox" class="bulk-checkbox" name="ids[]" value="<?= (int) $channel['id'] ?>" form="bulk-delete-form" aria-label="Select <?= h($channel['name']) ?>"></td>
                            <td><div class="channel-cell">
                                <?= statusButton((int) $channel['id'], (bool) $channel['enabled']) ?>
                                <?php if ($channel['logo_url']): ?><img class="channel-logo" src="<?= h($channel['logo_url']) ?>" alt="" loading="lazy" referrerpolicy="no-referrer"><?php else: ?><span class="channel-logo placeholder">▶</span><?php endif; ?>
                                <div><strong><?= h($channel['name']) ?></strong><small><?= h($channel['tvg_name']) ?></small></div>
                            </div></td>
                            <td class="monospace"><?= h($channel['tvg_id']) ?></td>
                            <td><span class="category-chip"><?= h($channel['group_title'] ?: 'Uncategorized') ?></span></td>
                            <td><?= h($channel['tvg_language']) ?></td>
                            <td><?= h($channel['tvg_type']) ?></td>
                            <td class="url-cell"><?= urlLink($channel['logo_url']) ?></td>
                            <td class="url-cell"><?= urlLink($channel['stream_url']) ?></td>
                            <td class="url-cell"><?= urlLink($channel['license_key_url']) ?></td>
                            <?php foreach ($visibleUrlColumns as $field): ?>
                                <td class="url-cell"><?= urlLink(is_string($urls[$field] ?? null) ? $urls[$field] : '') ?></td>
                            <?php endforeach; ?>
                            <td><div class="manage-cell">
                                <?php $previewData = json_encode(channelPreviewData($channel), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?: '{}'; ?>
                                <button class="button small-button icon-button preview-button" type="button" data-preview="<?= h($previewData) ?>" title="Preview <?= h($channel['name']) ?>" aria-label="Preview <?= h($channel['name']) ?>"><?= playIcon() ?></button>
                                <a class="button small-button icon-button" href="?<?= h(http_build_query($listParams + ['edit' => (int) $channel['id']])) ?>" title="Edit <?= h($channel['name']) ?>" aria-label="Edit <?= h($channel['name']) ?>"><?= editIcon() ?></a>
                                <?= postButton('delete', (int) $channel['id'], 'Delete ' . $channel['name'], 'small-button danger-button icon-button', deleteIcon()) ?>
                            </div></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php if ($totalPages > 1): ?>
                <nav class="pagination" aria-label="Pagination">
                    <span>Page <?= $page ?> of <?= $totalPages ?></span>
                    <div>
                        <?php if ($page > 1): ?><a class="button secondary" href="?<?= h(http_build_query($baseQuery + ['page' => $page - 1])) ?>">Previous</a><?php endif; ?>
                        <?php if ($page < $totalPages): ?><a class="button secondary" href="?<?= h(http_build_query($baseQuery + ['page' => $page + 1])) ?>">Next</a><?php endif; ?>
                    </div>
                </nav>
            <?php endif; ?>
        </section>
        <?php endif; ?>
        <footer>IPTV Manager <span>·</span> Local playlist administration</footer>
    </main>
    <div class="logo-preview" id="logo-preview" hidden><img alt=""></div>
    <dialog class="player-dialog" id="preview-player" aria-labelledby="preview-player-title">
        <div class="player-shell">
            <header class="player-head">
                <div>
                    <p class="eyebrow">CHANNEL PREVIEW</p>
                    <h2 id="preview-player-title">Channel preview</h2>
                </div>
                <button class="button secondary small-button" type="button" id="preview-player-close">Close</button>
            </header>
            <video id="preview-player-video" controls playsinline></video>
            <p class="player-status" id="preview-player-status" role="status" hidden></p>
        </div>
    </dialog>
    <script>
    (() => {
        const preview = document.getElementById('logo-preview');
        if (!preview) {
            return;
        }
        const image = preview.querySelector('img');
        const hide = () => {
            preview.hidden = true;
        };
        document.querySelectorAll('img.channel-logo').forEach((logo) => {
            logo.addEventListener('mouseenter', () => {
                image.src = logo.currentSrc || logo.src;
                preview.hidden = false;
                preview.style.left = '0px';
                preview.style.top = '0px';
                const icon = logo.getBoundingClientRect();
                const box = preview.getBoundingClientRect();
                const gap = 12;
                let left = icon.right + gap;
                if (left + box.width > window.innerWidth - gap) {
                    left = icon.left - box.width - gap;
                }
                let top = icon.top + icon.height / 2 - box.height / 2;
                top = Math.max(gap, Math.min(top, window.innerHeight - box.height - gap));
                preview.style.left = Math.max(gap, left) + 'px';
                preview.style.top = top + 'px';
            });
            logo.addEventListener('mouseleave', hide);
        });
        window.addEventListener('scroll', hide, true);
        window.addEventListener('resize', hide);
    })();
    </script>
    <script>
    (() => {
        const dialog = document.getElementById('preview-player');
        const video = document.getElementById('preview-player-video');
        const title = document.getElementById('preview-player-title');
        const status = document.getElementById('preview-player-status');
        const closeButton = document.getElementById('preview-player-close');
        if (!dialog || !video || !title || !status || !closeButton) {
            return;
        }

        let player = null;
        let shakaPromise = null;
        let requestToken = 0;

        const setStatus = (message, isError = false) => {
            status.textContent = message;
            status.classList.toggle('error', isError);
            status.hidden = message === '';
        };

        const destroyPlayer = () => {
            const current = player;
            player = null;
            if (current) {
                Promise.resolve(current.destroy()).catch(() => {});
            }
            video.pause();
            video.removeAttribute('src');
            video.load();
        };

        const closeDialog = () => {
            if (dialog.open) {
                dialog.close();
            } else {
                requestToken++;
                destroyPlayer();
                setStatus('');
            }
        };

        const loadShaka = () => {
            if (window.shaka) {
                return Promise.resolve(window.shaka);
            }
            if (!shakaPromise) {
                shakaPromise = new Promise((resolve, reject) => {
                    const script = document.createElement('script');
                    script.src = 'https://cdn.jsdelivr.net/npm/shaka-player@4/dist/shaka-player.compiled.js';
                    script.onload = () => resolve(window.shaka);
                    script.onerror = () => {
                        shakaPromise = null;
                        reject(new Error('Could not load the Shaka Player library.'));
                    };
                    document.head.appendChild(script);
                });
            }
            return shakaPromise;
        };

        const useNativePlayback = (data) => !data.drmType && ['video/mp4', 'video/webm', 'video/ogg'].includes(data.mime);

        const playStream = async (data, token) => {
            destroyPlayer();
            setStatus('Loading stream...');
            if (!data.src) {
                setStatus('This channel has no stream URL.', true);
                return;
            }

            if (useNativePlayback(data)) {
                video.src = data.src;
                try {
                    await video.play();
                } catch (error) {
                    if (token === requestToken) {
                        setStatus(error.message || 'Unable to play this channel.', true);
                    }
                }
                return;
            }

            try {
                const shaka = await loadShaka();
                if (token !== requestToken) {
                    return;
                }
                if (!shaka.Player.isBrowserSupported()) {
                    throw new Error('This browser does not support the required media playback APIs.');
                }
                player = new shaka.Player(video);
                player.addEventListener('error', (event) => {
                    const detail = event.detail;
                    setStatus(detail instanceof Error ? detail.message : 'Playback failed.', true);
                });

                if (data.drmType) {
                    const types = {
                        clearkey: 'org.w3.clearkey',
                        widevine: 'com.widevine.alpha',
                        playready: 'com.microsoft.playready',
                    };
                    const drmType = types[data.drmType];
                    if (!drmType) {
                        throw new Error('Unknown DRM type for this channel.');
                    }
                    const drm = {};
                    if (data.keyId && data.key) {
                        drm.clearKeys = { [data.keyId]: data.key };
                    } else if (data.licenseUrl) {
                        drm.servers = { [drmType]: data.licenseUrl };
                    } else {
                        throw new Error('No DRM license information is configured for this channel.');
                    }

                    const headers = data.headers || {};
                    if (Object.keys(headers).length > 0) {
                        player.getNetworkingEngine().registerRequestFilter((type, request) => {
                            if (type === shaka.net.NetworkingEngine.RequestType.LICENSE) {
                                Object.assign(request.headers, headers);
                            }
                        });
                    }
                    player.configure({ drm });
                    setStatus('Loading ' + data.drmType + ' DRM protected stream...');
                }

                await player.load(data.src);
                if (token !== requestToken) {
                    return;
                }
                await video.play();
                setStatus(data.drmType ? 'Playing DRM protected stream.' : '');
            } catch (error) {
                if (token !== requestToken) {
                    return;
                }
                setStatus((error && error.message) ? error.message : 'Unable to play this channel.', true);
            }
        };

        closeButton.addEventListener('click', closeDialog);
        dialog.addEventListener('close', () => {
            requestToken++;
            destroyPlayer();
            setStatus('');
        });
        dialog.addEventListener('click', (event) => {
            if (event.target === dialog) {
                closeDialog();
            }
        });

        document.querySelectorAll('.preview-button').forEach((button) => {
            button.addEventListener('click', () => {
                let data;
                try {
                    data = JSON.parse(button.dataset.preview || '{}');
                } catch (error) {
                    setStatus('Invalid preview data for this channel.', true);
                    return;
                }
                requestToken++;
                title.textContent = data.name || 'Channel preview';
                if (!dialog.open) {
                    dialog.showModal();
                }
                playStream(data, requestToken);
            });
        });
    })();
    </script>
    <script>
    (() => {
        const selectAll = document.querySelector('.bulk-select-all');
        const bulkForm = document.getElementById('bulk-delete-form');
        if (!selectAll || !bulkForm) {
            return;
        }
        const boxes = Array.from(document.querySelectorAll('.bulk-checkbox'));
        const button = bulkForm.querySelector('button[type="submit"]');
        const syncButton = () => {
            if (button) {
                button.disabled = !boxes.some((box) => box.checked);
            }
        };
        selectAll.addEventListener('change', () => {
            boxes.forEach((box) => {
                box.checked = selectAll.checked;
            });
            syncButton();
        });
        boxes.forEach((box) => box.addEventListener('change', () => {
            if (!box.checked) {
                selectAll.checked = false;
            }
            syncButton();
        }));
        syncButton();
    })();
    </script>
<?php endif; ?>
</body>
</html>
