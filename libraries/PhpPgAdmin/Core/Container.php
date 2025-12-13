<?php

namespace PhpPgAdmin\Core;

use PhpPgAdmin\Misc;
use PhpPgAdmin\PluginManager;
use Postgres as PostgresLegacy;
use PhpPgAdmin\Database\Connection\Postgres as PostgresNew;

/**
 * Simple singleton container to hold shared application objects during
 * the migration away from globals. Provides explicit getters to retain
 * IDE type support while we phase out globals.
 */
class Container
{
    /** @var Container|null */
    private static $instance;

    /** @var array */
    private $store = [];

    private function __construct()
    {
    }

    /** Retrieve the singleton instance. */
    private static function instance(): Container
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    /** Store a value by key. */
    public static function set(string $key, $value): void
    {
        $container = self::instance();
        $container->store[$key] = $value;
    }

    /** Retrieve a value or a default if absent. */
    public static function get(string $key, $default = null)
    {
        $container = self::instance();

        if (array_key_exists($key, $container->store)) {
            return $container->store[$key];
        }

        return $default;
    }

    /** Check whether a key exists in the container. */
    public static function has(string $key): bool
    {
        $container = self::instance();

        return array_key_exists($key, $container->store);
    }

    /** Reset all stored values (primarily for tests). */
    public static function reset(): void
    {
        $container = self::instance();
        $container->store = [];
    }

    /** Explicit setters/getters for common dependencies */
    public static function setConf(array $conf): void
    {
        self::set('conf', $conf);
    }

    public static function getConf(): array
    {
        return (array) self::get('conf', []);
    }

    public static function setLang(array $lang): void
    {
        self::set('lang', $lang);
    }

    public static function getLang(): array
    {
        return (array) self::get('lang', []);
    }

    public static function setMisc(Misc $misc): void
    {
        self::set('misc', $misc);
    }

    public static function getMisc(): Misc
    {
        return self::get('misc');
    }

    public static function setData(PostgresLegacy $data): void
    {
        self::set('data', $data);
    }

    public static function getData(): ?PostgresLegacy
    {
        return self::get('data');
    }

	public static function setPostgres(PostgresNew $pg): void
	{
		self::set('pg', $pg);
	}

	public static function getPostgres(): ?PostgresNew
	{
		return self::get('pg');
	}

	public static function setPluginManager(PluginManager $pluginManager): void
    {
        self::set('plugin_manager', $pluginManager);
    }

    public static function getPluginManager(): PluginManager
    {
        return self::get('plugin_manager');
    }

}
