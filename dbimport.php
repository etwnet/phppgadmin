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

function validate_job_id(string $jobId): bool
{
    if ($jobId === '' || strlen($jobId) > 128) {
        return false;
    }
    // Disallow path separators and traversal outright.
    if (strpos($jobId, '/') !== false || strpos($jobId, '\\') !== false || strpos($jobId, '..') !== false) {
        return false;
    }

    // Accept legacy uniqid('import_', true) format and new random hex format.
    // - import_<hex>
    // - import_<hex>.<digits>
    // - UUID (optional, in case we ever switch)
    if (preg_match('/^import_[0-9a-f]{13,}(?:\.[0-9]+)?$/i', $jobId)) {
        return true;
    }
    if (preg_match('/^import_[0-9a-f]{32}$/i', $jobId)) {
        return true;
    }
    if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $jobId)) {
        return true;
    }

    return false;
}

function get_import_base_dir(): string
{
    $conf = AppContainer::getConf();
    $base = $conf['import']['temp_dir'] ?? (sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'phppgadmin_imports');
    return rtrim($base, '/\\');
}

function get_job_dir(string $jobId): string
{
    if (!validate_job_id($jobId)) {
        throw new Exception('invalid job id');
    }
    $base = get_import_base_dir();
    $baseReal = realpath($base);
    if ($baseReal === false) {
        $baseReal = $base;
    }
    $jobDir = $baseReal . DIRECTORY_SEPARATOR . $jobId;
    // Ensure the computed directory stays under base.
    $prefix = rtrim($baseReal, '/\\') . DIRECTORY_SEPARATOR;
    if (strpos($jobDir, $prefix) !== 0) {
        throw new Exception('invalid job dir');
    }
    return $jobDir;
}

/**
 * Acquire a per-job lock to prevent concurrent writers (e.g., multiple tabs calling process).
 * Returns an open file handle that must be kept open until release.
 */
function acquire_job_lock(string $jobDir)
{
    $lockFile = $jobDir . DIRECTORY_SEPARATOR . '.lock';
    $fh = @fopen($lockFile, 'c');
    if ($fh === false) {
        return false;
    }
    if (!flock($fh, LOCK_EX | LOCK_NB)) {
        fclose($fh);
        return false;
    }
    return $fh;
}

function release_job_lock($fh): void
{
    if (is_resource($fh)) {
        @flock($fh, LOCK_UN);
        @fclose($fh);
    }
}

function read_json_locked(string $path)
{
    $fh = @fopen($path, 'rb');
    if ($fh === false) {
        return null;
    }
    flock($fh, LOCK_SH);
    $contents = stream_get_contents($fh);
    flock($fh, LOCK_UN);
    fclose($fh);
    if ($contents === false || $contents === '') {
        return null;
    }
    return json_decode($contents, true);
}

function write_json_locked(string $path, array $data): bool
{
    $fh = @fopen($path, 'c+');
    if ($fh === false) {
        return false;
    }
    if (!flock($fh, LOCK_EX)) {
        fclose($fh);
        return false;
    }
    ftruncate($fh, 0);
    rewind($fh);
    $json = json_encode($data);
    if ($json === false) {
        $json = '{}';
    }
    $ok = fwrite($fh, $json) !== false;
    fflush($fh);
    flock($fh, LOCK_UN);
    fclose($fh);
    return $ok;
}

function get_valid_zip_entries(string $zipPath, int $maxEntries, int $maxEntrySize): array
{
    if (!CompressionReader::isSupported('zip')) {
        throw new Exception('zip support is not available');
    }
    $zip = new ZipArchive();
    $entries = [];
    $tooMany = false;
    if ($zip->open($zipPath) === true) {
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i);
            if ($name === false || substr($name, -1) === '/') {
                continue;
            }
            $norm = str_replace('\\', '/', $name);
            if (strpos($norm, '../') !== false || strpos($norm, '/..') !== false || strpos($norm, '/') === 0) {
                continue;
            }
            $stat = $zip->statIndex($i);
            $usize = isset($stat['size']) ? (int) $stat['size'] : 0;
            if ($usize > $maxEntrySize) {
                continue;
            }
            $entries[] = ['name' => $name, 'size' => $usize];
            if (count($entries) > $maxEntries) {
                $tooMany = true;
                break;
            }
        }
        $zip->close();
    }
    if ($tooMany) {
        throw new Exception('too_many_entries');
    }
    usort($entries, function ($a, $b) {
        return strcasecmp($a['name'], $b['name']);
    });
    return $entries;
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

