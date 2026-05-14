<?php
/**
 * Attachment URL Repair — Admin Tool
 *
 * Finds rendered note files that reference only the prefix of dotted attachment IDs
 * and repairs them using each note's attachment metadata.
 */
// phpcs:disable

require_once __DIR__ . '/../auth.php';
requireAuth();
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../functions.php';
requireSettingsPassword();

if (!isCurrentUserAdmin()) {
    header('HTTP/1.1 403 Forbidden');
    echo '<div style="padding:20px;font-family:sans-serif;color:#721c24;background:#f8d7da;border:1px solid #f5c6cb;border-radius:4px;margin:20px;">Access denied. Admin privileges required.</div>';
    exit;
}

require_once __DIR__ . '/../db_connect.php';
require_once __DIR__ . '/../version_helper.php';

$v             = getAppVersion();
$currentLang   = getUserLanguage();
$pageWorkspace = trim(getWorkspaceFilter());

if (empty($_SESSION['attachment_repair_csrf_token'])) {
    $_SESSION['attachment_repair_csrf_token'] = bin2hex(random_bytes(32));
}

$action = $_POST['action'] ?? null;
$results = null;
$backupDir = null;
$fatalError = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array($action, ['scan', 'repair'], true)) {
    $token = $_POST['csrf_token'] ?? '';
    if (!hash_equals($_SESSION['attachment_repair_csrf_token'], $token)) {
        $fatalError = t('common.error', [], 'Invalid form submission. Please try again.');
    } else {
        $results = repairAttachmentUrlsForAllUsers($action === 'repair', $backupDir);
    }
}

function repairAttachmentUsersDir(): string {
    $usersDir = dirname(SQLITE_DATABASE, 2) . '/users';
    if (is_dir($usersDir)) {
        return $usersDir;
    }

    return __DIR__ . '/../../data/users';
}

function repairAttachmentRelativePath(?string $path): string {
    if (!$path) {
        return '';
    }

    $root = realpath(__DIR__ . '/../..');
    $realPath = realpath($path) ?: $path;
    if ($root && strpos($realPath, $root . DIRECTORY_SEPARATOR) === 0) {
        return str_replace(DIRECTORY_SEPARATOR, '/', substr($realPath, strlen($root) + 1));
    }

    return str_replace(DIRECTORY_SEPARATOR, '/', $path);
}

function repairAttachmentEnsureBackupDir(string $usersDir, ?string &$backupDir): string {
    if ($backupDir === null) {
        $backupDir = dirname($usersDir) . '/backups/attachment-url-repair-' . date('Ymd-His');
        createDirectoryWithPermissions($backupDir, 0775, true);
    }

    return $backupDir;
}

function repairAttachmentBackupFile(string $source, string $backupRoot, string $relativePath): void {
    $target = $backupRoot . '/' . $relativePath;
    createDirectoryWithPermissions(dirname($target), 0775, true);
    if (!copy($source, $target)) {
        throw new RuntimeException('Cannot back up ' . $source);
    }
}

function repairAttachmentBuildMap($attachmentsJson, int &$invalidJson, int &$ambiguousPrefixes): array {
    $attachmentsJson = (string)($attachmentsJson ?? '');
    if ($attachmentsJson === '' || $attachmentsJson === '[]') {
        return [];
    }

    $attachments = json_decode($attachmentsJson, true);
    if (!is_array($attachments)) {
        $invalidJson++;
        return [];
    }

    $idsByPrefix = [];
    foreach ($attachments as $attachment) {
        if (!is_array($attachment) || empty($attachment['id'])) {
            continue;
        }

        $fullId = (string)$attachment['id'];
        if (strpos($fullId, '.') === false) {
            continue;
        }

        $prefix = strstr($fullId, '.', true);
        if ($prefix === false || $prefix === '') {
            continue;
        }

        $idsByPrefix[$prefix][$fullId] = true;
    }

    $repairMap = [];
    foreach ($idsByPrefix as $prefix => $fullIds) {
        $fullIds = array_keys($fullIds);
        if (count($fullIds) === 1) {
            $repairMap[$prefix] = $fullIds[0];
        } else {
            $ambiguousPrefixes++;
        }
    }

    return $repairMap;
}

