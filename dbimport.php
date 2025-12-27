<?php

use PhpPgAdmin\Core\AppContainer;
use PhpPgAdmin\Database\Import\SqlParser;
use PhpPgAdmin\Database\Import\StatementClassifier;
use PhpPgAdmin\Database\Import\CompressionReader;
use PhpPgAdmin\Database\Import\LocalFileReader;
use PhpPgAdmin\Database\Import\GzipReader;
use PhpPgAdmin\Database\Import\Bzip2Reader;
use PhpPgAdmin\Database\Import\ZipEntryReader;
use PhpPgAdmin\Database\Actions\RoleActions;

// dbimport.php
// Minimal import job API scaffold
// Actions: upload, process, status, gc

require_once __DIR__ . '/libraries/bootstrap.php';

function get_job_dir(string $jobId): string
{
    $conf = AppContainer::getConf();
    $base = $conf['import']['temp_dir'] ?? (sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'phppgadmin_imports');
    return rtrim($base, '/\\') . DIRECTORY_SEPARATOR . $jobId;
}

function lazy_gc(): void
{
    $misc = AppContainer::getMisc();
    $conf = AppContainer::getConf();
    $pg = AppContainer::getPostgres();

    $base = $conf['import']['temp_dir'] ?? (sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'phppgadmin_imports');
    if (!is_dir($base)) {
        return;
    }
    // Inspect up to 2 random job dirs and remove stale ones
    $dirs = glob($base . DIRECTORY_SEPARATOR . '*', GLOB_ONLYDIR);
    if (!$dirs) {
        return;
    }
    shuffle($dirs);
    $probe = $conf['import']['lazy_gc_probe'] ?? 2;
    $check = array_slice($dirs, 0, max(1, (int) $probe));
    $lifetime = $conf['import']['job_lifetime'] ?? 86400;
    foreach ($check as $d) {
        $stateFile = $d . DIRECTORY_SEPARATOR . 'state.json';
        if (!is_file($stateFile)) {
            array_map('unlink', glob($d . DIRECTORY_SEPARATOR . '*'));
            @rmdir($d);
            continue;
        }
        $state = json_decode(file_get_contents($stateFile), true);
        if ($state && isset($state['last_activity']) && (time() - $state['last_activity'] > $lifetime)) {
            array_map('unlink', glob($d . DIRECTORY_SEPARATOR . '*'));
            @rmdir($d);
        }
    }
}