function handle_init_upload()
{
    $misc = AppContainer::getMisc();
    $conf = AppContainer::getConf();

    header('Content-Type: application/json');

    $filename = $_REQUEST['filename'] ?? 'upload.dump';
    $filesize = intval($_REQUEST['filesize'] ?? 0);
    $scope = $_REQUEST['scope'] ?? 'database';
    $scope_ident = $_REQUEST['scope_ident'] ?? '';

    if ($filesize <= 0) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid filesize']);
        exit;
    }

    // Collect boolean options from the form (presence means checked)
    $opts = [];
    $opts['roles'] = isset($_REQUEST['opt_roles']);
    $opts['tablespaces'] = isset($_REQUEST['opt_tablespaces']);
    $opts['databases'] = isset($_REQUEST['opt_databases']);
    $opts['schema_create'] = isset($_REQUEST['opt_schema_create']);
    $opts['data'] = isset($_REQUEST['opt_data']);
    $opts['truncate'] = isset($_REQUEST['opt_truncate']);
    $opts['ownership'] = isset($_REQUEST['opt_ownership']);
    $opts['rights'] = isset($_REQUEST['opt_rights']);
    $opts['defer_self'] = isset($_REQUEST['opt_defer_self']);
    $opts['allow_drops'] = isset($_REQUEST['opt_allow_drops']);
    $opts['error_mode'] = $_REQUEST['opt_error_mode'] ?? 'abort';

    $jobId = 'import_' . bin2hex(random_bytes(16));
    $jobDir = get_job_dir($jobId);
    if (!is_dir($jobDir)) {
        mkdir($jobDir, 0700, true);
    }

    $state = [
        'job_id' => $jobId,
        'filename' => basename($filename),
        'created' => time(),
        'last_activity' => time(),
        'uploaded_bytes' => 0,
        'expected_size' => $filesize,
        'offset' => 0,
        'size' => 0,
        'status' => 'uploading',
        'scope' => $scope,
        'scope_ident' => $scope_ident,
        'server_info' => (function () use ($misc) {
            $si = $misc->getServerInfo();
            if (!is_array($si)) {
                $si = (array) $si;
            }
            if (isset($si['password'])) {
                unset($si['password']);
            }
            return $si;
        })(),
        'options' => $opts,
        'truncated_tables' => [],
        'deferred' => [],
        'ownership_queue' => [],
        'rights_queue' => [],
        'buffer' => '',
    ];

    if (!empty($state['server_info']) && is_array($state['server_info'])) {
        if (empty($state['server_info']['database']) && $scope === 'database') {
            if (!empty($scope_ident)) {
                $state['server_info']['database'] = $scope_ident;
            }
        }
    }

    write_json_locked($jobDir . DIRECTORY_SEPARATOR . 'state.json', $state);

    lazy_gc();

    $chunkSize = $conf['import']['chunk_size'] ?? (5 * 1024 * 1024);
    echo json_encode([
        'job_id' => $jobId,
        'chunk_size' => $chunkSize,
        'expected_size' => $filesize
    ]);
}

