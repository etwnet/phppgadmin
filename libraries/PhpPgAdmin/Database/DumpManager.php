<?php

namespace PhpPgAdmin\Database;

use PhpPgAdmin\Core\AppContainer;

/**
 * Manages database dump operations and executable detection.
 * Handles pg_dump availability checking, format validation, and strategy selection.
 */
class DumpManager
{
    /**
     * Supported export formats and their requirements
     */
    const FORMATS = [
        'copy' => ['requires_pg_dump' => true, 'name' => 'COPY'],
        'sql' => ['requires_pg_dump' => true, 'name' => 'SQL'],
        'csv' => ['requires_pg_dump' => false, 'name' => 'CSV'],
        'tab' => ['requires_pg_dump' => false, 'name' => 'Tabbed'],
        'html' => ['requires_pg_dump' => false, 'name' => 'XHTML'],
        'xml' => ['requires_pg_dump' => false, 'name' => 'XML'],
    ];

    /**
     * Get available export formats for current dump situation
     * @param bool $all True if doing cluster-wide dump (pg_dumpall)
     * @param string $what 'dataonly', 'structureonly', or 'structureanddata'
     * @return array ['available' => [formats], 'has_pg_dump' => bool]
     */
    public static function getAvailableFormats($all = false, $what = 'dataonly')
    {
        $pg_dump_path = self::detectDumpExecutable($all);
        $has_pg_dump = !empty($pg_dump_path);

        $available = [];

        foreach (self::FORMATS as $format => $info) {
            // COPY/SQL only available for dataonly with pg_dump
            if ($info['requires_pg_dump']) {
                if ($has_pg_dump && $what === 'dataonly') {
                    $available[] = $format;
                }
            } else {
                // CSV/TAB/HTML/XML always available
                $available[] = $format;
            }
        }

        return [
            'available' => $available,
            'has_pg_dump' => $has_pg_dump,
        ];
    }

    /**
     * Validate that a selected format is available for the current dump operation
     * Dies with error message if format is unavailable
     * 
     * @param string $format The requested export format
     * @param bool $all True if doing cluster-wide dump (pg_dumpall)
     * @param string $what 'dataonly', 'structureonly', or 'structureanddata'
     * @return void Dies on error, returns silently if format is valid
     */
    public static function validateFormatAvailable($format, $all = false, $what = 'dataonly')
    {
        $lang = AppContainer::getLang();

        // Normalize format
        $format = strtolower($format);

        if (!isset(self::FORMATS[$format])) {
            echo "Error: Unknown export format: " . htmlspecialchars($format);
            exit;
        }

        $format_info = self::FORMATS[$format];
        $format_name = $format_info['name'];

        // Check if format requires pg_dump
        if ($format_info['requires_pg_dump']) {
            $pg_dump_path = self::detectDumpExecutable($all);

            if (empty($pg_dump_path)) {
                // Build helpful error message
                $exec_type = $all ? 'pg_dumpall' : 'pg_dump';

                echo "Error: {$format_name} format requires {$exec_type} executable.\n\n";
                echo "{$exec_type} executable not found in:\n";
                echo "  - Configured path\n";
                echo "  - Common PostgreSQL installation directories\n";
                echo "  - System PATH environment variable\n\n";
                echo "Please use browser back button to select an available format (CSV, Tab-delimited, XHTML, or XML).\n";
                exit;
            }

            // Ensure dump type matches pg_dump capability
            if ($format === 'copy' || $format === 'sql') {
                if ($what !== 'dataonly') {
                    echo "Error: {$format_name} format only available for data-only exports.\n";
                    exit;
                }
            }
        }

        // Format validation passed
        return;
    }

    /**
     * Get the dump executable path, with automatic detection if needed
     * @param bool $all True for pg_dumpall, false for pg_dump
     * @return string|null Path to executable or null if not found
     */
    public static function getDumpExecutable($all = false)
    {
        return self::detectDumpExecutable($all);
    }

    /**
     * Detects the dump executable (pg_dump or pg_dumpall) path.
     * Searches in order: configured path → common defaults → system PATH.
     * Results are cached in session to avoid repeated lookups.
     * 
     * @param bool $all True to find pg_dumpall, false for pg_dump
     * @return string|null The path to the executable if found, null otherwise
     */
    public static function detectDumpExecutable($all = false)
    {
        $misc = AppContainer::getMisc();
        $info = $misc->getServerInfo();
        $exec_type = $all ? 'pg_dumpall' : 'pg_dump';
        $cache_key = "dump_executable_{$exec_type}";

        // Check session cache first
        if (isset($_SESSION[$cache_key])) {
            // skip for debugging purposes
            return $_SESSION[$cache_key];
        }


        $configured_path = $info[$all ? 'pg_dumpall_path' : 'pg_dump_path'] ?? null;

        // If path is configured, use it (even if empty string means "not found")
        if (isset($info[$all ? 'pg_dumpall_path' : 'pg_dump_path'])) {
            if (!empty($configured_path) && self::_checkExecutable($configured_path)) {
                $_SESSION[$cache_key] = $configured_path;
                return $configured_path;
            }
            // Configured path exists but doesn't work - don't search further
            if (!empty($configured_path)) {
                $_SESSION[$cache_key] = false;
                return null;
            }
        }

        // Try common default paths
        $default_paths = self::_getDefaultDumpPaths($exec_type);
        foreach ($default_paths as $path) {
            if (self::_checkExecutable($path)) {
                $_SESSION[$cache_key] = $path;
                return $path;
            }
        }

        // Fall back to searching PATH environment variable
        $found_path = self::_searchPath($exec_type);
        if ($found_path) {
            $_SESSION[$cache_key] = $found_path;
            return $found_path;
        }

        // Not found - cache the negative result
        $_SESSION[$cache_key] = false;
        return null;
    }