function handle_upload()
{
    $misc = AppContainer::getMisc();
    $conf = AppContainer::getConf();
    $pg = AppContainer::getPostgres();

    header('Content-Type: application/json');
    $uploadMax = $conf['import']['upload_max_size'] ?? 0;
    if (!empty($_SERVER['CONTENT_LENGTH']) && $uploadMax > 0 && $_SERVER['CONTENT_LENGTH'] > $uploadMax) {
        http_response_code(413);
        echo json_encode(['error' => 'Upload exceeds configured maximum']);
        exit;
    }

    if (empty($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
        http_response_code(400);
        echo json_encode(['error' => 'No file uploaded or upload error']);
        exit;
    }

    $jobId = uniqid('import_', true);
    $jobDir = get_job_dir($jobId);
    if (!is_dir($jobDir)) {
        mkdir($jobDir, 0700, true);
    }

    $dest = $jobDir . DIRECTORY_SEPARATOR . 'upload.dump';
    if (!move_uploaded_file($_FILES['file']['tmp_name'], $dest)) {
        http_response_code(500);
        echo json_encode(['error' => 'Failed to move uploaded file']);
        exit;
    }

    $scope = $_REQUEST['scope'] ?? 'database';
    $scope_ident = $_REQUEST['scope_ident'] ?? '';

    // Collect boolean options from the form (presence means checked)
    $opts = [];
    $opts['roles'] = isset($_REQUEST['opt_roles']);
    $opts['tablespaces'] = isset($_REQUEST['opt_tablespaces']);
    $opts['schema_create'] = isset($_REQUEST['opt_schema_create']);
    $opts['truncate'] = isset($_REQUEST['opt_truncate']);
    // defer_self defaults to true
    $opts['defer_self'] = isset($_REQUEST['opt_defer_self']) ? true : true;

    $state = [
        'job_id' => $jobId,
        'created' => time(),
        'last_activity' => time(),
        'offset' => 0,
        'size' => filesize($dest),
        'status' => 'uploaded',
        'scope' => $scope,
        'scope_ident' => $scope_ident,
        // Persist server/login info so jobs can be resumed after session loss
        'server_info' => (function () use ($misc) {
            $si = $misc->getServerInfo();
            if (!is_array($si)) {
                $si = (array) $si;
            }
            if (isset($si['password'])) {
                unset($si['password']);
            }
            // attach current database if provided in request or scope_ident
            $dbName = $_REQUEST['database'] ?? $_REQUEST['scope_ident'] ?? null;
            if ($dbName) {
                $si['database'] = $dbName;
            }
            return $si;
        })(),
        'options' => $opts,
        'truncated_tables' => [],
        'deferred' => [],
        'rights_queue' => [],
        'buffer' => '',
    ];
    file_put_contents($jobDir . DIRECTORY_SEPARATOR . 'state.json', json_encode($state));

    // Run a tiny lazy GC pass to avoid leaving many temp dirs
    lazy_gc();

    echo json_encode(['job_id' => $jobId, 'size' => $state['size']]);
}

function handle_status(): void
{
    $misc = AppContainer::getMisc();
    $conf = AppContainer::getConf();
    $pg = AppContainer::getPostgres();

    header('Content-Type: application/json');
    $jobId = $_REQUEST['job_id'] ?? '';
    if (!$jobId) {
        http_response_code(400);
        echo json_encode(['error' => 'job_id required']);
        exit;
    }
    $jobDir = get_job_dir($jobId);
    $stateFile = $jobDir . DIRECTORY_SEPARATOR . 'state.json';
    if (!is_file($stateFile)) {
        http_response_code(404);
        echo json_encode(['error' => 'job not found']);
        exit;
    }
    $state = json_decode(file_get_contents($stateFile), true);
    // If job was cancelled, return current state without processing
    if (isset($state['status']) && $state['status'] === 'cancelled') {
        echo json_encode($state);
        exit;
    }
    echo json_encode($state);
}

function handle_process()
{
    $misc = AppContainer::getMisc();
    $conf = AppContainer::getConf();
    $pg = AppContainer::getPostgres();

    header('Content-Type: application/json');
    $jobId = $_REQUEST['job_id'] ?? '';
    if (!$jobId) {
        http_response_code(400);
        echo json_encode(['error' => 'job_id required']);
        exit;
    }
    $jobDir = get_job_dir($jobId);
    $stateFile = $jobDir . DIRECTORY_SEPARATOR . 'state.json';
    $uploadFile = $jobDir . DIRECTORY_SEPARATOR . 'upload.dump';
    if (!is_file($stateFile) || !is_file($uploadFile)) {
        http_response_code(404);
        echo json_encode(['error' => 'job not found']);
        exit;
    }
    $state = json_decode(file_get_contents($stateFile), true);
    $chunkSize = $conf['import']['chunk_size'] ?? (4 * 1024 * 1024); // 4MB default

    $existing = $state['buffer'] ?? '';
    $opts = $state['options'] ?? ['roles' => false, 'tablespaces' => false, 'schema_create' => false, 'truncate' => false, 'defer_self' => true];

    // Prefer reader-based parsing (supports zip/gzip/bzip2 via stream readers).
    $res = null;
    try {
        $type = CompressionReader::detect($uploadFile);
        switch ($type) {
            case 'zip':
                // Determine which entry to open: user-selected, or first, or import-all iteration
                $entry = null;
                if (!empty($state['import_all_entries'])) {
                    // ensure we have the entries list in state
                    if (empty($state['zip_entries'])) {
                        $zip = new ZipArchive();
                        $entries = [];
                        if ($zip->open($uploadFile) === true) {
                            for ($i = 0; $i < $zip->numFiles; $i++) {
                                $name = $zip->getNameIndex($i);
                                if (substr($name, -1) === '/')
                                    continue;
                                $entries[] = $name;
                            }
                            $zip->close();
                        }
                        usort($entries, 'strcasecmp');
                        $state['zip_entries'] = $entries;
                        $state['current_entry_index'] = $state['current_entry_index'] ?? 0;
                        $state['offset'] = $state['offset'] ?? 0;
                    }
                    $idx = $state['current_entry_index'] ?? 0;
                    if (!isset($state['zip_entries'][$idx])) {
                        throw new Exception('No suitable entry found in zip archive');
                    }
                    $entry = $state['zip_entries'][$idx];
                } else {
                    if (!empty($state['selected_entry'])) {
                        $entry = $state['selected_entry'];
                    } else {
                        $zip = new ZipArchive();
                        if ($zip->open($uploadFile) === true) {
                            for ($i = 0; $i < $zip->numFiles; $i++) {
                                $name = $zip->getNameIndex($i);
                                if (substr($name, -1) !== '/') { // skip directories
                                    $entry = $name;
                                    break;
                                }
                            }
                            $zip->close();
                        }
                    }
                }
                if ($entry === null) {
                    throw new Exception('No suitable entry found in zip archive');
                }
                $reader = new ZipEntryReader($uploadFile, $entry);
                break;
            case 'gzip':
                $reader = new GzipReader($uploadFile);
                break;
            case 'bzip2':
                $reader = new Bzip2Reader($uploadFile);
                break;
            default:
                $reader = new LocalFileReader($uploadFile);
                break;
        }
        // Move reader to the persisted offset (may be emulated)
        if ($state['offset'] > 0) {
            $reader->seek((int) $state['offset']);
        }
        $res = SqlParser::parseFromReader($reader, $chunkSize, $existing);
        $reader->close();
    } catch (Exception $e) {
        // Fallback to existing file-based parser on any error
        $res = SqlParser::parseChunk($uploadFile, $state['offset'], $chunkSize, $existing);
    }

    $consumed = $res['consumed'] ?? 0;
    $statements = $res['statements'] ?? [];
    $state['buffer'] = $res['remainder'] ?? '';
    $state['offset'] += $consumed;
    $state['last_activity'] = time();

    // Determine finished/running state, taking into account multi-entry ZIP imports
    $eof = $res['eof'] ?? false;
    $finished = false;
    if ($eof && ($state['buffer'] === '')) {
        if (!empty($state['import_all_entries'])) {
            $idx = $state['current_entry_index'] ?? 0;
            $entries = $state['zip_entries'] ?? [];
            if ($idx < count($entries) - 1) {
                // advance to next entry; reset offset and buffer for next entry
                $state['current_entry_index'] = $idx + 1;
                $state['offset'] = 0;
                $state['buffer'] = '';
                $state['log'][] = ['time' => time(), 'info' => 'switch_entry', 'to' => $state['zip_entries'][$state['current_entry_index']]];
                $finished = false;
            } else {
                $finished = true;
            }
        } else {
            $finished = true;
        }
    } else {
        $finished = false;
    }
    $state['status'] = $finished ? 'finished' : 'running';

    // classify & execute (or defer) returned statements
    $logs = $state['log'] ?? [];
    $errors = $state['errors'] ?? 0;

    // get current user and superuser flag
    $currentUser = null;
    if (isset($pg->conn->_connectionID)) {
        $currentUser = pg_parameter_status($pg->conn->_connectionID, 'user');
    }
    $roleActions = new RoleActions($pg);
    $isSuper = $roleActions->isSuperUser();

    // scope enforcement helper
    $scope = $state['scope'] ?? 'database';
    $allowCategory = function ($cat) use ($scope, $isSuper) {
        switch ($scope) {
            case 'server':
                if ($isSuper)
                    return true;
                return !in_array($cat, ['cluster_object', 'connection_change']);
            case 'database':
                return !in_array($cat, ['cluster_object', 'connection_change']);
            case 'schema':
                return in_array($cat, ['schema_object', 'data', 'rights', 'unknown']);
            case 'table':
                return $cat === 'data';
            default:
                return false;
        }
    };

    foreach ($statements as $stmt) {
        $stmtTrim = trim($stmt);
        if ($stmtTrim === '')
            continue;
        $cat = StatementClassifier::classify($stmtTrim, $currentUser ?? '');

        // Handle self-affecting statements according to option
        if ($cat === 'self_affecting') {
            if (!($opts['defer_self'] ?? true)) {
                // try immediate execution if superuser or server scope
                if ($isSuper || $scope === 'server') {
                    $err = $pg->execute($stmtTrim);
                    if ($err !== 0) {
                        $errors++;
                        $logs[] = ['time' => time(), 'error' => $err, 'statement' => substr($stmtTrim, 0, 200)];
                    } else {
                        $logs[] = ['time' => time(), 'ok' => true, 'statement' => substr($stmtTrim, 0, 200)];
                    }
                } else {
                    $state['deferred'][] = $stmtTrim;
                    $logs[] = ['time' => time(), 'deferred' => true, 'statement' => substr($stmtTrim, 0, 200)];
                }
            } else {
                $state['deferred'][] = $stmtTrim;
                $logs[] = ['time' => time(), 'deferred' => true, 'statement' => substr($stmtTrim, 0, 200)];
            }
            continue;
        }

        // Rights statements queued as before
        if ($cat === 'rights') {
            $state['rights_queue'][] = $stmtTrim;
            $logs[] = ['time' => time(), 'queued_rights' => true, 'statement' => substr($stmtTrim, 0, 200)];
            continue;
        }

        // Quick policy checks for specific options
        // CREATE SCHEMA
        if (preg_match('/^\s*CREATE\s+SCHEMA\b/i', $stmtTrim) && empty($opts['schema_create'])) {
            $logs[] = ['time' => time(), 'skipped' => true, 'reason' => 'schema_create_disabled', 'statement' => substr($stmtTrim, 0, 200)];
            continue;
        }
        // ROLE statements
        if (preg_match('/^\s*(CREATE|ALTER|DROP)\s+(ROLE|USER)\b/i', $stmtTrim) && empty($opts['roles'])) {
            $logs[] = ['time' => time(), 'skipped' => true, 'reason' => 'roles_disabled', 'statement' => substr($stmtTrim, 0, 200)];
            continue;
        }
        // TABLESPACE statements
        if (preg_match('/^\s*(CREATE|ALTER|DROP)\s+TABLESPACE\b/i', $stmtTrim) && empty($opts['tablespaces'])) {
            $logs[] = ['time' => time(), 'skipped' => true, 'reason' => 'tablespaces_disabled', 'statement' => substr($stmtTrim, 0, 200)];
            continue;
        }

        if (!$allowCategory($cat)) {
            $logs[] = ['time' => time(), 'skipped' => true, 'category' => $cat, 'statement' => substr($stmtTrim, 0, 200)];
            continue;
        }

        // If data statements and truncate option enabled, attempt truncate once per target table
        if ($cat === 'data' && (!empty($opts['truncate']))) {
            $rawTable = null;
            if (preg_match('/^\s*INSERT\s+INTO\s+([^\s(]+)/i', $stmtTrim, $m) || preg_match('/^\s*COPY\s+([^\s(]+)/i', $stmtTrim, $m)) {
                $rawTable = $m[1];
            }
            if ($rawTable) {
                // Normalize: remove surrounding quotes and whitespace
                $rawTable = trim($rawTable);
                // Remove surrounding quotes if present
                $rawTable = preg_replace('/^"(.*)"$/', '$1', $rawTable);
                // split schema.table if present
                $parts = preg_split('/\./', $rawTable);
                $parts = array_map(function ($p) {
                    $p = trim($p);
                    return preg_replace('/^"(.*)"$/', '$1', $p);
                }, $parts);
                if (count($parts) === 1) {
                    $schema = ($state['scope'] ?? '') === 'schema' ? ($state['scope_ident'] ?? '') : null;
                    $table = $parts[0];
                } else {
                    $table = array_pop($parts);
                    $schema = array_pop($parts);
                }
                $full = $schema ? ($schema . '.' . $table) : $table;
                if (!in_array($full, $state['truncated_tables'] ?? [])) {
                    // Prepare quoted identifier
                    if ($schema) {
                        $ident = pg_escape_id($schema) . '.' . pg_escape_id($table);
                    } else {
                        $ident = pg_escape_id($table);
                    }
                    $terr = $pg->execute("TRUNCATE TABLE {$ident}");
                    if ($terr !== 0) {
                        $errors++;
                        $logs[] = ['time' => time(), 'error' => $terr, 'truncate_failed' => true, 'table' => $full];
                    } else {
                        $logs[] = ['time' => time(), 'truncated' => true, 'table' => $full];
                        $state['truncated_tables'][] = $full;
                    }
                }
            }
        }

        $err = $pg->execute($stmtTrim);
        if ($err !== 0) {
            $errors++;
            $logs[] = ['time' => time(), 'error' => $err, 'statement' => substr($stmtTrim, 0, 200)];
        } else {
            $logs[] = ['time' => time(), 'ok' => true, 'statement' => substr($stmtTrim, 0, 200)];
        }
        if (count($logs) > 200)
            array_shift($logs);
    }
    $state['log'] = $logs;
    $state['errors'] = $errors;

    // If finished, run deferred rights/self-affecting statements according to scope/superuser
    if ($state['status'] === 'finished') {
        // execute rights queue if allowed
        $rights = $state['rights_queue'] ?? [];
        if (!empty($rights) && $allowCategory('rights')) {
            foreach ($rights as $rstmt) {
                $err = $pg->execute($rstmt);
                if ($err !== 0) {
                    $state['errors'] = ($state['errors'] ?? 0) + 1;
                    $state['log'][] = ['time' => time(), 'error' => $err, 'rights' => true, 'statement' => substr($rstmt, 0, 200)];
                } else {
                    $state['log'][] = ['time' => time(), 'ok' => true, 'rights' => true, 'statement' => substr($rstmt, 0, 200)];
                }
            }
        } elseif (!empty($rights)) {
            $state['log'][] = ['time' => time(), 'skipped_rights' => true, 'count' => count($rights)];
        }

        // execute deferred self-affecting only if superuser or server-scope
        $deferred = $state['deferred'] ?? [];
        if (!empty($deferred)) {
            if ($isSuper || ($state['scope'] ?? '') === 'server') {
                foreach ($deferred as $dstmt) {
                    $err = $pg->execute($dstmt);
                    if ($err !== 0) {
                        $state['errors'] = ($state['errors'] ?? 0) + 1;
                        $state['log'][] = ['time' => time(), 'error' => $err, 'deferred' => true, 'statement' => substr($dstmt, 0, 200)];
                    } else {
                        $state['log'][] = ['time' => time(), 'ok' => true, 'deferred' => true, 'statement' => substr($dstmt, 0, 200)];
                    }
                }
            } else {
                $state['log'][] = ['time' => time(), 'skipped_deferred' => true, 'count' => count($deferred)];
            }
        }

        // clear queues after attempted execution
        $state['rights_queue'] = [];
        $state['deferred'] = [];
    }

    file_put_contents($stateFile, json_encode($state));

    // small lazy GC probe as we process
    lazy_gc();

    echo json_encode(['job_id' => $jobId, 'offset' => $state['offset'], 'size' => $state['size'], 'status' => $state['status'], 'errors' => $errors]);
}

function handle_gc(): void
{
    $misc = AppContainer::getMisc();
    $conf = AppContainer::getConf();
    $pg = AppContainer::getPostgres();

    header('Content-Type: application/json');
    // Manual GC trigger: remove jobs older than configured lifetime
    $base = $conf['import']['temp_dir'] ?? (sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'phppgadmin_imports');
    $lifetime = $conf['import']['job_lifetime'] ?? 86400; // default 24h
    $deleted = 0;
    if (is_dir($base)) {
        $dirs = glob($base . DIRECTORY_SEPARATOR . '*', GLOB_ONLYDIR);
        foreach ($dirs as $d) {
            $stateFile = $d . DIRECTORY_SEPARATOR . 'state.json';
            if (!is_file($stateFile)) {
                // remove immediately
                array_map('unlink', glob($d . DIRECTORY_SEPARATOR . '*'));
                @rmdir($d);
                $deleted++;
                continue;
            }
            $state = json_decode(file_get_contents($stateFile), true);
            if ($state && isset($state['last_activity']) && (time() - $state['last_activity'] > $lifetime)) {
                array_map('unlink', glob($d . DIRECTORY_SEPARATOR . '*'));
                @rmdir($d);
                $deleted++;
            }
        }
    }
    echo json_encode(['deleted' => $deleted]);
}

// Helper handlers for remaining actions
function handle_list_entries(): void
{
    $misc = AppContainer::getMisc();
    $conf = AppContainer::getConf();

    header('Content-Type: application/json');
    $jobId = $_REQUEST['job_id'] ?? '';
    if (!$jobId) {
        http_response_code(400);
        echo json_encode(['error' => 'job_id required']);
        exit;
    }
    $jobDir = get_job_dir($jobId);
    $uploadFile = $jobDir . DIRECTORY_SEPARATOR . 'upload.dump';
    if (!is_file($uploadFile)) {
        http_response_code(404);
        echo json_encode(['error' => 'job not found']);
        exit;
    }
    $type = CompressionReader::detect($uploadFile);
    if ($type !== 'zip') {
        echo json_encode(['entries' => []]);
        exit;
    }
    $maxEntries = (int) ($conf['import']['max_zip_entries'] ?? 1000);
    $maxEntrySize = (int) ($conf['import']['max_zip_entry_uncompressed_size'] ?? (10 * 1024 * 1024 * 1024));

    $zip = new ZipArchive();
    $entries = [];
    $skipped = [];
    if ($zip->open($uploadFile) === true) {
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i);
            if (substr($name, -1) === '/')
                continue; // skip directories
            $norm = str_replace('\\', '/', $name);
            if (strpos($norm, '../') !== false || strpos($norm, '/..') !== false || strpos($norm, '/') === 0) {
                $skipped[] = ['name' => $name, 'reason' => 'path_traversal'];
                continue;
            }
            $stat = $zip->statIndex($i);
            $usize = isset($stat['size']) ? (int) $stat['size'] : 0;
            if ($usize > $maxEntrySize) {
                $skipped[] = ['name' => $name, 'reason' => 'too_large', 'size' => $usize];
                continue;
            }
            $entries[] = ['name' => $name, 'size' => $usize];
            if (count($entries) >= $maxEntries) {
                break;
            }
        }
        $zip->close();
    }

    if (count($entries) >= $maxEntries) {
        http_response_code(413);
        echo json_encode(['error' => 'too_many_entries', 'max' => $maxEntries]);
        exit;
    }

    usort($entries, function ($a, $b) {
        return strcasecmp($a['name'], $b['name']);
    });
    echo json_encode(['entries' => $entries, 'skipped' => $skipped]);
}