function handle_upload_chunk()
{
    header('Content-Type: application/json');

    $jobId = $_REQUEST['job_id'] ?? '';
    $offset = intval($_REQUEST['offset'] ?? 0);
    $clientChecksum = strtolower($_SERVER['HTTP_X_CHECKSUM'] ?? '');

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

    $state = read_json_locked($stateFile);
    if ($state['status'] !== 'uploading') {
        http_response_code(400);
        echo json_encode(['error' => 'job not in uploading state']);
        exit;
    }

    $tmpFile = $jobDir . DIRECTORY_SEPARATOR . 'chunk.tmp';
    $targetFile = $jobDir . DIRECTORY_SEPARATOR . 'upload.dump';

    // Read chunk from php://input
    $in = fopen('php://input', 'rb');
    $out = fopen($tmpFile, 'wb');
    if (!$in || !$out) {
        http_response_code(500);
        echo json_encode(['error' => 'failed to open streams']);
        exit;
    }
    stream_copy_to_stream($in, $out);
    fclose($in);
    fclose($out);

    // Verify checksum
    $chunkData = file_get_contents($tmpFile);
    $serverChecksum = hash('fnv1a64', $chunkData);

    if ($serverChecksum !== $clientChecksum) {
        @unlink($tmpFile);
        echo json_encode(['status' => 'BAD_CHECKSUM']);
        exit;
    }

    // Append to target file
    $target = fopen($targetFile, $offset === 0 ? 'wb' : 'ab');
    if (!$target) {
        @unlink($tmpFile);
        http_response_code(500);
        echo json_encode(['error' => 'failed to open target']);
        exit;
    }
    $tmp = fopen($tmpFile, 'rb');
    stream_copy_to_stream($tmp, $target);
    fclose($tmp);
    fclose($target);
    @unlink($tmpFile);

    // Update state
    $chunkSize = strlen($chunkData);
    $state['uploaded_bytes'] = $offset + $chunkSize;
    $state['last_activity'] = time();
    write_json_locked($stateFile, $state);

    echo json_encode(['status' => 'OK', 'uploaded_bytes' => $state['uploaded_bytes']]);
}

function handle_upload_status()
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
        echo json_encode(['uploaded_bytes' => 0]);
        exit;
    }

    $state = read_json_locked($stateFile);
    echo json_encode(['uploaded_bytes' => $state['uploaded_bytes'] ?? 0]);
}

