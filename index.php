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
            $visibility = normalizeFeedVisibility((string) ($_POST['visibility'] ?? 'both'));
            $encodedProperties = json_encode((object) $properties, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            if ($encodedProperties === false) {
                throw new RuntimeException('Unable to encode the KODIPROP properties.');
            }
            $statement = $db->prepare(
                'INSERT INTO channels (source_id, name, tvg_id, tvg_name, group_title, tvg_language, tvg_type, '
                . 'logo_url, attributes_json, properties_json, url_fields_json, enabled, updated_at) '
                . 'VALUES (:source_id, :name, :tvg_id, :tvg_name, :group_title, :language, :type, '
                . ':logo_url, :attributes, :properties, :url_fields, :enabled, :updated_at)'
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
                'attributes' => '{}',
                'properties' => $encodedProperties,
                'url_fields' => '{}',
                'enabled' => isset($_POST['enabled']) ? 1 : 0,
                'updated_at' => gmdate('Y-m-d H:i:s'),
            ]);
            insertFeed($db, (int) $db->lastInsertId(), [
                'label' => trim((string) ($_POST['feed_label'] ?? '')),
                'stream_url' => $streamUrl,
                'license' => $licenseKeyUrl,
                'drm' => $drm,
                'properties' => $properties,
                'visibility' => $visibility,
                'enabled' => 1,
                'sort_order' => 0,
            ]);
            bumpCacheVersion($db);
            flash('Channel added.');
            redirect();
        } elseif ($action === 'edit') {
            $name = trim((string) ($_POST['name'] ?? ''));
            if ($name === '') {
                throw new RuntimeException('Channel name is required.');
            }
            $statement = $db->prepare(
                'UPDATE channels SET name = :name, tvg_id = :tvg_id, tvg_name = :tvg_name, '
                . 'group_title = :group_title, tvg_language = :language, tvg_type = :type, '
                . 'logo_url = :logo_url, enabled = :enabled, updated_at = :updated_at '
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
                'enabled' => isset($_POST['enabled']) ? 1 : 0,
                'updated_at' => gmdate('Y-m-d H:i:s'),
                'id' => filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT),
            ]);
            flash('Channel details saved.');
        } elseif ($action === 'channel_rename') {
            $channelId = (int) filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT);
            $name = trim((string) ($_POST['name'] ?? ''));
            if ($name === '') {
                throw new RuntimeException('Channel name is required.');
            }
            $statement = $db->prepare('UPDATE channels SET name = :name, tvg_name = :tvg_name, updated_at = :updated_at WHERE id = :id');
            $statement->execute([
                'name' => $name,
                'tvg_name' => trim((string) ($_POST['tvg_name'] ?? '')),
                'updated_at' => gmdate('Y-m-d H:i:s'),
                'id' => $channelId,
            ]);
            flash('Channel renamed to "' . $name . '".');
        } elseif ($action === 'channel_logo') {
            $channelId = (int) filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT);
            if ($channelId <= 0) {
                throw new RuntimeException('Channel not found.');
            }
            $check = $db->prepare('SELECT id FROM channels WHERE id = :id');
            $check->execute(['id' => $channelId]);
            if (!$check->fetchColumn()) {
                throw new RuntimeException('Channel not found.');
            }

            $logoUrl = trim((string) ($_POST['logo_url'] ?? ''));
            $uploaded = $_FILES['logo_file'] ?? null;
            $hasUpload = is_array($uploaded) && (int) ($uploaded['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE;

            if ($hasUpload) {
                if ((int) $uploaded['error'] !== UPLOAD_ERR_OK) {
                    throw new RuntimeException('The logo upload failed (error code ' . (int) $uploaded['error'] . ').');
                }
                $temporary = (string) ($uploaded['tmp_name'] ?? '');
                if ($temporary === '' || !is_uploaded_file($temporary)) {
                    throw new RuntimeException('The uploaded logo file is not readable.');
                }
                if ((int) ($uploaded['size'] ?? 0) > 5 * 1024 * 1024) {
                    throw new RuntimeException('The logo image must be 5 MB or smaller.');
                }
                $logoUrl = storeUploadedLogo($temporary, (string) ($uploaded['name'] ?? 'logo'));
            }

            if ($logoUrl !== '' && !filter_var($logoUrl, FILTER_VALIDATE_URL) && !str_starts_with($logoUrl, 'logo.php?')) {
                throw new RuntimeException('Enter a valid image URL or choose a file to upload.');
            }

            $statement = $db->prepare('UPDATE channels SET logo_url = :logo_url, updated_at = :updated_at WHERE id = :id');
            $statement->execute([
                'logo_url' => $logoUrl,
                'updated_at' => gmdate('Y-m-d H:i:s'),
                'id' => $channelId,
            ]);
            flash($logoUrl === '' ? 'Logo removed.' : 'Channel logo updated.');
        } elseif ($action === 'feed_create' || $action === 'feed_update') {
            $isCreate = $action === 'feed_create';
            $channelId = (int) filter_input(INPUT_POST, 'channel_id', FILTER_VALIDATE_INT);
            $feedId = (int) filter_input(INPUT_POST, 'feed_id', FILTER_VALIDATE_INT);
            if (!$isCreate) {
                $feedStatement = $db->prepare('SELECT id, channel_id FROM feeds WHERE id = :id');
                $feedStatement->execute(['id' => $feedId]);
                $feedRow = $feedStatement->fetch();
                if (!$feedRow) {
                    throw new RuntimeException('Feed not found.');
                }
                $channelId = (int) $feedRow['channel_id'];
            }
            $channelStatement = $db->prepare('SELECT id, name FROM channels WHERE id = :id');
            $channelStatement->execute(['id' => $channelId]);
            $channelRow = $channelStatement->fetch();
            if (!$channelRow) {
                throw new RuntimeException('Channel not found.');
            }
            $streamUrl = trim((string) ($_POST['stream_url'] ?? ''));
            if ($streamUrl === '') {
                throw new RuntimeException('Stream URL is required.');
            }
            $duplicateStatement = $db->prepare(
                'SELECT id FROM feeds WHERE channel_id = :channel_id AND stream_url = :stream_url AND id != :id'
            );
            $duplicateStatement->execute([
                'channel_id' => $channelId,
                'stream_url' => $streamUrl,
                'id' => $isCreate ? -1 : $feedId,
            ]);
            if ($duplicateStatement->fetchColumn()) {
                throw new RuntimeException('This channel already has a feed with that stream URL.');
            }
            $properties = parseChannelProperties((string) ($_POST['properties'] ?? ''));
            $licenseKeyUrl = trim((string) ($_POST['license_key_url'] ?? ''));
            $drm = readDrmFields($_POST, $licenseKeyUrl);
            $visibility = normalizeFeedVisibility((string) ($_POST['visibility'] ?? 'both'));
            $feedData = [
                'label' => trim((string) ($_POST['feed_label'] ?? '')),
                'stream_url' => $streamUrl,
                'license' => $licenseKeyUrl,
                'drm' => $drm,
                'properties' => $properties,
                'visibility' => $visibility,
            ];
            if ($isCreate) {
                $maxSort = (int) $db->query('SELECT COALESCE(MAX(sort_order), -1) + 1 FROM feeds WHERE channel_id = ' . $channelId)->fetchColumn();
                $feedData['enabled'] = 1;
                $feedData['sort_order'] = max(0, $maxSort);
                insertFeed($db, $channelId, $feedData);
                flash('Feed added to ' . $channelRow['name'] . '.');
            } else {
                updateFeed($db, $feedId, $feedData);
                flash('Feed saved.');
            }
        } elseif ($action === 'feed_delete') {
            $statement = $db->prepare('DELETE FROM feeds WHERE id = :id');
            $statement->execute(['id' => filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT)]);
            flash('Feed deleted.');
        } elseif ($action === 'feed_toggle') {
            $statement = $db->prepare('UPDATE feeds SET enabled = CASE enabled WHEN 1 THEN 0 ELSE 1 END WHERE id = :id');
            $statement->execute(['id' => filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT)]);
            flash('Feed status updated.');
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
        } elseif ($action === 'debug_toggle') {
            $enabled = !requestDebugEnabled();
            setRequestDebugMode($enabled);
            flash($enabled
                ? 'Debug mode on — every request to all three servers is now logged.'
                : 'Debug mode off — only user-agent security events are logged.');
        } elseif ($action === 'log_clear') {
            clearRequestLog();
            flash('Request log cleared.');
        } elseif ($action === 'ua_settings') {
            $enforced = (string) ($_POST['enforce'] ?? '0') === '1';
            setUserAgentEnforced($enforced);
            flash($enforced
                ? 'User-agent enforcement enabled — rules are applied to both playlist servers.'
                : 'User-agent enforcement disabled — all clients get the default playlist.');
        } elseif ($action === 'ua_rule_save') {
            $ruleId = trim((string) ($_POST['id'] ?? ''));
            $pattern = trim((string) ($_POST['pattern'] ?? ''));
            validateUserAgentPattern($pattern);
            $ruleAction = (string) ($_POST['rule_action'] ?? '');
            if (!array_key_exists($ruleAction, userAgentRuleActions())) {
                throw new RuntimeException('Unknown rule action.');
            }
            $rule = [
                'id' => $ruleId !== '' ? $ruleId : bin2hex(random_bytes(6)),
                'pattern' => $pattern,
                'action' => $ruleAction,
                'note' => trim((string) ($_POST['note'] ?? '')),
                'enabled' => isset($_POST['rule_enabled']),
            ];
            $rules = userAgentRules();
            $replaced = false;
            if ($ruleId !== '') {
                foreach ($rules as $index => $existing) {
                    if ((string) $existing['id'] === $ruleId) {
                        $rules[$index] = $rule;
                        $replaced = true;
                        break;
                    }
                }
                if (!$replaced) {
                    throw new RuntimeException('Rule not found.');
                }
            } else {
                $rules[] = $rule;
            }
            saveUserAgentRules($rules);
            flash($replaced ? 'Rule updated.' : 'Rule added.');
        } elseif ($action === 'ua_rule_delete') {
            $ruleId = (string) ($_POST['id'] ?? '');
            $rules = array_values(array_filter(
                userAgentRules(),
                static fn (array $rule): bool => (string) $rule['id'] !== $ruleId
            ));
            saveUserAgentRules($rules);
            flash('Rule deleted.');
        } elseif ($action === 'ua_rule_move') {
            $ruleId = (string) ($_POST['id'] ?? '');
            $direction = (string) ($_POST['dir'] ?? '') === 'up' ? -1 : 1;
            $rules = userAgentRules();
            foreach ($rules as $index => $rule) {
                if ((string) $rule['id'] !== $ruleId) {
                    continue;
                }
                $target = $index + $direction;
                if ($target < 0 || $target >= count($rules)) {
                    break;
                }
                [$rules[$index], $rules[$target]] = [$rules[$target], $rules[$index]];
                flash('Rule order updated — the first matching rule wins.');
                break;
            }
            saveUserAgentRules($rules);
        } else {
            throw new RuntimeException('Unknown action.');
        }
        if (!in_array($action, ['debug_toggle', 'log_clear', 'ua_settings', 'ua_rule_save', 'ua_rule_delete', 'ua_rule_move'], true)) {
            bumpCacheVersion($db);
        }
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
        'log' => isset($_GET['log']) ? '1' : null,
        'security' => isset($_GET['security']) ? '1' : null,
        'test' => trim((string) ($_GET['test'] ?? '')) ?: null,
        'edit_rule' => trim((string) ($_GET['edit_rule'] ?? '')) ?: null,
        'edit' => isset($_GET['edit']) ? (string) max(0, (int) $_GET['edit']) : null,
    ], static fn ($value): bool => is_string($value) && $value !== ''));
    redirect('index.php' . ($query !== '' ? '?' . $query : ''));
}