function repairAttachmentReplaceRefs(string $content, int $noteId, string $prefix, string $fullId, int &$replacements): string {
    $notePattern = preg_quote((string)$noteId, '~');
    $prefixPattern = preg_quote($prefix, '~');
    $boundary = '(?=$|[^A-Za-z0-9._-])';
    $urlPart = '[^"\'<>\s)\]]*';

    $patterns = [
        '~((?:/)?api/v1/notes/' . $notePattern . '/attachments/)' . $prefixPattern . $boundary . '~',
        '~(api_attachments\.php\?' . $urlPart . '\bnote_id=' . $notePattern . '\b' . $urlPart . '\battachment_id=)' . $prefixPattern . $boundary . '~',
        '~(api_attachments\.php\?' . $urlPart . '\battachment_id=)' . $prefixPattern . $boundary . '(' . $urlPart . '\bnote_id=' . $notePattern . '\b' . $urlPart . ')~',
    ];

    $content = preg_replace_callback($patterns[0], function (array $matches) use ($fullId, &$replacements): string {
        $replacements++;
        return $matches[1] . $fullId;
    }, $content);

    $content = preg_replace_callback($patterns[1], function (array $matches) use ($fullId, &$replacements): string {
        $replacements++;
        return $matches[1] . $fullId;
    }, $content);

    return preg_replace_callback($patterns[2], function (array $matches) use ($fullId, &$replacements): string {
        $replacements++;
        return $matches[1] . $fullId . $matches[2];
    }, $content);
}

function repairAttachmentApplyMap(string $content, int $noteId, array $repairMap, int &$replacements): string {
    foreach ($repairMap as $prefix => $fullId) {
        $content = repairAttachmentReplaceRefs($content, $noteId, $prefix, $fullId, $replacements);
    }

    return $content;
}

