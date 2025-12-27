<?php

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

        // Data statements - skip if data import disabled
        if ($cat === 'data' && empty($opts['data'])) {
            $logs[] = ['time' => time(), 'skipped' => true, 'reason' => 'data_disabled', 'statement' => substr($stmtTrim, 0, 200)];
            continue;
        }

        // DROP statements - block unless explicitly allowed
        if ($cat === 'drop' && empty($opts['allow_drops'])) {
            $logs[] = ['time' => time(), 'blocked' => true, 'reason' => 'drops_not_allowed', 'statement' => substr($stmtTrim, 0, 200)];
            continue;
        }

        // Ownership-changing statements queued to run after object creation but before rights
        if ($cat === 'ownership_change') {
            if (empty($opts['ownership'])) {
                $logs[] = ['time' => time(), 'skipped' => true, 'reason' => 'ownership_disabled', 'statement' => substr($stmtTrim, 0, 200)];
                continue;
            }
            $state['ownership_queue'][] = $stmtTrim;
            $logs[] = ['time' => time(), 'queued_ownership' => true, 'statement' => substr($stmtTrim, 0, 200)];
            continue;
        }

        // Rights statements queued as before
        if ($cat === 'rights') {
            if (empty($opts['rights'])) {
                $logs[] = ['time' => time(), 'skipped' => true, 'reason' => 'rights_disabled', 'statement' => substr($stmtTrim, 0, 200)];
                continue;
            }
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
            // Handle error based on error_mode option
            $errorMode = $opts['error_mode'] ?? 'abort';
            if ($errorMode === 'abort') {
                $state['status'] = 'error';
                $state['error'] = 'statement_failed';
                $state['error_detail'] = "Statement failed with error: $err";
                $state['log'] = $logs;
                $state['errors'] = $errors;
                $state['last_activity'] = time();
                write_json_locked($stateFile, $state);
                release_job_lock($lock);
                http_response_code(500);
                echo json_encode(['error' => 'statement_failed', 'detail' => $err, 'offset' => $state['offset'], 'errors' => $errors]);
                exit;
            }
            // log and ignore modes: continue processing
        } else {
            $logs[] = ['time' => time(), 'ok' => true, 'statement' => substr($stmtTrim, 0, 200)];
        }
        if (count($logs) > 200)
            array_shift($logs);
    }
    $state['log'] = $logs;
    $state['errors'] = $errors;

    // If finished, run queued statements in order: ownership → rights → self-affecting deferred
    if ($state['status'] === 'finished') {
        // 1. Execute ownership changes first (so objects have correct owners before grants)
        $ownership = $state['ownership_queue'] ?? [];
        if (!empty($ownership) && !empty($opts['ownership'])) {
            foreach ($ownership as $ostmt) {
                $err = $pg->execute($ostmt);
                if ($err !== 0) {
                    $state['errors'] = ($state['errors'] ?? 0) + 1;
                    $state['log'][] = ['time' => time(), 'error' => $err, 'ownership' => true, 'statement' => substr($ostmt, 0, 200)];
                } else {
                    $state['log'][] = ['time' => time(), 'ok' => true, 'ownership' => true, 'statement' => substr($ostmt, 0, 200)];
                }
            }
        } elseif (!empty($ownership)) {
            $state['log'][] = ['time' => time(), 'skipped_ownership' => true, 'count' => count($ownership)];
        }

        // 2. Execute rights queue if allowed
        $rights = $state['rights_queue'] ?? [];
        if (!empty($rights) && !empty($opts['rights']) && $allowCategory('rights')) {
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

        // 3. Execute deferred self-affecting last (only if superuser or server-scope)
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
        $state['ownership_queue'] = [];
        $state['rights_queue'] = [];
        $state['deferred'] = [];
    }

    write_json_locked($stateFile, $state);
    release_job_lock($lock);

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
    try {
        $jobDir = get_job_dir($jobId);
    } catch (Exception $e) {
        http_response_code(400);
        echo json_encode(['error' => 'invalid job_id']);
        exit;
    }
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
    if (!CompressionReader::isSupported('zip')) {
        http_response_code(400);
        echo json_encode(['error' => 'unsupported_compression', 'type' => 'zip']);
        exit;
    }
    $maxEntries = (int) ($conf['import']['max_zip_entries'] ?? 1000);
    $maxEntrySize = (int) ($conf['import']['max_zip_entry_uncompressed_size'] ?? (10 * 1024 * 1024 * 1024));
    try {
        $entries = get_valid_zip_entries($uploadFile, $maxEntries, $maxEntrySize);
        echo json_encode(['entries' => $entries, 'skipped' => []]);
    } catch (Exception $e) {
        if ($e->getMessage() === 'too_many_entries') {
            http_response_code(413);
            echo json_encode(['error' => 'too_many_entries', 'max' => $maxEntries]);
            exit;
        }
        http_response_code(500);
        echo json_encode(['error' => 'zip_error', 'detail' => $e->getMessage()]);
    }
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
    try {
        $jobDir = get_job_dir($jobId);
    } catch (Exception $e) {
        http_response_code(400);
        echo json_encode(['error' => 'invalid job_id']);
        exit;
    }
    $stateFile = $jobDir . DIRECTORY_SEPARATOR . 'state.json';
    if (!is_file($stateFile)) {
        http_response_code(404);
        echo json_encode(['error' => 'job not found']);
        exit;
    }
    $lock = acquire_job_lock($jobDir);
    if ($lock === false) {
        http_response_code(409);
        echo json_encode(['error' => 'job_busy']);
        exit;
    }
    $state = read_json_locked($stateFile);
    $sel = $_REQUEST['entry'] ?? null;
    $importAll = isset($_REQUEST['import_all']) && ($_REQUEST['import_all'] === '1' || $_REQUEST['import_all'] === 'true');
    if ($importAll) {
        $state['selected_entry'] = null;
        $state['import_all_entries'] = true;
        unset($state['zip_entries']);
        $state['current_entry_index'] = 0;
        $state['offset'] = 0;
    } elseif ($sel) {
        if (!CompressionReader::isSupported('zip')) {
            http_response_code(400);
            echo json_encode(['error' => 'unsupported_compression', 'type' => 'zip']);
            exit;
        }
        $uploadFile2 = $jobDir . DIRECTORY_SEPARATOR . 'upload.dump';
        $conf = AppContainer::getConf();
        $maxEntries = (int) ($conf['import']['max_zip_entries'] ?? 1000);
        $maxEntrySize = (int) ($conf['import']['max_zip_entry_uncompressed_size'] ?? (10 * 1024 * 1024 * 1024));
        $entries = get_valid_zip_entries($uploadFile2, $maxEntries, $maxEntrySize);
        $found = false;
        foreach ($entries as $e) {
            if ($e['name'] === $sel) {
                $found = true;
                break;
            }
        }
        if (!$found) {
            release_job_lock($lock);
            http_response_code(404);
            echo json_encode(['error' => 'entry_not_found']);
            exit;
        }
        $state['selected_entry'] = $sel;
        $state['import_all_entries'] = false;
        $state['offset'] = 0;
    } else {
        release_job_lock($lock);
        http_response_code(400);
        echo json_encode(['error' => 'entry or import_all required']);
        exit;
    }
    $state['last_activity'] = time();
    write_json_locked($stateFile, $state);
    release_job_lock($lock);
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
    try {
        $jobDir = get_job_dir($jobId);
    } catch (Exception $e) {
        http_response_code(400);
        echo json_encode(['error' => 'invalid job_id']);
        exit;
    }
    $sf = $jobDir . DIRECTORY_SEPARATOR . 'state.json';
    if (!is_file($sf)) {
        http_response_code(404);
        echo json_encode(['error' => 'not found']);
        exit;
    }
    $lock = acquire_job_lock($jobDir);
    if ($lock === false) {
        http_response_code(409);
        echo json_encode(['error' => 'job_busy']);
        exit;
    }
    $s = read_json_locked($sf);
    $s['status'] = 'cancelled';
    $s['last_activity'] = time();
    write_json_locked($sf, $s);
    release_job_lock($lock);
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
    try {
        $jobDir = get_job_dir($jobId);
    } catch (Exception $e) {
        http_response_code(400);
        echo json_encode(['error' => 'invalid job_id']);
        exit;
    }
    $sf = $jobDir . DIRECTORY_SEPARATOR . 'state.json';
    if (!is_file($sf)) {
        http_response_code(404);
        echo json_encode(['error' => 'not found']);
        exit;
    }
    $lock = acquire_job_lock($jobDir);
    if ($lock === false) {
        http_response_code(409);
        echo json_encode(['error' => 'job_busy']);
        exit;
    }
    $s = read_json_locked($sf);
    if (isset($s['status']) && $s['status'] === 'cancelled') {
        $s['status'] = 'running';
        $s['last_activity'] = time();
        write_json_locked($sf, $s);
        release_job_lock($lock);
        echo json_encode(['ok' => true]);
    } else {
        release_job_lock($lock);
        echo json_encode(['ok' => false, 'reason' => 'not_cancelled']);
    }
}

// Main action dispatcher

$action = $_REQUEST['action'] ?? 'init_upload';

switch ($action) {
    case 'init_upload':
        handle_init_upload();
        break;
    case 'upload_chunk':
        handle_upload_chunk();
        break;
    case 'upload_status':
        handle_upload_status();
        break;
    case 'finalize_upload':
        handle_finalize_upload();
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
