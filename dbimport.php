<?php

use PhpPgAdmin\Core\AppContainer;
use PhpPgAdmin\Database\Import\Bzip2Reader;
use PhpPgAdmin\Database\Import\CompressionReader;
use PhpPgAdmin\Database\Import\GzipReader;
use PhpPgAdmin\Database\Import\LocalFileReader;
use PhpPgAdmin\Database\Import\ReaderInterface;
use PhpPgAdmin\Database\Import\SqlParser;
use PhpPgAdmin\Database\Import\StatementClassifier;
use PhpPgAdmin\Database\Import\ZipEntryReader;
use PhpPgAdmin\Database\Actions\RoleActions;
use PhpPgAdmin\Database\Import\ImportJob;
use PhpPgAdmin\Database\Import\ImportExecutor;

// dbimport.php
// Minimal import job API scaffold
// Actions: upload, process, status, gc

require_once __DIR__ . '/libraries/bootstrap.php';

function fnv1a64(string $data): string
{
    return hash('fnv1a64', $data);
    /*
    $hash = 0xcbf29ce484222325;
    $prime = 0x100000001b3;
    $len = strlen($data);
    for ($i = 0; $i < $len; $i++) {
        $hash ^= ord($data[$i]);
        $hash = ($hash * $prime) & 0xffffffffffffffff;
    }
    return sprintf('%016x', $hash);
    */
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

    $maxSize = $conf['import']['upload_max_size'] ?? 0;
    if ($maxSize > 0 && $filesize > $maxSize) {
        http_response_code(413);
        echo json_encode(['error' => 'Upload exceeds configured maximum']);
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
    $jobDir = ImportJob::getDir($jobId);
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

    ImportJob::writeState($jobDir, $state);

    ImportJob::lazyGc();

    $chunkSize = $conf['import']['upload_chunk_size'] ?? ($conf['import']['chunk_size'] ?? (5 * 1024 * 1024));
    echo json_encode([
        'job_id' => $jobId,
        'chunk_size' => $chunkSize,
        'expected_size' => $filesize
    ]);
}

function handle_upload_chunk()
{
    header('Content-Type: application/json');

    $conf = AppContainer::getConf();
    $maxSize = $conf['import']['upload_max_size'] ?? 0;

    $jobId = $_REQUEST['job_id'] ?? '';
    $offset = intval($_REQUEST['offset'] ?? 0);
    $clientChecksum = strtolower($_SERVER['HTTP_X_CHECKSUM'] ?? '');

    if (!$jobId) {
        http_response_code(400);
        echo json_encode(['error' => 'job_id required']);
        exit;
    }

    try {
        $jobDir = ImportJob::getDir($jobId);
    } catch (Exception $e) {
        http_response_code(400);
        echo json_encode(['error' => 'invalid job_id']);
        exit;
    }

    $state = ImportJob::readState($jobDir);
    if (!$state) {
        http_response_code(404);
        echo json_encode(['error' => 'job not found']);
        exit;
    }

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
    $serverChecksum = fnv1a64($chunkData);

    if ($serverChecksum !== $clientChecksum) {
        @unlink($tmpFile);
        echo json_encode(['status' => 'BAD_CHECKSUM']);
        exit;
    }

    $chunkSize = strlen($chunkData);
    if ($maxSize > 0 && ($offset + $chunkSize) > $maxSize) {
        @unlink($tmpFile);
        http_response_code(413);
        echo json_encode(['error' => 'Upload exceeds configured maximum']);
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
    $state['uploaded_bytes'] = $offset + $chunkSize;
    $state['last_activity'] = time();
    ImportJob::writeState($jobDir, $state);

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
        $jobDir = ImportJob::getDir($jobId);
    } catch (Exception $e) {
        http_response_code(400);
        echo json_encode(['error' => 'invalid job_id']);
        exit;
    }

    $state = ImportJob::readState($jobDir);
    if (!$state) {
        echo json_encode(['uploaded_bytes' => 0]);
        exit;
    }

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
        $jobDir = ImportJob::getDir($jobId);
    } catch (Exception $e) {
        http_response_code(400);
        echo json_encode(['error' => 'invalid job_id']);
        exit;
    }

    $state = ImportJob::readState($jobDir);
    if (!$state) {
        http_response_code(404);
        echo json_encode(['error' => 'job not found']);
        exit;
    }

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
    ImportJob::writeState($jobDir, $state);

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
        $jobDir = ImportJob::getDir($jobId);
    } catch (Exception $e) {
        http_response_code(400);
        echo json_encode(['error' => 'invalid job_id']);
        exit;
    }
    $state = ImportJob::readState($jobDir);
    if (!$state) {
        http_response_code(404);
        echo json_encode(['error' => 'job not found']);
        exit;
    }
    // If job was paused, return current state without processing
    if (isset($state['status']) && $state['status'] === 'paused') {
        echo json_encode($state);
        exit;
    }
    echo json_encode($state);
}