function repairAttachmentUrlsForAllUsers(bool $doRepair, ?string &$backupDir): array {
    $usersDir = repairAttachmentUsersDir();
    $rows = [];

    if (!is_dir($usersDir)) {
        return $rows;
    }

    $userIds = array_values(array_filter(
        scandir($usersDir),
        fn($d) => ctype_digit($d) && is_dir($usersDir . '/' . $d)
    ));
    sort($userIds, SORT_NUMERIC);

    foreach ($userIds as $userId) {
        $userPath = $usersDir . '/' . $userId;
        $dbPath = $userPath . '/database/poznote.db';
        $entriesPath = $userPath . '/entries';
        $dbBackedUp = false;

        $row = [
            'user_id' => $userId,
            'notes_scanned' => 0,
            'notes_with_refs' => 0,
            'refs_found' => 0,
            'refs_repaired' => 0,
            'files_updated' => 0,
            'db_rows_updated' => 0,
            'invalid_json' => 0,
            'ambiguous_prefixes' => 0,
            'skipped_db' => !is_file($dbPath),
            'errors' => [],
        ];

        if ($row['skipped_db']) {
            $rows[] = $row;
            continue;
        }

        try {
            $pdo = new PDO('sqlite:' . $dbPath);
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $pdo->exec('PRAGMA busy_timeout = 10000');
            $stmt = $pdo->query('SELECT id, entry, attachments FROM entries');
            $entries = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
            $row['notes_scanned'] = count($entries);
            $updateEntry = $pdo->prepare('UPDATE entries SET entry = :entry WHERE id = :id');

            foreach ($entries as $entry) {
                $noteId = (int)$entry['id'];
                $repairMap = repairAttachmentBuildMap($entry['attachments'] ?? '', $row['invalid_json'], $row['ambiguous_prefixes']);
                if ($repairMap === []) {
                    continue;
                }

                $noteRefs = 0;

                $entryContent = (string)($entry['entry'] ?? '');
                if ($entryContent !== '') {
                    $entryReplacements = 0;
                    $updatedEntry = repairAttachmentApplyMap($entryContent, $noteId, $repairMap, $entryReplacements);
                    $noteRefs += $entryReplacements;

                    if ($doRepair && $entryReplacements > 0 && $updatedEntry !== $entryContent) {
                        if (!$dbBackedUp) {
                            $backupRoot = repairAttachmentEnsureBackupDir($usersDir, $backupDir);
                            repairAttachmentBackupFile($dbPath, $backupRoot, 'users/' . $userId . '/database/poznote.db');
                            $dbBackedUp = true;
                        }
                        $updateEntry->execute([':entry' => $updatedEntry, ':id' => $noteId]);
                        $row['db_rows_updated']++;
                        $row['refs_repaired'] += $entryReplacements;
                    }
                }

                $noteFiles = glob($entriesPath . '/' . $noteId . '.*') ?: [];
                foreach ($noteFiles as $noteFile) {
                    if (!is_file($noteFile) || !preg_match('/\.(?:html|md|markdown)$/i', $noteFile)) {
                        continue;
                    }

                    $fileContent = file_get_contents($noteFile);
                    if ($fileContent === false) {
                        $row['errors'][] = 'Note ' . $noteId . ': cannot read ' . basename($noteFile);
                        continue;
                    }

                    $fileReplacements = 0;
                    $updatedFileContent = repairAttachmentApplyMap($fileContent, $noteId, $repairMap, $fileReplacements);
                    $noteRefs += $fileReplacements;

                    if ($doRepair && $fileReplacements > 0 && $updatedFileContent !== $fileContent) {
                        $backupRoot = repairAttachmentEnsureBackupDir($usersDir, $backupDir);
                        repairAttachmentBackupFile($noteFile, $backupRoot, 'users/' . $userId . '/entries/' . basename($noteFile));
                        if (file_put_contents($noteFile, $updatedFileContent) === false) {
                            $row['errors'][] = 'Note ' . $noteId . ': cannot write ' . basename($noteFile);
                            continue;
                        }
                        setFilePermissions($noteFile, 0644);
                        $row['files_updated']++;
                        $row['refs_repaired'] += $fileReplacements;
                    }
                }

                if ($noteRefs > 0) {
                    $row['notes_with_refs']++;
                    $row['refs_found'] += $noteRefs;
                }
            }
        } catch (Throwable $e) {
            $row['errors'][] = $e->getMessage();
        }

        $rows[] = $row;
    }

    return $rows;
}

$totals = [
    'users' => 0,
    'notes_scanned' => 0,
    'notes_with_refs' => 0,
    'refs_found' => 0,
    'refs_repaired' => 0,
    'files_updated' => 0,
    'db_rows_updated' => 0,
    'errors' => 0,
];

if ($results !== null) {
    foreach ($results as $resultRow) {
        $totals['users']++;
        $totals['notes_scanned'] += $resultRow['notes_scanned'];
        $totals['notes_with_refs'] += $resultRow['notes_with_refs'];
        $totals['refs_found'] += $resultRow['refs_found'];
        $totals['refs_repaired'] += $resultRow['refs_repaired'];
        $totals['files_updated'] += $resultRow['files_updated'];
        $totals['db_rows_updated'] += $resultRow['db_rows_updated'];
        $totals['errors'] += count($resultRow['errors']);
    }
}