function handle_finalize_upload()
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

    $state = read_json_locked($stateFile);
    $targetFile = $jobDir . DIRECTORY_SEPARATOR . 'upload.dump';

    if (!is_file($targetFile)) {
        http_response_code(400);
        echo json_encode(['error' => 'upload file not found']);
        exit;
    }

    $actualSize = filesize($targetFile);
    $expectedSize = $state['expected_size'] ?? 0;

    if ($actualSize !== $expectedSize) {
        http_response_code(400);
        echo json_encode([
            'error' => 'size mismatch',
            'expected' => $expectedSize,
            'actual' => $actualSize
        ]);
        exit;
    }

    $state['size'] = $actualSize;
    $state['status'] = 'uploaded';
    $state['last_activity'] = time();
    write_json_locked($stateFile, $state);

    echo json_encode([
        'job_id' => $jobId,
        'size' => $actualSize,
        'status' => 'uploaded'
    ]);
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
    $state = read_json_locked($stateFile);
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
    try {
        $jobDir = get_job_dir($jobId);
    } catch (Exception $e) {
        http_response_code(400);
        echo json_encode(['error' => 'invalid job_id']);
        exit;
    }
    $stateFile = $jobDir . DIRECTORY_SEPARATOR . 'state.json';
    $uploadFile = $jobDir . DIRECTORY_SEPARATOR . 'upload.dump';
    if (!is_file($stateFile) || !is_file($uploadFile)) {
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
    $chunkSize = $conf['import']['chunk_size'] ?? (4 * 1024 * 1024); // 4MB default

    if (isset($state['status']) && $state['status'] === 'cancelled') {
        release_job_lock($lock);
        echo json_encode($state);
        exit;
    }

    $existing = $state['buffer'] ?? '';
    $opts = $state['options'] ?? ['roles' => false, 'tablespaces' => false, 'schema_create' => false, 'truncate' => false, 'defer_self' => true];

    $type = CompressionReader::detect($uploadFile);
    if (!CompressionReader::isSupported($type)) {
        $state['status'] = 'error';
        $state['error'] = 'unsupported_compression';
        $state['compression'] = $type;
        $state['last_activity'] = time();
        write_json_locked($stateFile, $state);
        release_job_lock($lock);
        http_response_code(400);
        echo json_encode(['error' => 'unsupported_compression', 'type' => $type]);
        exit;
    }

    // Prefer reader-based parsing (supports zip/gzip/bzip2 via stream readers).
    $res = null;
    $reader = null;
    try {
        switch ($type) {
            case 'zip':
                $maxEntries = (int) ($conf['import']['max_zip_entries'] ?? 1000);
                $maxEntrySize = (int) ($conf['import']['max_zip_entry_uncompressed_size'] ?? (10 * 1024 * 1024 * 1024));
                $validEntries = get_valid_zip_entries($uploadFile, $maxEntries, $maxEntrySize);
                if (empty($validEntries)) {
                    throw new Exception('No suitable entry found in zip archive');
                }

                // Determine which entry to open: user-selected, or import-all iteration, or first safe entry
                $entry = null;
                if (!empty($state['import_all_entries'])) {
                    if (empty($state['zip_entries'])) {
                        $state['zip_entries'] = array_map(function ($e) {
                            return $e['name'];
                        }, $validEntries);
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
                        $ok = false;
                        foreach ($validEntries as $ve) {
                            if ($ve['name'] === $entry) {
                                $ok = true;
                                break;
                            }
                        }
                        if (!$ok) {
                            throw new Exception('selected entry is not allowed');
                        }
                    } else {
                        $entry = $validEntries[0]['name'];
                    }
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

        // Optimization: do multiple parse+execute chunks per request for compressed formats,
        // reducing expensive seek/reopen cycles.
        $maxChunks = (int) ($conf['import']['max_chunks_per_request'] ?? (($type === 'plain') ? 1 : 3));
        $deadline = microtime(true) + 2.0; // avoid long-running requests
        $combinedStatements = [];
        $combinedEof = false;
        $totalConsumed = 0;

        if (($state['offset'] ?? 0) > 0) {
            $reader->seek((int) $state['offset']);
        }

        for ($i = 0; $i < max(1, $maxChunks); $i++) {
            if (microtime(true) >= $deadline) {
                break;
            }
            $chunkRes = SqlParser::parseFromReader($reader, $chunkSize, $existing);
            $consumed2 = (int) ($chunkRes['consumed'] ?? 0);
            $stmts2 = $chunkRes['statements'] ?? [];
            $existing = $chunkRes['remainder'] ?? '';
            $combinedEof = (bool) ($chunkRes['eof'] ?? false);
            $totalConsumed += $consumed2;

            if (!empty($stmts2)) {
                $combinedStatements = array_merge($combinedStatements, $stmts2);
            }
            // Stop if EOF reached or no progress (avoid tight loop)
            if ($combinedEof || ($consumed2 === 0 && empty($stmts2))) {
                break;
            }
        }

        $res = [
            'consumed' => $totalConsumed,
            'statements' => $combinedStatements,
            'remainder' => $existing,
            'eof' => $combinedEof,
        ];

        $reader->close();
    } catch (Exception $e) {
        if ($reader !== null) {
            try {
                $reader->close();
            } catch (Exception $e2) {
                // ignore
            }
        }
        if ($type !== 'plain') {
            $state['status'] = 'error';
            $state['error'] = ($e->getMessage() === 'too_many_entries') ? 'too_many_entries' : 'reader_error';
            $state['error_detail'] = $e->getMessage();
            $state['last_activity'] = time();
            write_json_locked($stateFile, $state);
            release_job_lock($lock);
            http_response_code(500);
            echo json_encode(['error' => $state['error'], 'detail' => $e->getMessage()]);
            exit;
        }
        // Fallback to existing file-based parser only for plain inputs
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
