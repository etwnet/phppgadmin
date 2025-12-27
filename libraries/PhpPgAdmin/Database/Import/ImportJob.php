<?php

namespace PhpPgAdmin\Database\Import;

use PhpPgAdmin\Core\AppContainer;
use Exception;

class ImportJob
{
    public static function validateId(string $jobId): bool
    {
        if ($jobId === '' || strlen($jobId) > 128) {
            return false;
        }
        // Disallow path separators and traversal outright.
        if (strpos($jobId, '/') !== false || strpos($jobId, '\\') !== false || strpos($jobId, '..') !== false) {
            return false;
        }

        // Accept legacy uniqid('import_', true) format and new random hex format.
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

    public static function getBaseDir(): string
    {
        $conf = AppContainer::getConf();
        $base = $conf['import']['temp_dir'] ?? (sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'phppgadmin_imports');
        return rtrim($base, '/\\');
    }

    public static function getDir(string $jobId): string
    {
        if (!self::validateId($jobId)) {
            throw new Exception('invalid job id');
        }
        $base = self::getBaseDir();
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

    public static function acquireLock(string $jobDir, string $jobId = null)
    {
        // Decide lock mode
        $conf = AppContainer::getConf();
        $mode = $conf['import']['lock_mode'] ?? 'pg_advisory';

        // Disabled locking
        if ($mode === 'disabled') {
            return ['type' => 'none'];
        }

        // Try Postgres advisory lock when configured
        if ($mode === 'pg_advisory') {
            try {
                $pg = AppContainer::getPostgres();
                if ($jobId === null) {
                    $jobId = basename($jobDir);
                }
                // derive two 32-bit keys from crc32
                $a = sprintf('%u', crc32($jobId));
                $b = sprintf('%u', crc32($jobId . '::pg'));
                $res = $pg->selectField("SELECT pg_try_advisory_lock({$a}, {$b}) AS locked", 'locked');
                if ($res === -1 || $res === false) {
                    return false;
                }
                if ($res) {
                    return ['type' => 'pg_advisory', 'a' => (int) $a, 'b' => (int) $b];
                }
                return false;
            } catch (Exception $e) {
                // fall through to file locking
            }
        }

        // File-based lock fallback
        $lockFile = $jobDir . DIRECTORY_SEPARATOR . '.lock';
        $fh = @fopen($lockFile, 'c+');
        if ($fh === false) {
            return false;
        }

        // Try fast non-blocking acquire
        if (@flock($fh, LOCK_EX | LOCK_NB)) {
            // on success write PID+timestamp for diagnostics
            ftruncate($fh, 0);
            rewind($fh);
            $meta = json_encode(['pid' => getmypid(), 'ts' => time()]);
            @fwrite($fh, $meta);
            fflush($fh);
            return ['type' => 'file', 'fh' => $fh, 'path' => $lockFile];
        }

        // Couldn't get lock — check for stale holder
        clearstatcache(true, $lockFile);
        rewind($fh);
        $contents = stream_get_contents($fh);
        $meta = @json_decode($contents, true) ?: [];
        $pid = isset($meta['pid']) ? (int) $meta['pid'] : 0;
        $ts = isset($meta['ts']) ? (int) $meta['ts'] : 0;

        $staleAge = $conf['import']['stale_lock_age'] ?? 3600;

        $isStale = false;
        if ($pid > 0 && function_exists('posix_kill')) {
            $isStale = (@posix_kill($pid, 0) === false) && ($ts > 0 ? (time() - $ts) > 1 : true);
        } elseif ($ts > 0) {
            $isStale = (time() - $ts) > $staleAge;
        } else {
            $stat = @stat($lockFile);
            $isStale = ($stat && (time() - ($stat['mtime'] ?? 0) > $staleAge));
        }

        if ($isStale) {
            // attempt to remove stale file and retry
            @fclose($fh);
            @unlink($lockFile);
            $fh2 = @fopen($lockFile, 'c+');
            if ($fh2 !== false && @flock($fh2, LOCK_EX | LOCK_NB)) {
                ftruncate($fh2, 0);
                rewind($fh2);
                $meta = json_encode(['pid' => getmypid(), 'ts' => time()]);
                @fwrite($fh2, $meta);
                fflush($fh2);
                return ['type' => 'file', 'fh' => $fh2, 'path' => $lockFile];
            }
            if (is_resource($fh2)) {
                @fclose($fh2);
            }
        } else {
            // not stale — close and report busy
            @fclose($fh);
        }

        return false;
    }

    public static function releaseLock($lock): void
    {
        if (!isset($lock['type'])) {
            return;
        }
        if ($lock['type'] === 'pg_advisory' && isset($lock['a']) && isset($lock['b'])) {
            try {
                $pg = AppContainer::getPostgres();
                $a = (int) $lock['a'];
                $b = (int) $lock['b'];
                $pg->execute("SELECT pg_advisory_unlock({$a}, {$b})");
            } catch (Exception $e) {
                // ignore
            }
            return;
        }
        if ($lock['type'] === 'file' && isset($lock['fh'])) {
            $fh = $lock['fh'];
            if (is_resource($fh)) {
                @flock($fh, LOCK_UN);
                @fclose($fh);
            }
            return;
        }
    }

    public static function readState(string $jobDir)
    {
        $path = $jobDir . DIRECTORY_SEPARATOR . 'state.json';
        $fh = @fopen($path, 'rb');
        if ($fh === false) {
            return null;
        }
        //@flock($fh, LOCK_SH);
        $contents = stream_get_contents($fh);
        //@flock($fh, LOCK_UN);
        fclose($fh);
        if ($contents === false || $contents === '') {
            return null;
        }
        return json_decode($contents, true);
    }

    public static function writeState(string $jobDir, array $data): bool
    {
        $path = $jobDir . DIRECTORY_SEPARATOR . 'state.json';
        $fh = @fopen($path, 'c+');
        if ($fh === false) {
            return false;
        }
        //if (!@flock($fh, LOCK_EX)) {
        //    fclose($fh);
        //    return false;
        //}
        ftruncate($fh, 0);
        rewind($fh);
        $json = json_encode($data);
        if ($json === false) {
            $json = '{}';
        }
        $ok = fwrite($fh, $json) !== false;
        fflush($fh);
        //@flock($fh, LOCK_UN);
        fclose($fh);
        return $ok;
    }

    public static function lazyGc(): void
    {
        $conf = AppContainer::getConf();
        $base = self::getBaseDir();
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

    /**
     * Full garbage-collection: remove job dirs older than $lifetime seconds.
     * Returns number of deleted job dirs.
     */
    public static function gc(): int
    {
        $conf = AppContainer::getConf();
        $base = self::getBaseDir();
        $lifetime = $conf['import']['job_lifetime'] ?? 86400;
        $deleted = 0;
        if (!is_dir($base)) {
            return 0;
        }
        $dirs = glob($base . DIRECTORY_SEPARATOR . '*', GLOB_ONLYDIR);
        foreach ($dirs as $d) {
            $stateFile = $d . DIRECTORY_SEPARATOR . 'state.json';
            if (!is_file($stateFile)) {
                array_map('unlink', glob($d . DIRECTORY_SEPARATOR . '*'));
                @rmdir($d);
                $deleted++;
                continue;
            }
            $state = json_decode(@file_get_contents($stateFile), true);
            if ($state && isset($state['last_activity']) && (time() - $state['last_activity'] > $lifetime)) {
                array_map('unlink', glob($d . DIRECTORY_SEPARATOR . '*'));
                @rmdir($d);
                $deleted++;
            }
        }
        return $deleted;
    }
}