function handle_select_entry(): void
{
    header('Content-Type: application/json');
    $jobId = $_REQUEST['job_id'] ?? '';
    if (!$jobId) {
        http_response_code(400);
        echo json_encode(['error' => 'job_id required']);
        exit;
    }
    $jobDir = get_job_dir($jobId);
    $stateFile = $jobDir . DIRECTORY_SEPARATOR . 'state.json';
    if (!is_file($stateFile)) {
        http_response_code(404);
        echo json_encode(['error' => 'job not found']);
        exit;
    }
    $state = json_decode(file_get_contents($stateFile), true);
    $sel = $_REQUEST['entry'] ?? null;
    $importAll = isset($_REQUEST['import_all']) && ($_REQUEST['import_all'] === '1' || $_REQUEST['import_all'] === 'true');
    if ($importAll) {
        $state['selected_entry'] = null;
        $state['import_all_entries'] = true;
        unset($state['zip_entries']);
        $state['current_entry_index'] = 0;
        $state['offset'] = 0;
    } elseif ($sel) {
        $uploadFile2 = $jobDir . DIRECTORY_SEPARATOR . 'upload.dump';
        $conf = AppContainer::getConf();
        $maxEntrySize = (int) ($conf['import']['max_zip_entry_uncompressed_size'] ?? (10 * 1024 * 1024 * 1024));
        $zip2 = new ZipArchive();
        $found = false;
        if ($zip2->open($uploadFile2) === true) {
            for ($i = 0; $i < $zip2->numFiles; $i++) {
                $name = $zip2->getNameIndex($i);
                if ($name === $sel) {
                    $stat = $zip2->statIndex($i);
                    $usize = isset($stat['size']) ? (int) $stat['size'] : 0;
                    $norm = str_replace('\\', '/', $name);
                    if (strpos($norm, '../') !== false || strpos($norm, '/..') !== false || strpos($norm, '/') === 0) {
                        http_response_code(400);
                        echo json_encode(['error' => 'unsafe_entry']);
                        $zip2->close();
                        exit;
                    }
                    if ($usize > $maxEntrySize) {
                        http_response_code(413);
                        echo json_encode(['error' => 'entry_too_large', 'size' => $usize]);
                        $zip2->close();
                        exit;
                    }
                    $found = true;
                    break;
                }
            }
            $zip2->close();
        }
        if (!$found) {
            http_response_code(404);
            echo json_encode(['error' => 'entry_not_found']);
            exit;
        }
        $state['selected_entry'] = $sel;
        $state['import_all_entries'] = false;
        $state['offset'] = 0;
    } else {
        http_response_code(400);
        echo json_encode(['error' => 'entry or import_all required']);
        exit;
    }
    $state['last_activity'] = time();
    file_put_contents($stateFile, json_encode($state));
    echo json_encode(['ok' => true]);
}