    /**
     * Get common default paths for dump executables based on OS
     * Delegates to _getDefaultExecutablePaths for unified handling
     * @param string $exec_type 'pg_dump' or 'pg_dumpall'
     * @return array List of paths to check
     */
    private static function _getDefaultDumpPaths($exec_type)
    {
        return self::_getDefaultExecutablePaths($exec_type);
    }

    /**
     * Search for executable in system PATH environment variable
     * @param string $exec_type 'pg_dump' or 'pg_dumpall'
     * @return string|null Path to executable if found
     */
    private static function _searchPath($exec_type)
    {
        $is_windows = (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN');

        if ($is_windows) {
            // Use 'where' command on Windows
            $exec_name = $exec_type . '.exe';
            @exec('where ' . escapeshellarg($exec_name), $output, $return_var);
            if ($return_var === 0 && !empty($output)) {
                return trim($output[0]);
            }
        } else {
            // Use 'which' command on Unix-like systems
            @exec('which ' . escapeshellarg($exec_type), $output, $return_var);
            if ($return_var === 0 && !empty($output)) {
                return trim($output[0]);
            }
        }

        return null;
    }

    /**
     * Check if an executable file exists and is executable
     * @param string $path Path to check
     * @return bool True if executable exists and is readable
     */
    private static function _checkExecutable($path)
    {
        return file_exists($path) && is_file($path) && is_readable($path);
    }

    /**
     * Determine if a given subject supports structure-only dumps via pg_dump
     * @param string $subject 'server', 'database', 'schema', 'table', 'view'
     * @return bool
     */
    public static function supportsStructureOnlyDump($subject)
    {
        // All subjects support structure-only dumps
        return in_array($subject, ['server', 'database', 'schema', 'table', 'view']);
    }

    /**
     * Detect psql executable path
     * Searches in order: configured path → common defaults → system PATH.
     * Results are cached in session to avoid repeated lookups.
     * 
     * @return string|null The path to psql executable if found, null otherwise
     */
    public static function detectPsqlExecutable()
    {
        $misc = AppContainer::getMisc();
        $info = $misc->getServerInfo();
        $cache_key = "dump_executable_psql";

        // Check session cache first
        if (isset($_SESSION[$cache_key])) {
            return $_SESSION[$cache_key];
        }

        $configured_path = $info['psql_path'] ?? null;

        // If path is configured, use it
        if (isset($info['psql_path'])) {
            if (!empty($configured_path) && self::_checkExecutable($configured_path)) {
                $_SESSION[$cache_key] = $configured_path;
                return $configured_path;
            }
            // Configured path exists but doesn't work - don't search further
            if (!empty($configured_path)) {
                $_SESSION[$cache_key] = false;
                return null;
            }
        }

        // Try common default paths
        $default_paths = self::_getDefaultExecutablePaths('psql');
        foreach ($default_paths as $path) {
            if (self::_checkExecutable($path)) {
                $_SESSION[$cache_key] = $path;
                return $path;
            }
        }

        // Fall back to searching PATH environment variable
        $found_path = self::_searchPath('psql');
        if ($found_path) {
            $_SESSION[$cache_key] = $found_path;
            return $found_path;
        }

        // Not found - cache the negative result
        $_SESSION[$cache_key] = false;
        return null;
    }

    /**
     * Get psql executable path (wrapper around detectPsqlExecutable)
     * @return string|null Path to psql executable or null if not found
     */
    public static function getPsqlExecutable()
    {
        return self::detectPsqlExecutable();
    }

    /**
     * Get common default paths for executables based on OS
     * Supports both dump executables (pg_dump, pg_dumpall) and psql
     * 
     * @param string $exec_type 'pg_dump', 'pg_dumpall', or 'psql'
     * @return array List of paths to check
     */
    private static function _getDefaultExecutablePaths($exec_type)
    {
        $is_windows = (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN');
        $paths = [];

        if ($is_windows) {
            // Check Program Files directories for PostgreSQL installations
            // Prioritize newest versions first
            $prog_files = [
                getenv('ProgramFiles'),
                getenv('ProgramFiles(x86)'),
            ];

            foreach ($prog_files as $base) {
                if (empty($base)) {
                    continue;
                }

                // Try to find PostgreSQL directories, sorted by version (newest first)
                $glob_pattern = $base . '\\PostgreSQL\\*\\bin\\' . $exec_type . '.exe';
                $found = glob($glob_pattern);
                if ($found) {
                    // Sort descending to prioritize newer versions
                    rsort($found);
                    $paths = array_merge($paths, $found);
                }
            }
        } else {
            // Unix-like systems - check common install locations
            $paths = [
                '/usr/local/bin/' . $exec_type,
                '/usr/bin/' . $exec_type,
                '/opt/postgresql/bin/' . $exec_type,
            ];
        }

        return $paths;
    }

    /**
     * Clear cached dump and utility executable paths (for testing or manual refresh)
     */
    public static function clearExecutableCache()
    {
        unset($_SESSION['dump_executable_pg_dump']);
        unset($_SESSION['dump_executable_pg_dumpall']);
        unset($_SESSION['dump_executable_psql']);
    }
}
