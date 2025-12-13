<?php


namespace PhpPgAdmin\Core;


use PhpPgAdmin\Misc;
use PhpPgAdmin\PluginManager;
use Postgres as PostgresLegacy;
use PhpPgAdmin\Database\Postgres as PostgresNew;

abstract class AbstractContext {

	protected function lang(): array
	{
		$lang = Container::getLang();
		if (!$lang && isset($GLOBALS['lang'])) {
			$lang = $GLOBALS['lang'];
		}
		return (array) $lang;
	}

	protected function conf(): array
	{
		$conf = Container::getConf();
		if (!$conf && isset($GLOBALS['conf'])) {
			$conf = $GLOBALS['conf'];
		}
		return (array) $conf;
	}

	protected function data(): ?PostgresLegacy
	{
		$data = Container::getData();
		if (!$data && isset($GLOBALS['data'])) {
			$data = $GLOBALS['data'];
		}
		return $data;
	}

	protected function postgres(): ?PostgresNew
	{
		return Container::getPostgres();
	}

	protected function misc(): Misc
	{
		$data = Container::getMisc();
		if (!$data && isset($GLOBALS['misc'])) {
			$data = $GLOBALS['misc'];
		}
		return $data;
	}

	protected function pluginManager(): PluginManager
	{
		$pm = Container::getPluginManager();
		if (!$pm && isset($GLOBALS['plugin_manager'])) {
			$pm = $GLOBALS['plugin_manager'];
		}
		return $pm;
	}

}