$loggedIn = isset($_SESSION['admin']);
$guideMode = $loggedIn && isset($_GET['guide']);
$logMode = $loggedIn && isset($_GET['log']);
$securityMode = $loggedIn && isset($_GET['security']);
$flashMessage = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

if (isset($_GET['tail'])) {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    if (!$loggedIn) {
        http_response_code(401);
        echo json_encode(['error' => 'Sign-in required.']);
        exit;
    }
    $logSize = is_file(requestLogPath()) ? (int) filesize(requestLogPath()) : 0;
    echo (string) json_encode([
        'debug' => requestDebugEnabled(),
        'size' => $logSize,
        'entries' => readRequestLog(200),
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

function h(?string $value): string
{
    return htmlspecialchars($value ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function postButton(string $action, int $id, string $label, string $class = '', string $icon = '', string $confirm = ''): string
{
    $attributes = $icon !== '' ? ' title="' . h($label) . '" aria-label="' . h($label) . '"' : '';
    $content = $icon !== '' ? $icon : h($label);
    $confirmJs = 'true';
    if ($confirm !== '') {
        $confirmJs = "confirm('" . addslashes($confirm) . "')";
    } elseif ($action === 'delete') {
        $confirmJs = "confirm('Delete this channel?')";
    }
    return '<form method="post" class="inline-form" onsubmit="return '
        . $confirmJs
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

function channelPreviewData(array $row, string $name): array
{
    $drm = channelDrmSettings($row);
    $headers = [];
    try {
        $headers = parseDrmHeaders((string) ($row['drm_headers'] ?? ''));
    } catch (RuntimeException) {
        $headers = [];
    }
    if ($headers === []) {
        $properties = json_decode((string) ($row['properties_json'] ?? ''), true);
        $licenseProperty = is_array($properties) ? ($properties['inputstream.adaptive.license_key'] ?? '') : '';
        if (is_string($licenseProperty)) {
            $segments = explode('|', $licenseProperty);
            if (isset($segments[1]) && $segments[1] !== '') {
                parse_str($segments[1], $parsedHeaders);
                foreach ($parsedHeaders as $headerName => $value) {
                    if (is_string($headerName) && is_string($value)) {
                        $headers[$headerName] = $value;
                    }
                }
            }
        }
    }

    $path = parse_url((string) ($row['stream_url'] ?? ''), PHP_URL_PATH);
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
        'name' => $name,
        'src' => (string) ($row['stream_url'] ?? ''),
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

function feedStatusButton(int $id, bool $enabled): string
{
    $label = $enabled ? 'Disable feed' : 'Enable feed';
    return '<form method="post" class="status-form">'
        . '<input type="hidden" name="csrf" value="' . h(csrfToken()) . '">'
        . '<input type="hidden" name="action" value="feed_toggle">'
        . '<input type="hidden" name="id" value="' . $id . '">'
        . '<button class="status-dot ' . ($enabled ? 'enabled' : 'disabled') . '" type="submit" title="' . h($label) . '" aria-label="' . h($label) . '"></button>'
        . '</form>';
}

function feedVisibilityLabels(): array
{
    return [
        'playlist' => 'Playlist server only',
        'clear' => 'Clear server only',
        'both' => 'Both servers',
    ];
}

function feedVisibilitySelect(string $selected): string
{
    $html = '<select name="visibility">';
    foreach (feedVisibilityLabels() as $value => $label) {
        $html .= '<option value="' . h($value) . '"' . ($value === $selected ? ' selected' : '') . '>' . h($label) . '</option>';
    }
    return $html . '</select>';
}

function feedVisibilityShort(string $visibility): string
{
    return match ($visibility) {
        'playlist' => 'playlist',
        'clear' => 'clear',
        'both' => 'both',
        default => $visibility,
    };
}

function feedPropertiesText(array $feed): string
{
    $lines = '';
    $properties = json_decode((string) ($feed['properties_json'] ?? ''), true);
    if (is_array($properties)) {
        foreach ($properties as $name => $value) {
            if (is_string($name) && is_string($value)) {
                $lines .= $name . '=' . $value . "\n";
            }
        }
    }
    return rtrim($lines);
}

function feedFormFields(array $feed, string $propertiesText, string $visibility): string
{
    $drm = channelDrmSettings($feed);
    $drmTypes = ['' => 'None / KODIPROP only', 'clearkey' => 'ClearKey', 'widevine' => 'Widevine', 'playready' => 'PlayReady'];
    $drmSelect = '<select name="drm_type">';
    foreach ($drmTypes as $value => $label) {
        $drmSelect .= '<option value="' . h($value) . '"' . (($drm['type'] ?? '') === $value ? ' selected' : '') . '>' . h($label) . '</option>';
    }
    $drmSelect .= '</select>';

    return '<label>Feed label<input name="feed_label" value="' . h((string) ($feed['label'] ?? '')) . '" placeholder="Optional, e.g. HD"></label>'
        . '<label>Visible on' . feedVisibilitySelect($visibility) . '<small>The playlist server serves feeds assigned to "playlist" or "both"; the clear server serves "clear" or "both".</small></label>'
        . '<label class="span-2">Stream URL<input name="stream_url" value="' . h((string) ($feed['stream_url'] ?? '')) . '" required></label>'
        . '<label class="span-2">License key URL<input name="license_key_url" value="' . h((string) ($drm['license'] ?? '')) . '"></label>'
        . '<label>DRM type' . $drmSelect . '<small>Widevine and PlayReady require a license URL.</small></label>'
        . '<label>DRM license headers<textarea name="drm_headers" rows="2" spellcheck="false" placeholder="Authorization: Bearer token">' . h((string) ($feed['drm_headers'] ?? '')) . '</textarea><small>One Name: value header per line, sent with license requests.</small></label>'
        . '<label>ClearKey key ID<input name="drm_key_id" value="' . h((string) ($drm['key_id'] ?? '')) . '" placeholder="32 hex characters"></label>'
        . '<label>ClearKey key<input name="drm_key" value="' . h((string) ($drm['key'] ?? '')) . '" placeholder="32 hex characters"></label>'
        . '<label class="span-2">KODIPROP properties<textarea name="properties" rows="6" spellcheck="false" placeholder="inputstream.adaptive.manifest_type=mpd">' . h($propertiesText) . '</textarea><small>One name=value pair per line. These become #KODIPROP entries in the playlist. Dedicated DRM fields override matching keys.</small></label>';
}

function feedDrmWarning(array $feed): string
{
    $visibility = (string) ($feed['visibility'] ?? 'both');
    if (!channelHasDrm($feed) || $visibility === 'playlist') {
        return '';
    }
    return '<p class="feed-warning">This feed has DRM settings but is visible on the clear server.</p>';
}

function logRowHtml(array $entry): string
{
    $status = (int) ($entry['status'] ?? 0);
    $statusClass = $status >= 500 ? 'st-error' : ($status >= 400 ? 'st-block' : 'st-ok');
    $timestamp = str_replace('T', ' ', substr((string) ($entry['t'] ?? ''), 0, 19));
    $service = (string) ($entry['service'] ?? '');
    $note = (string) ($entry['note'] ?? '');
    $noteHtml = $note !== ''
        ? '<span class="note-chip">' . h($note) . '</span>'
        : '<span class="empty-value">—</span>';
    $detail = h((string) json_encode($entry, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

    return '<tr>'
        . '<td class="mono">' . h($timestamp) . '</td>'
        . '<td><span class="service-chip service-' . h($service) . '">' . h($service !== '' ? $service : '—') . '</span></td>'
        . '<td class="mono ' . $statusClass . '">' . $status . '</td>'
        . '<td class="mono">' . h((string) ($entry['method'] ?? '')) . '</td>'
        . '<td class="mono uri-cell" title="' . h((string) ($entry['uri'] ?? '')) . '">' . h((string) ($entry['uri'] ?? '')) . '</td>'
        . '<td class="mono">' . h((string) ($entry['ip'] ?? '')) . '</td>'
        . '<td class="mono ua-cell" title="' . h((string) ($entry['ua'] ?? '')) . '">' . h((string) ($entry['ua'] ?? '')) . '</td>'
        . '<td>' . $noteHtml . '</td>'
        . '<td><button type="button" class="button secondary small-button log-detail-button" data-detail="' . $detail . '">Details</button></td>'
        . '</tr>';
}

function logDetailFields(array $entry): array
{
    return [
        'Time' => str_replace('T', ' ', substr((string) ($entry['t'] ?? ''), 0, 25)),
        'Server' => (string) ($entry['service'] ?? ''),
        'Method' => (string) ($entry['method'] ?? ''),
        'Status' => (string) ($entry['status'] ?? ''),
        'Duration' => isset($entry['ms']) ? $entry['ms'] . ' ms' : '',
        'Client IP' => (string) ($entry['ip'] ?? ''),
        'Protocol' => (string) ($entry['proto'] ?? ''),
        'Host' => (string) ($entry['host'] ?? ''),
        'Referer' => (string) ($entry['referer'] ?? ''),
        'Path' => (string) ($entry['uri'] ?? ''),
        'User agent' => (string) ($entry['ua'] ?? ''),
        'Security decision' => (string) ($entry['note'] ?? ''),
    ];
}

?><!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= $loggedIn ? h($logMode ? 'Live log · IPTV Manager' : ($securityMode ? 'Security · IPTV Manager' : ($guideMode ? 'TV Guide · IPTV Manager' : 'Channels · IPTV Manager'))) : 'Sign in · IPTV Manager' ?></title>
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
        $where[] = '(name LIKE :search OR tvg_id LIKE :search OR group_title LIKE :search '
            . 'OR EXISTS (SELECT 1 FROM feeds f WHERE f.channel_id = channels.id AND f.stream_url LIKE :search))';
        $parameters['search'] = '%' . $search . '%';
    }
    if ($category !== '') {
        $where[] = 'group_title = :category';
        $parameters['category'] = $category;
    }
    $whereSql = $where ? ' WHERE ' . implode(' AND ', $where) : '';
    $listData = databaseCache(
        $db,
        'v2:channels:' . sha1((string) json_encode([$search, $category, $page, $pageSize])),
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

    $feedSummaries = [];
    if ($channels !== []) {
        $channelIds = array_map(static fn (array $channel): int => (int) $channel['id'], $channels);
        $placeholders = implode(',', array_fill(0, count($channelIds), '?'));
        $feedStatement = $db->prepare(
            'SELECT * FROM feeds WHERE channel_id IN (' . $placeholders . ') ORDER BY sort_order, id'
        );
        $feedStatement->execute($channelIds);
        foreach ($feedStatement->fetchAll() as $feedRow) {
            $feedSummaries[(int) $feedRow['channel_id']][] = $feedRow;
        }
    }

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
    if ($logMode) {
        $debugMode = requestDebugEnabled();
        $logEntries = readRequestLog(200);
    }
    if ($securityMode) {
        $uaEnforced = userAgentEnforced();
        $uaRules = userAgentRules();
        $uaActions = userAgentRuleActions();
        $uaActionLabels = [
            'serve' => 'Allow · default playlist',
            'playlist' => 'Allow · full playlist',
            'clear' => 'Allow · clear playlist',
            'block' => 'Deny (404)',
        ];
        $editRule = null;
        $editRuleId = trim((string) ($_GET['edit_rule'] ?? ''));
        if ($editRuleId !== '') {
            foreach ($uaRules as $rule) {
                if ((string) $rule['id'] === $editRuleId) {
                    $editRule = $rule;
                    break;
                }
            }
        }
        $uaTest = trim((string) ($_GET['test'] ?? ''));
        $uaTestResult = $uaTest !== '' ? evaluateUserAgentRules($uaTest, $uaRules) : null;
    }
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
    $editFeeds = [];
    if ($editChannel) {
        $editFeeds = feedsForChannel($db, (int) $editChannel['id']);
    }
    ?>
    <header class="topbar">
        <a class="brand" href="index.php"><span class="brand-mark small">▶</span><span>IPTV <b>Manager</b></span></a>
        <div class="topbar-right">
            <a class="button text-button" href="index.php"<?= (!$guideMode && !$logMode && !$securityMode && !$addChannel && !$importPlaylist && !$manageCategories && !$editChannel) ? ' aria-current="page"' : '' ?>>Dashboard</a>
            <a class="button text-button" href="index.php?guide=1"<?= $guideMode ? ' aria-current="page"' : '' ?>>TV Guide</a>
            <a class="button text-button" href="index.php?log=1"<?= $logMode ? ' aria-current="page"' : '' ?>>Live log</a>
            <a class="button text-button" href="index.php?security=1"<?= $securityMode ? ' aria-current="page"' : '' ?>>Security</a>
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
            <div class="user-menu">
                <button class="user-menu-toggle" type="button" id="user-menu-toggle" aria-haspopup="true" aria-expanded="false">
                    <span class="user-avatar" aria-hidden="true"><?= h(strtoupper(substr((string) $_SESSION['admin'], 0, 1))) ?></span>
                    <span class="user-name"><?= h((string) $_SESSION['admin']) ?></span>
                    <svg class="user-caret" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="m6 9 6 6 6-6"/></svg>
                </button>
                <div class="user-menu-panel" id="user-menu-panel" hidden>
                    <div class="user-menu-head">
                        <span class="user-avatar large" aria-hidden="true"><?= h(strtoupper(substr((string) $_SESSION['admin'], 0, 1))) ?></span>
                        <div>
                            <strong><?= h((string) $_SESSION['admin']) ?></strong>
                            <small><span class="online-dot"></span>Signed in</small>
                        </div>
                    </div>
                    <form method="post" class="user-menu-form">
                        <input type="hidden" name="csrf" value="<?= h(csrfToken()) ?>">
                        <input type="hidden" name="action" value="logout">
                        <button class="user-menu-item" type="submit">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><path d="m16 17 5-5-5-5"/><path d="M21 12H9"/></svg>
                            Sign out
                        </button>
                    </form>
                </div>
            </div>
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
                        <p class="muted">Add a channel and its first feed. TVG fields and KODIPROP properties are written to the generated playlist. You can add more feeds after saving.</p>
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
                        <label class="checkbox-label"><input type="checkbox" name="enabled" value="1" checked> Include channel in playlist</label>
                    </div>
                    <div class="form-section-head">
                        <h3>First feed</h3>
                        <p class="muted">A channel needs at least one feed to appear in a playlist. Add more feeds after saving the channel.</p>
                    </div>
                    <div class="form-grid">
                        <?= feedFormFields([], '', 'both') ?>
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
                        <p class="muted">Upload an M3U/M3U8 file or enter a playlist URL. Entries with the same channel name become feeds of one channel; existing feeds keep their visible-on and enabled settings. #KODIPROP DRM settings are mapped to the dedicated DRM fields automatically.</p>
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
                        <p class="muted">Update the channel details shared by all of its feeds.</p>
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
                        <label class="checkbox-label"><input type="checkbox" name="enabled" value="1" <?= $editChannel['enabled'] ? 'checked' : '' ?>> Include channel in playlist</label>
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
            <section class="form-card feeds-card">
                <div class="form-card-head">
                    <div>
                        <p class="eyebrow">CHANNEL FEEDS</p>
                        <h2>Feeds</h2>
                        <p class="muted">Each feed is one stream for this channel. A feed can be encrypted or clear, and you choose which server serves it.</p>
                    </div>
                </div>
                <?php if (!$editFeeds): ?>
                    <p class="category-empty muted">No feeds yet. Add one below so this channel appears in a playlist.</p>
                <?php endif; ?>
                <?php foreach ($editFeeds as $feedIndex => $feed): ?>
                    <?php
                    $feedVisibility = (string) $feed['visibility'];
                    $feedLabel = trim((string) $feed['label']);
                    $feedTitle = $feedLabel !== '' ? $feedLabel : 'Feed ' . ($feedIndex + 1);
                    $previewName = (string) $editChannel['name'] . ($feedLabel !== '' ? ' · ' . $feedLabel : '');
                    $feedPreview = json_encode(channelPreviewData($feed, $previewName), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?: '{}';
                    ?>
                    <article class="feed-card">
                        <header class="feed-card-head">
                            <div class="feed-card-title">
                                <?= feedStatusButton((int) $feed['id'], (bool) $feed['enabled']) ?>
                                <strong><?= h($feedTitle) ?></strong>
                                <span class="feed-chip"><?= h(feedVisibilityShort($feedVisibility)) ?></span>
                                <?php if (channelHasDrm($feed)): ?><span class="feed-chip drm-chip">encrypted</span><?php endif; ?>
                                <?php if (!(int) $feed['enabled']): ?><span class="feed-chip muted-chip">disabled</span><?php endif; ?>
                            </div>
                            <div class="feed-actions">
                                <button class="button small-button icon-button preview-button" type="button" data-preview="<?= h($feedPreview) ?>" title="Preview <?= h($feedTitle) ?>" aria-label="Preview <?= h($feedTitle) ?>"><?= playIcon() ?></button>
                                <?= postButton('feed_delete', (int) $feed['id'], 'Delete feed', 'small-button danger-button icon-button', deleteIcon(), 'Delete this feed?') ?>
                            </div>
                        </header>
                        <form method="post" class="channel-edit-form">
                            <input type="hidden" name="csrf" value="<?= h(csrfToken()) ?>">
                            <input type="hidden" name="action" value="feed_update">
                            <input type="hidden" name="feed_id" value="<?= (int) $feed['id'] ?>">
                            <input type="hidden" name="channel_id" value="<?= (int) $editChannel['id'] ?>">
                            <div class="form-grid">
                                <?= feedFormFields($feed, feedPropertiesText($feed), $feedVisibility) ?>
                            </div>
                            <?= feedDrmWarning($feed) ?>
                            <div class="form-actions">
                                <button class="button primary" type="submit">Save feed</button>
                            </div>
                        </form>
                    </article>
                <?php endforeach; ?>
                <article class="feed-card add-feed-card">
                    <h3>Add feed</h3>
                    <form method="post" class="channel-edit-form">
                        <input type="hidden" name="csrf" value="<?= h(csrfToken()) ?>">
                        <input type="hidden" name="action" value="feed_create">
                        <input type="hidden" name="channel_id" value="<?= (int) $editChannel['id'] ?>">
                        <div class="form-grid">
                            <?= feedFormFields([], '', 'both') ?>
                        </div>
                        <div class="form-actions">
                            <button class="button primary" type="submit">Add feed</button>
                        </div>
                    </form>
                </article>
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
        <?php elseif ($logMode): ?>
            <section class="form-card">
                <div class="form-card-head">
                    <div>
                        <p class="eyebrow">LIVE LOG · DEBUG MODE</p>
                        <h2>Request log</h2>
                        <p class="muted">While debug mode is on, every request received by the web, playlist and clear servers is recorded here — client IP, user agent, path, status and timing. User-agent security decisions are recorded even when debug mode is off.</p>
                    </div>
                    <div class="log-controls">
                        <form method="post" action="index.php?log=1" class="inline-form">
                            <input type="hidden" name="csrf" value="<?= h(csrfToken()) ?>">
                            <input type="hidden" name="action" value="debug_toggle">
                            <button class="button <?= $debugMode ? 'danger-button' : 'primary' ?>" type="submit">Debug mode: <?= $debugMode ? 'ON' : 'OFF' ?></button>
                        </form>
                        <form method="post" action="index.php?log=1" class="inline-form" onsubmit="return confirm('Clear the request log?')">
                            <input type="hidden" name="csrf" value="<?= h(csrfToken()) ?>">
                            <input type="hidden" name="action" value="log_clear">
                            <button class="button secondary" type="submit">Clear log</button>
                        </form>
                        <label class="log-live"><input type="checkbox" id="log-autorefresh" checked> Live</label>
                    </div>
                </div>
                <div class="state-row">
                    <span class="state-dot <?= $debugMode ? 'on' : 'off' ?>" id="log-indicator"></span>
                    <span class="muted" id="log-state"><?= $debugMode
                        ? 'Debug mode on — recording all requests from every server.'
                        : 'Debug mode off — only user-agent security events are recorded.' ?></span>
                </div>
                <div class="log-filters">
                    <div class="search-box log-search">
                        <span>⌕</span>
                        <input type="search" id="log-filter-text" placeholder="Filter by path, IP or user agent…" aria-label="Filter log entries">
                    </div>
                    <select id="log-filter-service" aria-label="Filter by server">
                        <option value="">All servers</option>
                        <option value="web">Web UI</option>
                        <option value="playlist">Playlist</option>
                        <option value="clear">Clear</option>
                    </select>
                    <select id="log-filter-status" aria-label="Filter by status">
                        <option value="">All statuses</option>
                        <option value="200">Success (2xx)</option>
                        <option value="400">Client error (4xx)</option>
                        <option value="500">Server error (5xx)</option>
                    </select>
                    <select id="log-filter-security" aria-label="Filter by security decision">
                        <option value="">All decisions</option>
                        <option value="allowed">Allowed (rule matched)</option>
                        <option value="denied">Denied (no rule)</option>
                    </select>
                    <span class="log-filter-count muted" id="log-filter-count"></span>
                    <button class="button secondary small-button" type="button" id="log-filter-clear" hidden>Clear filters</button>
                </div>
                <div class="table-wrap">
                    <table class="log-table">
                        <thead>
                            <tr><th>Time</th><th>Server</th><th>Status</th><th>Method</th><th>Path</th><th>Client IP</th><th>User agent</th><th>Security decision</th><th class="log-detail-head">Detail</th></tr>
                        </thead>
                        <tbody id="log-rows">
                            <?php foreach ($logEntries as $entry): ?>
                                <?= logRowHtml($entry) ?>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <div class="guide-empty" id="log-empty"<?= $logEntries !== [] ? ' hidden' : '' ?>>
                    <strong>No requests recorded yet</strong>
                    <span>Turn debug mode on, then load the playlist feed — new lines appear here within two seconds.</span>
                </div>
                <div class="guide-empty" id="log-no-match" hidden>
                    <strong>No requests match the filters</strong>
                    <span>Adjust or clear the filters to see more entries.</span>
                </div>
            </section>
            <dialog class="detail-dialog" id="log-detail">
                <div class="detail-shell">
                    <header class="detail-head">
                        <div>
                            <p class="eyebrow">REQUEST DETAIL</p>
                            <h2 id="log-detail-title">Request</h2>
                        </div>
                        <button class="button secondary small-button" type="button" id="log-detail-close">Close</button>
                    </header>
                    <dl class="detail-grid" id="log-detail-body"></dl>
                    <div class="detail-section">
                        <h3>Request headers</h3>
                        <pre class="detail-pre" id="log-detail-headers">No headers recorded.</pre>
                    </div>
                </div>
            </dialog>
            <script>
            (() => {
                const dialog = document.getElementById('log-detail');
                const title = document.getElementById('log-detail-title');
                const body = document.getElementById('log-detail-body');
                const headersPre = document.getElementById('log-detail-headers');
                const closeButton = document.getElementById('log-detail-close');
                window.showDetails = (entry) => {
                    if (!dialog || !body) {
                        return;
                    }
                    title.textContent = String(entry.method || '') + ' ' + String(entry.uri || '');
                    body.textContent = '';
                    const fields = [
                        ['Time', String(entry.t || '').replace('T', ' ').slice(0, 25)],
                        ['Server', entry.service || ''],
                        ['Method', entry.method || ''],
                        ['Status', String(entry.status ?? '')],
                        ['Duration', (entry.ms ?? '') + ' ms'],
                        ['Client IP', entry.ip || ''],
                        ['Protocol', entry.proto || ''],
                        ['Host', entry.host || ''],
                        ['Referer', entry.referer || ''],
                        ['Path', entry.uri || ''],
                        ['User agent', entry.ua || ''],
                        ['Security decision', entry.note || '—'],
                    ];
                    fields.forEach(([label, value]) => {
                        const dt = document.createElement('dt');
                        dt.textContent = label;
                        const dd = document.createElement('dd');
                        dd.textContent = value === '' ? '—' : String(value);
                        body.appendChild(dt);
                        body.appendChild(dd);
                    });
                    const headers = entry.headers && typeof entry.headers === 'object' ? entry.headers : null;
                    if (headers && Object.keys(headers).length > 0) {
                        headersPre.textContent = Object.entries(headers)
                            .map(([name, value]) => name + ': ' + value)
                            .join('\n');
                    } else {
                        headersPre.textContent = 'No headers recorded.';
                    }
                    if (!dialog.open) {
                        dialog.showModal();
                    }
                };
                if (closeButton) {
                    closeButton.addEventListener('click', () => dialog.close());
                }
                if (dialog) {
                    dialog.addEventListener('click', (event) => {
                        if (event.target === dialog) {
                            dialog.close();
                        }
                    });
                }
            })();
            </script>
            <script>
            (() => {
                const rows = document.getElementById('log-rows');
                const empty = document.getElementById('log-empty');
                const state = document.getElementById('log-state');
                const indicator = document.getElementById('log-indicator');
                const liveToggle = document.getElementById('log-autorefresh');
                if (!rows || !liveToggle) {
                    return;
                }

                const cell = (text, className) => {
                    const td = document.createElement('td');
                    td.textContent = text;
                    if (className) {
                        td.className = className;
                    }
                    return td;
                };

                const buildRow = (entry) => {
                    const tr = document.createElement('tr');
                    const time = String(entry.t || '').replace('T', ' ').slice(0, 19);
                    const status = Number(entry.status || 0);
                    const statusClass = status >= 500 ? 'st-error' : (status >= 400 ? 'st-block' : 'st-ok');
                    const service = String(entry.service || '');
                    const uri = String(entry.uri || '');
                    const ua = String(entry.ua || '');
                    const note = String(entry.note || '');

                    tr.appendChild(cell(time, 'mono'));

                    const serviceCell = document.createElement('td');
                    const chip = document.createElement('span');
                    chip.className = 'service-chip service-' + service;
                    chip.textContent = service || '—';
                    serviceCell.appendChild(chip);
                    tr.appendChild(serviceCell);

                    tr.appendChild(cell(String(status), 'mono ' + statusClass));
                    tr.appendChild(cell(String(entry.method || ''), 'mono'));

                    const uriCell = cell(uri, 'mono uri-cell');
                    uriCell.title = uri;
                    tr.appendChild(uriCell);

                    tr.appendChild(cell(String(entry.ip || ''), 'mono'));

                    const uaCell = cell(ua, 'mono ua-cell');
                    uaCell.title = ua;
                    tr.appendChild(uaCell);

                    if (note !== '') {
                        const noteCell = document.createElement('td');
                        const chipNote = document.createElement('span');
                        chipNote.className = 'note-chip';
                        chipNote.textContent = note;
                        noteCell.appendChild(chipNote);
                        tr.appendChild(noteCell);
                    } else {
                        tr.appendChild(cell('—', 'empty-value'));
                    }

                    const detailCell = document.createElement('td');
                    const detailButton = document.createElement('button');
                    detailButton.type = 'button';
                    detailButton.className = 'button secondary small-button log-detail-button';
                    detailButton.textContent = 'Details';
                    detailButton.addEventListener('click', () => showDetails(entry));
                    detailCell.appendChild(detailButton);
                    tr.appendChild(detailCell);
                    return tr;
                };

                const render = (entries) => {
                    rows.textContent = '';
                    entries.forEach((entry) => rows.appendChild(buildRow(entry)));
                    if (empty) {
                        empty.hidden = entries.length > 0;
                    }
                };

                const filterText = document.getElementById('log-filter-text');
                const filterService = document.getElementById('log-filter-service');
                const filterStatus = document.getElementById('log-filter-status');
                const filterSecurity = document.getElementById('log-filter-security');
                const filterCount = document.getElementById('log-filter-count');
                const filterClear = document.getElementById('log-filter-clear');
                const noMatch = document.getElementById('log-no-match');
                let allEntries = [];

                const filtersActive = () => {
                    return (filterText && filterText.value.trim() !== '')
                        || (filterService && filterService.value !== '')
                        || (filterStatus && filterStatus.value !== '')
                        || (filterSecurity && filterSecurity.value !== '');
                };

                const matchesFilters = (entry) => {
                    if (filterText) {
                        const needle = filterText.value.trim().toLowerCase();
                        if (needle !== '') {
                            const haystack = [entry.uri, entry.ip, entry.ua, entry.method, entry.note, entry.host]
                                .map((value) => String(value || '').toLowerCase())
                                .join(' ');
                            if (!haystack.includes(needle)) {
                                return false;
                            }
                        }
                    }
                    if (filterService && filterService.value !== '' && String(entry.service || '') !== filterService.value) {
                        return false;
                    }
                    if (filterStatus && filterStatus.value !== '') {
                        const bucket = Math.floor(Number(entry.status || 0) / 100);
                        if (String(bucket * 100) !== filterStatus.value) {
                            return false;
                        }
                    }
                    if (filterSecurity && filterSecurity.value !== '') {
                        const note = String(entry.note || '');
                        if (filterSecurity.value === 'allowed' && !(note.startsWith('ua:') && !note.startsWith('ua:deny'))) {
                            return false;
                        }
                        if (filterSecurity.value === 'denied' && note !== 'ua:deny') {
                            return false;
                        }
                    }
                    return true;
                };

                const applyFilters = () => {
                    const filtered = allEntries.filter(matchesFilters);
                    render(filtered);
                    const active = filtersActive();
                    if (filterClear) {
                        filterClear.hidden = !active;
                    }
                    if (filterCount) {
                        filterCount.textContent = active
                            ? filtered.length + ' of ' + allEntries.length + ' shown'
                            : '';
                    }
                    if (noMatch) {
                        noMatch.hidden = !(active && filtered.length === 0 && allEntries.length > 0);
                    }
                    if (empty && active) {
                        empty.hidden = true;
                    }
                };

                const poll = async () => {
                    try {
                        const response = await fetch('index.php?log=1&tail=1', {
                            headers: { Accept: 'application/json' },
                            credentials: 'same-origin',
                            cache: 'no-store',
                        });
                        if (!response.ok) {
                            throw new Error('HTTP ' + response.status);
                        }
                        const data = await response.json();
                        const entries = Array.isArray(data.entries) ? data.entries : [];
                        allEntries = entries;
                        applyFilters();
                        if (state) {
                            state.textContent = data.debug
                                ? 'Debug mode on — ' + entries.length + ' recent ' + (entries.length === 1 ? 'entry' : 'entries') + ' loaded.'
                                : 'Debug mode off — only user-agent security events are recorded.';
                        }
                        if (indicator) {
                            indicator.className = 'state-dot ' + (data.debug ? 'on' : 'off');
                        }
                    } catch (error) {
                        if (state) {
                            state.textContent = 'Live updates failed: ' + error.message;
                        }
                    }
                };

                [filterText, filterService, filterStatus, filterSecurity].forEach((control) => {
                    if (control) {
                        control.addEventListener('input', applyFilters);
                        control.addEventListener('change', applyFilters);
                    }
                });
                if (filterClear) {
                    filterClear.addEventListener('click', () => {
                        if (filterText) filterText.value = '';
                        if (filterService) filterService.value = '';
                        if (filterStatus) filterStatus.value = '';
                        if (filterSecurity) filterSecurity.value = '';
                        applyFilters();
                    });
                }

                let timer = null;
                const sync = () => {
                    if (timer !== null) {
                        clearInterval(timer);
                        timer = null;
                    }
                    if (liveToggle.checked) {
                        timer = setInterval(poll, 2000);
                    }
                };
                liveToggle.addEventListener('change', sync);
                sync();
                poll();

                rows.addEventListener('click', (event) => {
                    const button = event.target.closest('.log-detail-button');
                    if (!button || !button.dataset.detail) {
                        return;
                    }
                    try {
                        window.showDetails(JSON.parse(button.dataset.detail));
                    } catch (error) {
                        // Ignore malformed rows.
                    }
                });
            })();
            </script>
        <?php elseif ($securityMode): ?>
            <section class="form-card">
                <div class="form-card-head">
                    <div>
                        <p class="eyebrow">USER-AGENT SECURITY</p>
                        <h2>Playlist access rules</h2>
                        <p class="muted">Access is <strong>denied by default</strong> — every client receives <strong>404 Not Found</strong> unless its User-Agent matches an allow rule below. Add one rule per client (player) you want to let in; the first matching rule decides what that client receives: the server's default playlist, the full playlist, or the clear playlist.</p>
                    </div>
                    <form method="post" action="index.php?security=1" class="enforce-form">
                        <input type="hidden" name="csrf" value="<?= h(csrfToken()) ?>">
                        <input type="hidden" name="action" value="ua_settings">
                        <label class="enforce-label">Enforcement
                            <select name="enforce">
                                <option value="1" <?= $uaEnforced ? 'selected' : '' ?>>Enabled</option>
                                <option value="0" <?= $uaEnforced ? '' : 'selected' ?>>Disabled (allow all)</option>
                            </select>
                        </label>
                        <button class="button primary" type="submit">Save</button>
                    </form>
                </div>
                <div class="state-row">
                    <span class="state-dot <?= $uaEnforced ? 'on' : 'off' ?>"></span>
                    <span class="muted"><?= $uaEnforced
                        ? 'Enforcement is on — unmatched clients are denied with 404; only the allow rules below let a client through.'
                        : 'Enforcement is OFF — every client is allowed and receives the default playlist. Turn this on to lock the feeds down.' ?></span>
                </div>
                <div class="ua-test">
                    <form method="get" action="index.php" class="ua-test-form">
                        <input type="hidden" name="security" value="1">
                        <input name="test" value="<?= h($uaTest) ?>" placeholder="User-Agent to test, e.g. VLC/3.0.20 libVLC/3.0.20" spellcheck="false">
                        <button class="button secondary" type="submit">Test user agent</button>
                    </form>
                    <?php if ($uaTestResult !== null): ?>
                        <?php $matchedRule = $uaTestResult['rule']; ?>
                        <div class="test-result action-<?= h((string) $uaTestResult['action']) ?>">
                            <strong><?= h($uaActionLabels[$uaTestResult['action']] ?? (string) $uaTestResult['action']) ?></strong>
                            <?php if ($matchedRule !== null): ?>
                                <span>matched rule <code><?= h((string) $matchedRule['pattern']) ?></code><?= $matchedRule['note'] !== '' ? ' · ' . h((string) $matchedRule['note']) : '' ?></span>
                            <?php else: ?>
                                <span>no rule matched — the client is <strong>denied</strong> with 404 Not Found</span>
                            <?php endif; ?>
                            <?php if (!$uaEnforced): ?>
                                <span class="muted">Enforcement is off, so this outcome is not applied yet.</span>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </section>

            <section class="form-card">
                <div class="form-card-head">
                    <div>
                        <p class="eyebrow"><?= $editRule !== null ? 'EDIT RULE' : 'NEW RULE' ?></p>
                        <h2><?= $editRule !== null ? 'Edit rule' : 'Add a rule' ?></h2>
                        <p class="muted">A pattern matches when it is <strong>contained in</strong> the User-Agent (case-insensitive). Use <code>*</code> as a wildcard for "any text", e.g. <code>VLC</code>, <code>*Kodi*</code>, <code>TiviMate*</code>. A pattern wrapped in delimiters (<code>/</code>, <code>#</code>, <code>~</code> or <code>!</code>) is treated as a regular expression instead, e.g. <code>/^VLC\/3/i</code>. Disabled rules are skipped.</p>
                    </div>
                    <?php if ($editRule !== null): ?><a class="button secondary" href="index.php?security=1">Cancel edit</a><?php endif; ?>
                </div>
                <form method="post" action="index.php?security=1" class="channel-edit-form">
                    <input type="hidden" name="csrf" value="<?= h(csrfToken()) ?>">
                    <input type="hidden" name="action" value="ua_rule_save">
                    <?php if ($editRule !== null): ?><input type="hidden" name="id" value="<?= h((string) $editRule['id']) ?>"><?php endif; ?>
                    <div class="form-grid">
                        <label class="span-2">Allowed user-agent<input name="pattern" value="<?= h((string) ($editRule['pattern'] ?? '')) ?>" placeholder="VLC or *Kodi* or TiviMate*" required autofocus spellcheck="false"><small>Any UA containing this text is allowed. Use <code>*</code> as a wildcard; wrap in <code>/.../</code> for a regular expression.</small></label>
                        <label>Action
                            <select name="rule_action">
                                <?php foreach ($uaActions as $value => $label): ?>
                                    <option value="<?= h($value) ?>" <?= (string) ($editRule['action'] ?? 'serve') === $value ? 'selected' : '' ?>><?= h($label) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <small>What an allowed client receives.</small>
                        </label>
                        <label>Note<input name="note" value="<?= h((string) ($editRule['note'] ?? '')) ?>" placeholder="Optional — shown in the live log"></label>
                        <label class="checkbox-label"><input type="checkbox" name="rule_enabled" value="1"<?= ($editRule['enabled'] ?? true) ? ' checked' : '' ?>> Rule enabled</label>
                    </div>
                    <div class="form-actions">
                        <button class="button primary" type="submit"><?= $editRule !== null ? 'Save rule' : 'Allow this user agent' ?></button>
                        <?php if ($editRule !== null): ?><a class="button secondary" href="index.php?security=1">Cancel</a><?php endif; ?>
                    </div>
                </form>
            </section>

            <section class="table-card">
                <div class="table-toolbar">
                    <div>
                        <h2>Allow rules</h2>
                        <p class="muted"><?= count($uaRules) ?> <?= count($uaRules) === 1 ? 'rule' : 'rules' ?> · evaluated top to bottom, first match wins · anything not listed is denied</p>
                    </div>
                </div>
                <?php if ($uaRules === []): ?>
                    <div class="guide-empty">
                        <strong>No allow rules yet</strong>
                        <span>Add a rule above to let a specific user agent in. Everything else is denied with 404.</span>
                    </div>
                <?php else: ?>
                    <div class="table-wrap">
                        <table>
                            <thead>
                                <tr><th>#</th><th>Pattern</th><th>Action</th><th>Note</th><th>Status</th><th class="rule-actions-head">Manage</th></tr>
                            </thead>
                            <tbody>
                                <?php foreach ($uaRules as $index => $rule): ?>
                                    <tr<?= $rule['enabled'] ? '' : ' class="rule-disabled"' ?>>
                                        <td><?= $index + 1 ?></td>
                                        <td><code class="rule-pattern"><?= h((string) $rule['pattern']) ?></code></td>
                                        <td><span class="rule-badge action-<?= h((string) $rule['action']) ?>"><?= h($uaActionLabels[$rule['action']] ?? (string) $rule['action']) ?></span></td>
                                        <td><?= $rule['note'] !== '' ? h((string) $rule['note']) : '<span class="empty-value">—</span>' ?></td>
                                        <td><?= $rule['enabled'] ? 'Enabled' : 'Disabled' ?></td>
                                        <td class="rule-actions">
                                            <form method="post" action="index.php?security=1" class="inline-form">
                                                <input type="hidden" name="csrf" value="<?= h(csrfToken()) ?>">
                                                <input type="hidden" name="action" value="ua_rule_move">
                                                <input type="hidden" name="id" value="<?= h((string) $rule['id']) ?>">
                                                <input type="hidden" name="dir" value="up">
                                                <button class="button secondary small-button" type="submit"<?= $index === 0 ? ' disabled' : '' ?> title="Move rule up" aria-label="Move rule up">↑</button>
                                            </form>
                                            <form method="post" action="index.php?security=1" class="inline-form">
                                                <input type="hidden" name="csrf" value="<?= h(csrfToken()) ?>">
                                                <input type="hidden" name="action" value="ua_rule_move">
                                                <input type="hidden" name="id" value="<?= h((string) $rule['id']) ?>">
                                                <input type="hidden" name="dir" value="down">
                                                <button class="button secondary small-button" type="submit"<?= $index === count($uaRules) - 1 ? ' disabled' : '' ?> title="Move rule down" aria-label="Move rule down">↓</button>
                                            </form>
                                            <a class="button secondary small-button" href="index.php?security=1&amp;edit_rule=<?= h((string) $rule['id']) ?>">Edit</a>
                                            <form method="post" action="index.php?security=1" class="inline-form" onsubmit="return confirm('Delete this rule?')">
                                                <input type="hidden" name="csrf" value="<?= h(csrfToken()) ?>">
                                                <input type="hidden" name="action" value="ua_rule_delete">
                                                <input type="hidden" name="id" value="<?= h((string) $rule['id']) ?>">
                                                <button class="button small-button danger-button" type="submit">Delete</button>
                                            </form>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
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
                        <th>Logo URL</th><th>Feeds</th>
                        <?php foreach ($visibleUrlColumns as $field): ?>
                            <th><?= h($field) ?></th>
                        <?php endforeach; ?>
                        <th>Manage</th>
                    </tr></thead>
                    <tbody>
                    <?php if (!$channels): ?>
                        <tr><td colspan="<?= 9 + count($visibleUrlColumns) ?>" class="empty-state">
                            <strong>No channels found</strong>
                            <span><?= (int) $stats['total'] === 0 ? 'Add a channel to start building your playlist.' : 'Try a different search or category.' ?></span>
                            <?php if ((int) $stats['total'] === 0): ?><a class="button secondary small-button empty-action" href="index.php?add=1">Add channel</a><?php endif; ?>
                        </td></tr>
                    <?php endif; ?>
                    <?php foreach ($channels as $channel): ?>
                        <?php
                        $urls = json_decode($channel['url_fields_json'], true) ?: [];
                        $channelFeeds = $feedSummaries[(int) $channel['id']] ?? [];
                        $previewFeed = null;
                        foreach ($channelFeeds as $candidateFeed) {
                            if ((int) $candidateFeed['enabled'] === 1) {
                                $previewFeed = $candidateFeed;
                                break;
                            }
                        }
                        $previewData = $previewFeed !== null
                            ? json_encode(channelPreviewData($previewFeed, (string) $channel['name']), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT)
                            : false;
                        ?>
                        <tr>
                            <td class="select-cell"><input type="checkbox" class="bulk-checkbox" name="ids[]" value="<?= (int) $channel['id'] ?>" form="bulk-delete-form" aria-label="Select <?= h($channel['name']) ?>"></td>
                            <td><div class="channel-cell">
                                <?= statusButton((int) $channel['id'], (bool) $channel['enabled']) ?>
                                <button class="channel-logo-button" type="button" title="Change logo for <?= h($channel['name']) ?>" aria-label="Change logo for <?= h($channel['name']) ?>"
                                    data-logo-action="index.php?<?= h(http_build_query($listParams)) ?>"
                                    data-channel-id="<?= (int) $channel['id'] ?>"
                                    data-channel-name="<?= h($channel['name']) ?>"
                                    data-logo-url="<?= h((string) $channel['logo_url']) ?>">
                                    <?php if ($channel['logo_url']): ?><img class="channel-logo" src="<?= h($channel['logo_url']) ?>" alt="" loading="lazy" referrerpolicy="no-referrer"><?php else: ?><span class="channel-logo placeholder">▶</span><?php endif; ?>
                                    <span class="channel-logo-edit"><?= editIcon() ?></span>
                                </button>
                                <div><button class="channel-name-button" type="button" title="Rename <?= h($channel['name']) ?>"
                                    data-rename-action="index.php?<?= h(http_build_query($listParams)) ?>"
                                    data-channel-id="<?= (int) $channel['id'] ?>"
                                    data-channel-name="<?= h($channel['name']) ?>"
                                    data-channel-tvgname="<?= h((string) $channel['tvg_name']) ?>"><strong><?= h($channel['name']) ?></strong></button><small><?= h($channel['tvg_name']) ?></small></div>
                            </div></td>
                            <td class="monospace"><?= h($channel['tvg_id']) ?></td>
                            <td><span class="category-chip"><?= h($channel['group_title'] ?: 'Uncategorized') ?></span></td>
                            <td><?= h($channel['tvg_language']) ?></td>
                            <td><?= h($channel['tvg_type']) ?></td>
                            <td class="url-cell"><?= urlLink($channel['logo_url']) ?></td>
                            <td class="feeds-cell">
                                <?php if (!$channelFeeds): ?>
                                    <span class="empty-value">No feeds</span>
                                <?php else: ?>
                                    <strong><?= count($channelFeeds) ?> feed<?= count($channelFeeds) === 1 ? '' : 's' ?></strong>
                                    <span class="feed-chips">
                                        <?php foreach (array_unique(array_map(static fn (array $feedRow): string => (string) $feedRow['visibility'], $channelFeeds)) as $visibility): ?>
                                            <span class="feed-chip"><?= h(feedVisibilityShort($visibility)) ?></span>
                                        <?php endforeach; ?>
                                    </span>
                                <?php endif; ?>
                            </td>
                            <?php foreach ($visibleUrlColumns as $field): ?>
                                <td class="url-cell"><?= urlLink(is_string($urls[$field] ?? null) ? $urls[$field] : '') ?></td>
                            <?php endforeach; ?>
                            <td><div class="manage-cell">
                                <?php if ($previewData !== false): ?>
                                    <button class="button small-button icon-button preview-button" type="button" data-preview="<?= h($previewData) ?>" title="Preview <?= h($channel['name']) ?>" aria-label="Preview <?= h($channel['name']) ?>"><?= playIcon() ?></button>
                                <?php endif; ?>
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
    <dialog class="logo-dialog" id="logo-dialog" aria-labelledby="logo-dialog-title">
        <form method="post" enctype="multipart/form-data" class="logo-dialog-form" id="logo-dialog-form">
            <input type="hidden" name="csrf" value="<?= h(csrfToken()) ?>">
            <input type="hidden" name="action" value="channel_logo">
            <input type="hidden" name="id" id="logo-dialog-id" value="">
            <header class="dialog-head">
                <div>
                    <p class="eyebrow">CHANNEL LOGO</p>
                    <h2 id="logo-dialog-title">Change logo</h2>
                </div>
                <button class="button secondary small-button" type="button" data-dialog-close="logo-dialog">Close</button>
            </header>
            <div class="dialog-body">
                <div class="logo-dialog-preview">
                    <img id="logo-dialog-preview-img" src="" alt="" hidden>
                    <span class="channel-logo placeholder" id="logo-dialog-preview-fallback">▶</span>
                </div>
                <label class="field">
                    <span>Image URL</span>
                    <input type="url" name="logo_url" id="logo-dialog-url" placeholder="https://example.com/logo.png" autocomplete="off">
                </label>
                <div class="dialog-or"><span>or</span></div>
                <label class="field">
                    <span>Upload from computer</span>
                    <input type="file" name="logo_file" id="logo-dialog-file" accept="image/*">
                </label>
                <p class="muted small-note">PNG, JPG, GIF, WEBP, SVG, ICO or BMP · up to 5 MB. Leave both empty to remove the logo.</p>
            </div>
            <footer class="dialog-foot">
                <button class="button secondary" type="button" id="logo-dialog-remove">Remove logo</button>
                <button class="button" type="submit">Save logo</button>
            </footer>
        </form>
    </dialog>
    <dialog class="logo-dialog" id="rename-dialog" aria-labelledby="rename-dialog-title">
        <form method="post" class="logo-dialog-form" id="rename-dialog-form">
            <input type="hidden" name="csrf" value="<?= h(csrfToken()) ?>">
            <input type="hidden" name="action" value="channel_rename">
            <input type="hidden" name="id" id="rename-dialog-id" value="">
            <header class="dialog-head">
                <div>
                    <p class="eyebrow">CHANNEL NAME</p>
                    <h2 id="rename-dialog-title">Rename channel</h2>
                </div>
                <button class="button secondary small-button" type="button" data-dialog-close="rename-dialog">Close</button>
            </header>
            <div class="dialog-body">
                <label class="field">
                    <span>Channel name</span>
                    <input type="text" name="name" id="rename-dialog-name" required autocomplete="off">
                </label>
                <label class="field">
                    <span>TVG name</span>
                    <input type="text" name="tvg_name" id="rename-dialog-tvgname" autocomplete="off">
                </label>
            </div>
            <footer class="dialog-foot">
                <button class="button secondary" type="button" data-dialog-close="rename-dialog">Cancel</button>
                <button class="button" type="submit">Save name</button>
            </footer>
        </form>
    </dialog>
    <script>
    (() => {
        const openDialog = (id) => {
            const dialog = document.getElementById(id);
            if (dialog && typeof dialog.showModal === 'function') {
                dialog.showModal();
            }
        };
        const closeDialog = (id) => {
            const dialog = document.getElementById(id);
            if (dialog && dialog.open) {
                dialog.close();
            }
        };
        document.querySelectorAll('[data-dialog-close]').forEach((button) => {
            button.addEventListener('click', () => closeDialog(button.getAttribute('data-dialog-close')));
        });
        document.querySelectorAll('.logo-dialog').forEach((dialog) => {
            dialog.addEventListener('click', (event) => {
                if (event.target === dialog) {
                    dialog.close();
                }
            });
        });

        const logoDialog = document.getElementById('logo-dialog');
        const logoForm = document.getElementById('logo-dialog-form');
        const logoId = document.getElementById('logo-dialog-id');
        const logoUrl = document.getElementById('logo-dialog-url');
        const logoFile = document.getElementById('logo-dialog-file');
        const logoPreviewImg = document.getElementById('logo-dialog-preview-img');
        const logoPreviewFallback = document.getElementById('logo-dialog-preview-fallback');

        const renderLogoPreview = (src) => {
            if (!logoPreviewImg || !logoPreviewFallback) {
                return;
            }
            if (src) {
                logoPreviewImg.src = src;
                logoPreviewImg.hidden = false;
                logoPreviewFallback.hidden = true;
            } else {
                logoPreviewImg.hidden = true;
                logoPreviewImg.removeAttribute('src');
                logoPreviewFallback.hidden = false;
            }
        };

        if (logoForm) {
            logoForm.addEventListener('submit', () => {
                if (logoFile && logoFile.files.length === 0) {
                    logoFile.disabled = true;
                }
            });
            logoUrl && logoUrl.addEventListener('input', () => renderLogoPreview(logoUrl.value.trim()));
            logoFile && logoFile.addEventListener('change', () => {
                if (logoFile.files.length > 0) {
                    renderLogoPreview(URL.createObjectURL(logoFile.files[0]));
                } else if (logoUrl) {
                    renderLogoPreview(logoUrl.value.trim());
                }
            });
        }

        document.querySelectorAll('[data-logo-action]').forEach((button) => {
            button.addEventListener('click', () => {
                if (!logoForm) {
                    return;
                }
                logoForm.setAttribute('action', button.getAttribute('data-logo-action') || 'index.php');
                logoId.value = button.getAttribute('data-channel-id') || '';
                logoUrl.value = button.getAttribute('data-logo-url') || '';
                logoFile.value = '';
                const title = document.getElementById('logo-dialog-title');
                if (title) {
                    title.textContent = 'Logo · ' + (button.getAttribute('data-channel-name') || 'channel');
                }
                renderLogoPreview(logoUrl.value.trim());
                openDialog('logo-dialog');
            });
        });

        const removeButton = document.getElementById('logo-dialog-remove');
        removeButton && removeButton.addEventListener('click', () => {
            if (logoUrl) {
                logoUrl.value = '';
            }
            if (logoFile) {
                logoFile.value = '';
            }
            renderLogoPreview('');
            logoForm && logoForm.requestSubmit();
        });

        const renameForm = document.getElementById('rename-dialog-form');
        const renameId = document.getElementById('rename-dialog-id');
        const renameName = document.getElementById('rename-dialog-name');
        const renameTvgName = document.getElementById('rename-dialog-tvgname');
        document.querySelectorAll('[data-rename-action]').forEach((button) => {
            button.addEventListener('click', () => {
                if (!renameForm) {
                    return;
                }
                renameForm.setAttribute('action', button.getAttribute('data-rename-action') || 'index.php');
                renameId.value = button.getAttribute('data-channel-id') || '';
                renameName.value = button.getAttribute('data-channel-name') || '';
                renameTvgName.value = button.getAttribute('data-channel-tvgname') || '';
                openDialog('rename-dialog');
                renameName.focus();
                renameName.select();
            });
        });
    })();
    </script>
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
        const toggle = document.getElementById('user-menu-toggle');
        const panel = document.getElementById('user-menu-panel');
        if (!toggle || !panel) {
            return;
        }
        const menu = toggle.closest('.user-menu');
        const close = () => {
            panel.hidden = true;
            toggle.setAttribute('aria-expanded', 'false');
        };
        toggle.addEventListener('click', (event) => {
            event.stopPropagation();
            const open = panel.hidden;
            panel.hidden = !open;
            toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
        });
        document.addEventListener('click', (event) => {
            if (menu && !menu.contains(event.target)) {
                close();
            }
        });
        document.addEventListener('keydown', (event) => {
            if (event.key === 'Escape') {
                close();
            }
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