$resultRowsToShow = [];
if ($results !== null) {
    $resultRowsToShow = array_values(array_filter($results, function (array $resultRow): bool {
        return !empty($resultRow['errors']) || $resultRow['refs_found'] > 0 || $resultRow['refs_repaired'] > 0;
    }));
}
?>
<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars($currentLang, ENT_QUOTES); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo t_h('admin_tools.repair_attachments.title', [], 'Repair attachment images'); ?></title>
    <meta name="color-scheme" content="dark light">
    <script src="../js/theme-init.js?v=<?php echo $v; ?>"></script>
    <link rel="stylesheet" href="../css/lucide.css?v=<?php echo $v; ?>">
    <link rel="stylesheet" href="../css/settings.css?v=<?php echo $v; ?>">
    <link rel="stylesheet" href="../css/users.css?v=<?php echo $v; ?>">
    <link rel="stylesheet" href="../css/dark-mode/variables.css?v=<?php echo $v; ?>">
    <link rel="stylesheet" href="../css/dark-mode/layout.css?v=<?php echo $v; ?>">
    <link rel="stylesheet" href="../css/dark-mode/menus.css?v=<?php echo $v; ?>">
    <link rel="stylesheet" href="../css/dark-mode/editor.css?v=<?php echo $v; ?>">
    <link rel="stylesheet" href="../css/dark-mode/modals.css?v=<?php echo $v; ?>">
    <link rel="stylesheet" href="../css/dark-mode/components.css?v=<?php echo $v; ?>">
    <link rel="stylesheet" href="../css/dark-mode/pages.css?v=<?php echo $v; ?>">
    <link rel="stylesheet" href="../css/dark-mode/icons.css?v=<?php echo $v; ?>">
    <link rel="icon" href="../favicon.ico" type="image/x-icon">
    <script src="../js/theme-manager.js?v=<?php echo $v; ?>"></script>
    <link rel="stylesheet" href="../css/admin-tools.css?v=<?php echo $v; ?>">