function handle_list_jobs(): void
{
    $misc = AppContainer::getMisc();
    $conf = AppContainer::getConf();
    header('Content-Type: application/json');
    $base = $conf['import']['temp_dir'] ?? (sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'phppgadmin_imports');
    $out = [];

    // current server/login info for filtering
    $currentServer = $misc->getServerInfo();

    if (is_dir($base)) {
        $dirs = glob($base . DIRECTORY_SEPARATOR . '*', GLOB_ONLYDIR);
        // allow superusers to see all jobs; show_all request honored only for superusers
        $pg = AppContainer::getPostgres();
        $roleActions = new RoleActions($pg);
        $isSuper = $roleActions->isSuperUser();
        $showAll = false;
        if ($isSuper) {
            $showAll = isset($_REQUEST['show_all']) && ($_REQUEST['show_all'] === '1' || $_REQUEST['show_all'] === 'true');
        }

        foreach ($dirs as $d) {
            $sf = $d . DIRECTORY_SEPARATOR . 'state.json';
            if (!is_file($sf))
                continue;
            $s = json_decode(file_get_contents($sf), true);
            if (!$s)
                continue;

            // If job has server_info, only include jobs that match current server/user
            // unless the current user is a superuser or explicitly requested all jobs
            if (!$isSuper && !$showAll && !empty($s['server_info']) && is_array($s['server_info'])) {
                $jobServer = $s['server_info'];
                $mismatch = false;
                // compare host/port/username first
                foreach (['host', 'port', 'username'] as $k) {
                    if (isset($jobServer[$k]) && isset($currentServer[$k]) && (string) $jobServer[$k] !== (string) $currentServer[$k]) {
                        $mismatch = true;
                        break;
                    }
                }
                // also compare database if available
                if (!$mismatch && isset($jobServer['database']) && isset($currentServer['database']) && (string) $jobServer['database'] !== (string) $currentServer['database']) {
                    $mismatch = true;
                }
                if ($mismatch) {
                    continue; // skip jobs belonging to other servers/users/databases
                }
            }

            $out[] = [
                'job_id' => $s['job_id'] ?? basename($d),
                'created' => $s['created'] ?? null,
                'last_activity' => $s['last_activity'] ?? null,
                'status' => $s['status'] ?? null,
                'size' => $s['size'] ?? null,
                'offset' => $s['offset'] ?? 0,
                'scope' => $s['scope'] ?? null,
                'selected_entry' => $s['selected_entry'] ?? null,
                'import_all_entries' => !empty($s['import_all_entries']),
            ];
        }
    }
    echo json_encode(['jobs' => $out]);
}

