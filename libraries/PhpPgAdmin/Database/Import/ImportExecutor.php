<?php

namespace PhpPgAdmin\Database\Import;

use PhpPgAdmin\Database\Import\CompressionReader;
use PhpPgAdmin\Database\Import\ZipEntryReader;
use PhpPgAdmin\Database\Import\GzipReader;
use PhpPgAdmin\Database\Import\Bzip2Reader;
use PhpPgAdmin\Database\Import\LocalFileReader;
use PhpPgAdmin\Database\Import\SqlParser;
use PhpPgAdmin\Database\Import\StatementClassifier;
use ZipArchive;
use Exception;

class ImportExecutor
{
    public static function getValidZipEntries(string $zipPath, int $maxEntries, int $maxEntrySize): array
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

    public static function createReaderForJob($uploadFile, &$state, $conf)
    {
        $type = CompressionReader::detect($uploadFile);
        if (!CompressionReader::isSupported($type)) {
            throw new Exception("unsupported_compression: $type");
        }

        switch ($type) {
            case 'zip':
                $maxEntries = (int) ($conf['import']['max_zip_entries'] ?? 1000);
                $maxEntrySize = (int) ($conf['import']['max_zip_entry_uncompressed_size'] ?? (10 * 1024 * 1024 * 1024));
                $validEntries = self::getValidZipEntries($uploadFile, $maxEntries, $maxEntrySize);
                if (empty($validEntries)) {
                    throw new Exception('No suitable entry found in zip archive');
                }

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

                return new ZipEntryReader($uploadFile, $entry);
            case 'gzip':
                return new GzipReader($uploadFile);
            case 'bzip2':
                return new Bzip2Reader($uploadFile);
            default:
                return new LocalFileReader($uploadFile);
        }
    }

    public static function parseStatementsFromReader($reader, $chunkSize, &$existing, $maxChunks, $deadline)
    {
        $combinedStatements = [];
        $combinedEof = false;
        $totalConsumed = 0;

        for ($i = 0; $i < max(1, $maxChunks); $i++) {
            if (microtime(true) >= $deadline) {
                break;
            }
            $chunkRes = SqlParser::parseFromReader($reader, $chunkSize, $existing);
            $consumed = (int) ($chunkRes['consumed'] ?? 0);
            $stmts = $chunkRes['statements'] ?? [];
            $existing = $chunkRes['remainder'] ?? '';
            $combinedEof = (bool) ($chunkRes['eof'] ?? false);
            $totalConsumed += $consumed;

            if (!empty($stmts)) {
                $combinedStatements = array_merge($combinedStatements, $stmts);
            }
            if ($combinedEof || ($consumed === 0 && empty($stmts))) {
                break;
            }
        }

        return [
            'consumed' => $totalConsumed,
            'statements' => $combinedStatements,
            'remainder' => $existing,
            'eof' => $combinedEof,
        ];
    }