</head>
<body data-workspace="<?php echo htmlspecialchars($pageWorkspace, ENT_QUOTES, 'UTF-8'); ?>">
<div class="admin-container">
    <div class="admin-header">
        <div class="admin-nav" style="justify-content:center;">
            <a href="../index.php<?php echo $pageWorkspace !== '' ? '?workspace=' . urlencode($pageWorkspace) : ''; ?>" class="btn btn-secondary btn-margin-right">
                <i class="lucide lucide-sticky-note" style="margin-right: 5px;"></i>
                <?php echo t_h('common.back_to_notes', [], 'Back to notes'); ?>
            </a>
            <a href="../settings.php" class="btn btn-secondary">
                <i class="lucide lucide-settings" style="margin-right: 5px;"></i>
                <?php echo t_h('settings.title', [], 'Settings'); ?>
            </a>
        </div>
    </div>

    <div class="ci-page">
        <div class="ci-hero">
            <h1><?php echo t_h('admin_tools.repair_attachments.title', [], 'Repair attachment images'); ?></h1>
            <p><?php echo t_h('admin_tools.repair_attachments.description', [], 'Finds notes whose image URLs lost the part after a dot in the attachment ID, then restores those URLs from the note attachment metadata.'); ?></p>
        </div>

        <div class="ci-actions">
            <div class="ci-card">
                <span class="ci-card-step"><?php echo t_h('admin_tools.repair_attachments.step1', [], 'Step 1'); ?></span>
                <h2><?php echo t_h('admin_tools.repair_attachments.scan_title', [], 'Scan'); ?></h2>
                <p><?php echo t_h('admin_tools.repair_attachments.scan_desc', [], 'Scans every user note and counts truncated attachment URLs.'); ?> <strong><?php echo t_h('admin_tools.repair_attachments.scan_no_changes', [], 'No changes are made.'); ?></strong></p>
                <form method="POST">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['attachment_repair_csrf_token'], ENT_QUOTES); ?>">
                    <input type="hidden" name="action" value="scan">
                    <button type="submit" class="btn btn-secondary" style="width:100%;">
                        <i class="lucide-search"></i> <?php echo t_h('admin_tools.repair_attachments.scan_button', [], 'Scan notes'); ?>
                    </button>
                </form>
            </div>

            <div class="ci-card">
                <span class="ci-card-step"><?php echo t_h('admin_tools.repair_attachments.step2', [], 'Step 2'); ?></span>
                <h2><?php echo t_h('admin_tools.repair_attachments.repair_title', [], 'Repair'); ?></h2>
                <p><?php echo t_h('admin_tools.repair_attachments.repair_desc', [], 'Backs up affected note files and databases, then rewrites truncated URLs with the full attachment IDs.'); ?></p>
                <form method="POST">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['attachment_repair_csrf_token'], ENT_QUOTES); ?>">
                    <input type="hidden" name="action" value="repair">
                    <button type="submit" class="btn btn-primary" style="width:100%;">
                        <i class="lucide-wrench"></i> <?php echo t_h('admin_tools.repair_attachments.repair_button', [], 'Repair notes'); ?>
                    </button>
                </form>
            </div>
        </div>

        <?php if ($fatalError): ?>
            <div class="ci-notice warning"><i class="lucide-alert-triangle"></i> <?php echo htmlspecialchars($fatalError, ENT_QUOTES); ?></div>
        <?php endif; ?>

        <?php if ($results !== null): ?>
        <div class="ci-results">
            <div class="ci-results-header">
                <i class="lucide-<?php echo $action === 'repair' ? 'check-circle' : 'bar-chart'; ?>"></i>
                <h2><?php echo $action === 'repair' ? t_h('admin_tools.repair_attachments.results_repair', [], 'Repair results') : t_h('admin_tools.repair_attachments.results_scan', [], 'Scan results'); ?></h2>
            </div>

            <div class="ci-stats">
                <div class="ci-stat">
                    <div class="ci-stat-value <?php echo $totals['notes_with_refs'] > 0 ? 'is-warn' : 'is-ok'; ?>"><?php echo $totals['notes_with_refs']; ?></div>
                    <div class="ci-stat-label"><?php echo t_h('admin_tools.repair_attachments.table_notes', [], 'Notes'); ?></div>
                </div>
                <div class="ci-stat">
                    <div class="ci-stat-value <?php echo $totals['refs_found'] > 0 ? 'is-warn' : 'is-ok'; ?>"><?php echo $totals['refs_found']; ?></div>
                    <div class="ci-stat-label"><?php echo t_h('admin_tools.repair_attachments.stats_refs', [], 'Truncated refs'); ?></div>
                </div>
                <?php if ($action === 'repair'): ?>
                <div class="ci-stat">
                    <div class="ci-stat-value <?php echo $totals['refs_repaired'] > 0 ? 'is-ok' : ''; ?>"><?php echo $totals['refs_repaired']; ?></div>
                    <div class="ci-stat-label"><?php echo t_h('admin_tools.repair_attachments.stats_repaired', [], 'Repaired'); ?></div>
                </div>
                <?php endif; ?>
                <?php if ($totals['errors'] > 0): ?>
                <div class="ci-stat">
                    <div class="ci-stat-value is-err"><?php echo $totals['errors']; ?></div>
                    <div class="ci-stat-label"><?php echo t_h('admin_tools.repair_attachments.stats_errors', [], 'Errors'); ?></div>
                </div>
                <?php endif; ?>
            </div>

            <?php if ($action === 'repair'): ?>
                <?php if ($totals['refs_found'] === 0): ?>
                    <div class="ci-notice success"><i class="lucide-check-circle"></i> <?php echo t_h('admin_tools.repair_attachments.notice_repair_clean', [], 'No truncated attachment URLs found. Nothing to repair.'); ?></div>
                <?php elseif ($totals['errors'] === 0 && $totals['refs_repaired'] === $totals['refs_found']): ?>
                    <div class="ci-notice success"><i class="lucide-check-circle"></i> <?php echo t_h('admin_tools.repair_attachments.notice_repair_done', ['count' => $totals['refs_repaired']], '{{count}} attachment URL(s) repaired successfully.'); ?></div>
                <?php else: ?>
                    <div class="ci-notice warning"><i class="lucide-alert-triangle"></i> <?php echo t_h('admin_tools.repair_attachments.notice_repair_partial', ['done' => $totals['refs_repaired'], 'found' => $totals['refs_found'], 'errors' => $totals['errors']], '{{done}} of {{found}} URL(s) repaired; {{errors}} error(s) encountered.'); ?></div>
                <?php endif; ?>
                <?php if ($backupDir): ?>
                    <div class="ci-notice info"><i class="lucide-archive"></i> <?php echo t_h('admin_tools.repair_attachments.backup_dir', ['path' => repairAttachmentRelativePath($backupDir)], 'Backup created: {{path}}'); ?></div>
                <?php endif; ?>
            <?php else: ?>
                <?php if ($totals['refs_found'] === 0): ?>
                    <div class="ci-notice success"><i class="lucide-check-circle"></i> <?php echo t_h('admin_tools.repair_attachments.notice_scan_clean', [], 'No truncated attachment URLs found across all users.'); ?></div>
                <?php else: ?>
                    <div class="ci-notice info"><i class="lucide-info"></i> <?php echo t_h('admin_tools.repair_attachments.notice_scan_found', ['count' => $totals['refs_found'], 'notes' => $totals['notes_with_refs']], '{{count}} truncated URL(s) found in {{notes}} note(s). Use Step 2 to repair them.'); ?></div>
                <?php endif; ?>
            <?php endif; ?>

            <?php if (!empty($resultRowsToShow)): ?>
            <table class="ci-table">
                <thead>
                    <tr>
                        <th><?php echo t_h('admin_tools.repair_attachments.table_user', [], 'User'); ?></th>
                        <th class="num"><?php echo t_h('admin_tools.repair_attachments.table_refs', [], 'Refs'); ?></th>
                        <th><?php echo t_h('admin_tools.repair_attachments.table_status', [], 'Status'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($resultRowsToShow as $resultRow): ?>
                    <tr>
                        <td><strong><?php echo htmlspecialchars((string)$resultRow['user_id'], ENT_QUOTES); ?></strong></td>
                        <td class="num <?php echo ($action === 'repair' ? $resultRow['refs_repaired'] : $resultRow['refs_found']) > 0 ? 'has-data' : ''; ?>"><?php echo $action === 'repair' ? $resultRow['refs_repaired'] : $resultRow['refs_found']; ?></td>
                        <td>
                            <?php if (!empty($resultRow['errors'])): ?>
                                <span class="chip chip-err"><?php echo t_h('admin_tools.repair_attachments.status_error', [], 'Error'); ?></span>
                                <ul class="error-list">
                                    <?php foreach ($resultRow['errors'] as $error): ?>
                                        <li><?php echo htmlspecialchars($error, ENT_QUOTES); ?></li>
                                    <?php endforeach; ?>
                                </ul>
                            <?php elseif ($action === 'repair' && $resultRow['refs_repaired'] > 0): ?>
                                <span class="chip chip-done"><?php echo t_h('admin_tools.repair_attachments.status_repaired', [], 'Repaired'); ?></span>
                            <?php elseif ($resultRow['refs_found'] > 0): ?>
                                <span class="chip chip-warn"><?php echo t_h('admin_tools.repair_attachments.status_found', [], 'Found'); ?></span>
                            <?php else: ?>
                                <span class="chip chip-ok"><?php echo t_h('admin_tools.repair_attachments.status_clean', [], 'Clean'); ?></span>
                            <?php endif; ?>
                            <?php if ($resultRow['invalid_json'] > 0 || $resultRow['ambiguous_prefixes'] > 0): ?>
                                <ul class="error-list">
                                    <?php if ($resultRow['invalid_json'] > 0): ?>
                                        <li><?php echo t_h('admin_tools.repair_attachments.invalid_json', ['count' => $resultRow['invalid_json']], '{{count}} invalid attachment JSON field(s)'); ?></li>
                                    <?php endif; ?>
                                    <?php if ($resultRow['ambiguous_prefixes'] > 0): ?>
                                        <li><?php echo t_h('admin_tools.repair_attachments.ambiguous_prefixes', ['count' => $resultRow['ambiguous_prefixes']], '{{count}} ambiguous attachment prefix(es) skipped'); ?></li>
                                    <?php endif; ?>
                                </ul>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <?php endif; ?>
        </div>
        <?php endif; ?>
    </div>
</div>
</body>
</html>