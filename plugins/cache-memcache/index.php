<?php

class CacheMemcachePlugin extends \Tachyon\Plugins\AbstractPlugin
{
	const
		NAME = 'Cache Memcache',
		VERSION = '2.37',
		RELEASE = '2024-09-15',
		REQUIRED = '2.36.0',
		CATEGORY = 'Cache',
		DESCRIPTION = 'Cache handler using PHP Memcache or PHP Memcached';

	public function Init() : void
	{
		if (\class_exists('Memcache',false) || \class_exists('Memcached',false)) {
			$this->addHook('main.fabrica', 'MainFabrica');
		}
	}

	public function Supported() : string
	{
		return (\class_exists('Memcache',false) || \class_exists('Memcached',false))
			? ''
			: 'PHP Memcache/Memcached not installed';
	}

	public function MainFabrica($sName, &$mResult)
	{
		if ('cache' == $sName) {
			require_once __DIR__ . '/Memcache.php';
			$mResult = new \MailSo\Cache\Drivers\Memcache(
				$this->Config()->Get('plugin', 'host', '127.0.0.1'),
				(int) $this->Config()->Get('plugin', 'port', 11211)
			);
		}
	}

	protected function configMapping() : array
	{
		return array(
			\Tachyon\Plugins\Property::NewInstance('host')->SetLabel('Host')
				->SetDescription('Hostname of the memcache server')
				->SetDefaultValue('127.0.0.1'),
			\Tachyon\Plugins\Property::NewInstance('port')->SetLabel('Port')
				->SetDescription('Port of the memcache server')
				->SetDefaultValue(11211)
/*
			,\Tachyon\Plugins\Property::NewInstance('password')->SetLabel('Password')
				->SetType(\Tachyon\Enumerations\PluginPropertyType::PASSWORD)
				->SetDefaultValue('')
*/
		);
	}
}