/**
 * Validates job ID, checks state/files, acquires lock
 * @return array [jobDir, uploadFile, stateFile, state, lock]
 */
function validate_job_and_acquire_lock($jobId): array
{
    if (!$jobId) {
        http_response_code(400);
        echo json_encode(['error' => 'job_id required']);
        exit;
    }
    try {
        $jobDir = ImportJob::getDir($jobId);
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
    $lock = ImportJob::acquireLock($jobDir, $jobId);
    if ($lock === false) {
        http_response_code(409);
        echo json_encode(['error' => 'job_busy']);
        exit;
    }

    $state = ImportJob::readState($jobDir);
    if (isset($state['status']) && $state['status'] === 'paused') {
        ImportJob::releaseLock($lock);
        echo json_encode($state);
        exit;
    }

    return [$jobDir, $uploadFile, $stateFile, $state, $lock];
}

function handle_process()
{
    $misc = AppContainer::getMisc();
    $conf = AppContainer::getConf();
    $pg = AppContainer::getPostgres();

    header('Content-Type: application/json');
    $jobId = $_REQUEST['job_id'] ?? '';

    // Capture any incidental HTML output from lower layers (DB adapters,
    // error handlers) so we can always return clean JSON to the client.
    ob_start();

    // Validate and lock
    list($jobDir, $uploadFile, $stateFile, $state, $lock) = validate_job_and_acquire_lock($jobId);

    $chunkSize = $conf['import']['chunk_size'] ?? (2 * 1024 * 1024);
    $timeLimit = (float) ($conf['import']['process_time_limit'] ?? 2.0);

    $existing = $state['buffer'] ?? '';
    $opts = $state['options'] ?? ['roles' => false, 'tablespaces' => false, 'schema_create' => false, 'truncate' => false, 'defer_self' => true];

    $type = CompressionReader::detect($uploadFile);
    $reader = null;
    $res = null;

    try {
        $reader = ImportExecutor::createReaderForJob($uploadFile, $state, $conf);

        if (($state['offset'] ?? 0) > 0) {
            $reader->seek((int) $state['offset']);
        }

        $maxChunks = (int) ($conf['import']['max_chunks_per_request'] ?? (($type === 'plain') ? 1 : 3));
        $deadline = microtime(true) + $timeLimit;
        $res = ImportExecutor::parseStatementsFromReader($reader, $chunkSize, $existing, $maxChunks, $deadline);
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
            ImportJob::writeState($jobDir, $state);
            ImportJob::releaseLock($lock);
            if (ob_get_level())
                ob_end_clean();
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

    // Execute statements
    $logs = $state['log'] ?? [];
    $errors = $state['errors'] ?? 0;
    $roleActions = new RoleActions($pg);
    $isSuper = $roleActions->isSuperUser();
    $scope = $state['scope'] ?? 'database';

    $allowCategory = function ($cat) use ($scope, $isSuper) {
        switch ($scope) {
            case 'server':
                return $isSuper || !in_array($cat, ['cluster_object', 'connection_change']);
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

    // Silence global ADODB error output while running import statements
    $GLOBALS['phppgadmin_import_quiet'] = true;
    try {
        ImportExecutor::executeStatementsBatch($statements, $opts, $state, $pg, $scope, $isSuper, $allowCategory, $logs, $errors);
    } catch (Exception $e) {
        // Ensure quiet flag is cleared before we exit the request
        unset($GLOBALS['phppgadmin_import_quiet']);

        $state['status'] = 'error';
        $state['error'] = 'statement_failed';
        $state['error_detail'] = $e->getMessage();
        $state['log'] = $logs;
        $state['errors'] = $errors;
        $state['last_activity'] = time();
        ImportJob::writeState($jobDir, $state);
        ImportJob::releaseLock($lock);
        if (ob_get_level())
            ob_end_clean();
        http_response_code(500);
        echo json_encode(['error' => 'statement_failed', 'detail' => $e->getMessage(), 'offset' => $state['offset'], 'errors' => $errors]);
        exit;
    }
    // Clear quiet flag after successful execution
    unset($GLOBALS['phppgadmin_import_quiet']);

    // If error_mode is 'abort' we expect the executor to throw; however
    // as a safety-net, if errors were recorded and error_mode is 'abort',
    // mark the job as errored so it is not reported as still-running/finished.
    if (!empty($errors) && (($opts['error_mode'] ?? '') === 'abort')) {
        $state['status'] = 'error';
        $state['error'] = 'statement_failed';
        $state['error_detail'] = 'One or more statements failed during import';
    }

    $state['log'] = $logs;
    $state['errors'] = $errors;

    // Flush deferred queues if finished
    ImportExecutor::flushDeferredQueuesIfFinished($state, $opts, $pg, $isSuper, $scope, $allowCategory);

    // Persist finished state if we've consumed the whole upload
    if (!empty($state['size']) && isset($state['offset']) && $state['offset'] >= $state['size']) {
        // Do not overwrite explicit error state
        if (!isset($state['status']) || $state['status'] !== 'error') {
            $state['status'] = 'finished';
        }
    }

    ImportJob::writeState($jobDir, $state);
    ImportJob::releaseLock($lock);

    // small lazy GC probe as we process
    ImportJob::lazyGc();
    if (ob_get_level())
        ob_end_clean();
    $reportedStatus = $state['status'];
    if (!empty($state['size']) && isset($state['offset']) && $state['offset'] >= $state['size']) {
        $reportedStatus = 'finished';
    }
    echo json_encode([
        'job_id' => $jobId,
        'offset' => $state['offset'],
        'size' => $state['size'],
        'status' => $reportedStatus,
        'errors' => $errors,
        'log' => $state['log'] ?? []
    ]);
}

function handle_gc(): void
{
    header('Content-Type: application/json');
    $deleted = ImportJob::gc();
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
        $jobDir = ImportJob::getDir($jobId);
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
        $entries = ImportExecutor::getValidZipEntries($uploadFile, $maxEntries, $maxEntrySize);
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
        $jobDir = ImportJob::getDir($jobId);
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
    $lock = ImportJob::acquireLock($jobDir, $jobId);
    if ($lock === false) {
        http_response_code(409);
        echo json_encode(['error' => 'job_busy']);
        exit;
    }
    $state = ImportJob::readState($jobDir);
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
        $uploadFile = $jobDir . DIRECTORY_SEPARATOR . 'upload.dump';
        $conf = AppContainer::getConf();
        $maxEntries = (int) ($conf['import']['max_zip_entries'] ?? 1000);
        $maxEntrySize = (int) ($conf['import']['max_zip_entry_uncompressed_size'] ?? (10 * 1024 * 1024 * 1024));
        $entries = ImportExecutor::getValidZipEntries($uploadFile, $maxEntries, $maxEntrySize);
        $found = false;
        foreach ($entries as $e) {
            if ($e['name'] === $sel) {
                $found = true;
                break;
            }
        }
        if (!$found) {
            ImportJob::releaseLock($lock);
            http_response_code(404);
            echo json_encode(['error' => 'entry_not_found']);
            exit;
        }
        $state['selected_entry'] = $sel;
        $state['import_all_entries'] = false;
        $state['offset'] = 0;
    } else {
        ImportJob::releaseLock($lock);
        http_response_code(400);
        echo json_encode(['error' => 'entry or import_all required']);
        exit;
    }
    $state['last_activity'] = time();
    ImportJob::writeState($jobDir, $state);
    ImportJob::releaseLock($lock);
    echo json_encode(['ok' => true]);
}

function handle_list_jobs(): void
{
    $misc = AppContainer::getMisc();
    $conf = AppContainer::getConf();
    header('Content-Type: application/json');
    $base = ImportJob::getBaseDir();
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

function handle_pause_job(): void
{
    header('Content-Type: application/json');
    $jobId = $_REQUEST['job_id'] ?? '';
    if (!$jobId) {
        http_response_code(400);
        echo json_encode(['error' => 'job_id required']);
        exit;
    }
    try {
        $jobDir = ImportJob::getDir($jobId);
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
    $lock = ImportJob::acquireLock($jobDir, $jobId);
    if ($lock === false) {
        http_response_code(409);
        echo json_encode(['error' => 'job_busy']);
        exit;
    }
    $s = ImportJob::readState($jobDir);
    $s['status'] = 'paused';
    $s['last_activity'] = time();
    ImportJob::writeState($jobDir, $s);
    ImportJob::releaseLock($lock);
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
        $jobDir = ImportJob::getDir($jobId);
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
    $lock = ImportJob::acquireLock($jobDir, $jobId);
    if ($lock === false) {
        http_response_code(409);
        echo json_encode(['error' => 'job_busy']);
        exit;
    }
    $s = ImportJob::readState($jobDir);
    if (isset($s['status']) && $s['status'] === 'paused') {
        $s['status'] = 'running';
        $s['last_activity'] = time();
        ImportJob::writeState($jobDir, $s);
        ImportJob::releaseLock($lock);
        echo json_encode(['ok' => true]);
    } else {
        ImportJob::releaseLock($lock);
        echo json_encode(['ok' => false, 'reason' => 'not_paused']);
    }
}

function handle_delete_job(): void
{
    header('Content-Type: application/json');

    $jobId = $_REQUEST['job_id'] ?? '';
    if (!$jobId) {
        echo json_encode(['error' => 'missing job id']);
        return;
    }

    try {
        $jobDir = ImportJob::getDir($jobId);
    } catch (Exception $e) {
        echo json_encode(['error' => $e->getMessage()]);
        return;
    }

    if (!is_dir($jobDir)) {
        echo json_encode(['error' => 'job not found']);
        return;
    }

    $lock = ImportJob::acquireLock($jobDir, $jobId);
    if ($lock === false) {
        echo json_encode(['error' => 'failed to acquire lock']);
        return;
    }

    try {
        $it = new RecursiveDirectoryIterator($jobDir, RecursiveDirectoryIterator::SKIP_DOTS);
        $files = new RecursiveIteratorIterator($it, RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($files as $file) {
            if ($file->isDir()) {
                @rmdir($file->getRealPath());
            } else {
                @unlink($file->getRealPath());
            }
        }
        $removed = @rmdir($jobDir);
    } catch (Exception $e) {
        ImportJob::releaseLock($lock);
        echo json_encode(['error' => 'delete failed: ' . $e->getMessage()]);
        return;
    }

    ImportJob::releaseLock($lock);

    if ($removed) {
        echo json_encode(['ok' => true]);
    } else {
        echo json_encode(['error' => 'failed to remove job directory']);
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
    case 'pause_job':
        handle_pause_job();
        break;
    case 'resume_job':
        handle_resume_job();
        break;
    case 'delete_job':
        handle_delete_job();
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