    public static function executeStatementsBatch($statements, $opts, &$state, $pg, $scope, $isSuper, $allowCategory, &$logs, &$errors)
    {
        $currentUser = null;
        if (isset($pg->conn->_connectionID)) {
            $currentUser = pg_parameter_status($pg->conn->_connectionID, 'user');
        }

        foreach ($statements as $stmt) {
            $stmtTrim = trim($stmt);
            if ($stmtTrim === '')
                continue;
            $cat = StatementClassifier::classify($stmtTrim, $currentUser ?? '');

            // Handle self-affecting statements
            if ($cat === 'self_affecting') {
                if (!($opts['defer_self'] ?? true)) {
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

            // Data statements
            if ($cat === 'data' && empty($opts['data'])) {
                $logs[] = ['time' => time(), 'skipped' => true, 'reason' => 'data_disabled', 'statement' => substr($stmtTrim, 0, 200)];
                continue;
            }

            // DROP statements
            if ($cat === 'drop' && empty($opts['allow_drops'])) {
                $logs[] = ['time' => time(), 'blocked' => true, 'reason' => 'drops_not_allowed', 'statement' => substr($stmtTrim, 0, 200)];
                continue;
            }

            // Ownership changes
            if ($cat === 'ownership_change') {
                if (empty($opts['ownership'])) {
                    $logs[] = ['time' => time(), 'skipped' => true, 'reason' => 'ownership_disabled', 'statement' => substr($stmtTrim, 0, 200)];
                    continue;
                }
                $state['ownership_queue'][] = $stmtTrim;
                $logs[] = ['time' => time(), 'queued_ownership' => true, 'statement' => substr($stmtTrim, 0, 200)];
                continue;
            }

            // Rights statements
            if ($cat === 'rights') {
                if (empty($opts['rights'])) {
                    $logs[] = ['time' => time(), 'skipped' => true, 'reason' => 'rights_disabled', 'statement' => substr($stmtTrim, 0, 200)];
                    continue;
                }
                $state['rights_queue'][] = $stmtTrim;
                $logs[] = ['time' => time(), 'queued_rights' => true, 'statement' => substr($stmtTrim, 0, 200)];
                continue;
            }

            // Policy checks
            if (preg_match('/^\s*CREATE\s+SCHEMA\b/i', $stmtTrim) && empty($opts['schema_create'])) {
                $logs[] = ['time' => time(), 'skipped' => true, 'reason' => 'schema_create_disabled', 'statement' => substr($stmtTrim, 0, 200)];
                continue;
            }
            if (preg_match('/^\s*(CREATE|ALTER|DROP)\s+(ROLE|USER)\b/i', $stmtTrim) && empty($opts['roles'])) {
                $logs[] = ['time' => time(), 'skipped' => true, 'reason' => 'roles_disabled', 'statement' => substr($stmtTrim, 0, 200)];
                continue;
            }
            if (preg_match('/^\s*(CREATE|ALTER|DROP)\s+TABLESPACE\b/i', $stmtTrim) && empty($opts['tablespaces'])) {
                $logs[] = ['time' => time(), 'skipped' => true, 'reason' => 'tablespaces_disabled', 'statement' => substr($stmtTrim, 0, 200)];
                continue;
            }

            if (!$allowCategory($cat)) {
                $logs[] = ['time' => time(), 'skipped' => true, 'category' => $cat, 'statement' => substr($stmtTrim, 0, 200)];
                continue;
            }

            // Truncate logic
            if ($cat === 'data' && (!empty($opts['truncate']))) {
                $rawTable = null;
                if (preg_match('/^\s*INSERT\s+INTO\s+([^\s(]+)/i', $stmtTrim, $m) || preg_match('/^\s*COPY\s+([^\s(]+)/i', $stmtTrim, $m)) {
                    $rawTable = $m[1];
                }
                if ($rawTable) {
                    $rawTable = trim($rawTable);
                    $rawTable = preg_replace('/^"(.*)"$/', '$1', $rawTable);
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

            // Execute statement
            $err = $pg->execute($stmtTrim);
            if ($err !== 0) {
                $errors++;
                $logs[] = ['time' => time(), 'error' => $err, 'statement' => substr($stmtTrim, 0, 200)];
                $errorMode = $opts['error_mode'] ?? 'abort';
                if ($errorMode === 'abort') {
                    throw new Exception("Statement failed with error: $err");
                }
            } else {
                $logs[] = ['time' => time(), 'ok' => true, 'statement' => substr($stmtTrim, 0, 200)];
            }
            if (count($logs) > 200)
                array_shift($logs);
        }
    }

    public static function flushDeferredQueuesIfFinished(&$state, $opts, $pg, $isSuper, $scope, $allowCategory)
    {
        if ($state['status'] !== 'finished')
            return;

        // 1. Ownership changes first
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

        // 2. Rights queue
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

        // 3. Self-affecting deferred
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

        // Clear queues
        $state['ownership_queue'] = [];
        $state['rights_queue'] = [];
        $state['deferred'] = [];
    }
}
