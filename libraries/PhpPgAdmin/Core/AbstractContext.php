<?php


namespace PhpPgAdmin\Core;


use PhpPgAdmin\Misc;
use PhpPgAdmin\PluginManager;
use PhpPgAdmin\Database\Postgres as PostgresNew;
use Postgres as PostgresLegacy;

abstract class AbstractContext {

	protected function lang(): array
	{
		return AppContainer::getLang();
	}

	protected function conf(): array
	{
		return AppContainer::getConf();
	}

	protected function data(): ?PostgresLegacy
	{
		return AppContainer::getData();
	}

	protected function postgres(): ?PostgresNew
	{
		return AppContainer::getPostgres();
	}

	protected function misc(): Misc
	{
		return AppContainer::getMisc();
	}

	protected function pluginManager(): PluginManager
	{
		return AppContainer::getPluginManager();
	}

}