function handle_cancel_job(): void
{
    header('Content-Type: application/json');
    $jobId = $_REQUEST['job_id'] ?? '';
    if (!$jobId) {
        http_response_code(400);
        echo json_encode(['error' => 'job_id required']);
        exit;
    }
    $jobDir = get_job_dir($jobId);
    $sf = $jobDir . DIRECTORY_SEPARATOR . 'state.json';
    if (!is_file($sf)) {
        http_response_code(404);
        echo json_encode(['error' => 'not found']);
        exit;
    }
    $s = json_decode(file_get_contents($sf), true);
    $s['status'] = 'cancelled';
    $s['last_activity'] = time();
    file_put_contents($sf, json_encode($s));
    echo json_encode(['ok' => true]);
}

function handle_resume_job(): void
{
    header('Content-Type: application/json');
    $jobId = $_REQUEST['job_id'] ?? '';
    if (!$jobId) {
        http_response_code(400);
        echo json_encode(['error' => 'job_id required']);
        exit;
    }
    $jobDir = get_job_dir($jobId);
    $sf = $jobDir . DIRECTORY_SEPARATOR . 'state.json';
    if (!is_file($sf)) {
        http_response_code(404);
        echo json_encode(['error' => 'not found']);
        exit;
    }
    $s = json_decode(file_get_contents($sf), true);
    if (isset($s['status']) && $s['status'] === 'cancelled') {
        $s['status'] = 'running';
        $s['last_activity'] = time();
        file_put_contents($sf, json_encode($s));
        echo json_encode(['ok' => true]);
    } else {
        echo json_encode(['ok' => false, 'reason' => 'not_cancelled']);
    }
}

// Main action dispatcher

$action = $_REQUEST['action'] ?? 'upload';

switch ($action) {
    case 'upload':
        handle_upload();
        break;
    case 'list_entries':
        handle_list_entries();
        break;
    case 'select_entry':
        handle_select_entry();
        break;
    case 'status':
        handle_status();
        break;
    case 'list_jobs':
        handle_list_jobs();
        break;
    case 'cancel_job':
        handle_cancel_job();
        break;
    case 'resume_job':
        handle_resume_job();
        break;
    case 'process':
        handle_process();
        break;
    case 'gc':
        handle_gc();
        break;
    default:
        http_response_code(400);
        echo 'Unknown action';
}
