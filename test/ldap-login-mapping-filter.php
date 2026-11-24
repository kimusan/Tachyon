<?php

// Run with: php test/ldap-login-mapping-filter.php
// ldap-login-mapping put the login into its LDAP search filter as typed. Only
// the address check in ldapSearch() (a-z, 0-9, ".", "_", "-" before the "@")
// kept filter syntax out, so loosening that check would have let a login
// rewrite the filter. The values are escaped now.
define('APP_VERSION', 'test');
spl_autoload_register(static function (string $class): void {
	$base = dirname(__DIR__).'/tachyon/v/0.0.0/app/libraries/';
	$file = str_starts_with($class, 'Tachyon\\Util\\')
		? $base.'tachyon_util/'.strtolower(str_replace('\\', '/', substr($class, 13))).'.php'
		: $base.str_replace('\\', '/', $class).'.php';
	if (is_file($file)) {
		require_once $file;
	}
});
require dirname(__DIR__).'/plugins/ldap-login-mapping/index.php';

function check(bool $condition, string $message): void
{
	if (!$condition) {
		throw new RuntimeException($message);
	}
}

check(function_exists('ldap_escape'), 'test needs the ldap extension');
check(method_exists(LDAPLoginMappingPlugin::class, 'searchFilter'), 'no searchFilter(): the filter is built inline, unescaped');

$plugin = (new ReflectionClass(LDAPLoginMappingPlugin::class))->newInstanceWithoutConstructor();
$set = fn(string $p, $v) => (new ReflectionProperty(LDAPLoginMappingPlugin::class, $p))->setValue($plugin, $v);
$set('sObjectClass', 'inetOrgPerson');
$set('sEmailField', 'mail');
$set('sLoginField', 'uid');
$filter = fn(string $email, string $login) => (new ReflectionMethod(LDAPLoginMappingPlugin::class, 'searchFilter'))->invoke($plugin, $email, $login);

// An ordinary login is unchanged
$set('sSearchDomain', '');
check('(&(objectclass=inetOrgPerson)(|(mail=alice@example.com)(uid=alice)))' === $filter('alice@example.com', 'alice'),
	'an ordinary login gives a different filter');
$set('sSearchDomain', 'example.org');
check('(&(objectclass=inetOrgPerson)(|(mail=alice@example.org)(uid=alice)))' === $filter('alice@example.com', 'alice'),
	'the forced search domain is not used');

// Filter syntax in the login stays a value
$set('sSearchDomain', '');
$f = $filter('*)(uid=*@example.com', '*)(uid=*');
check('(&(objectclass=inetOrgPerson)(|(mail=\2a\29\28uid=\2a@example.com)(uid=\2a\29\28uid=\2a)))' === $f,
	"a login can still change the filter: {$f}");
$f = $filter("a\\b\0@example.com", "a\\b\0");
check(!str_contains($f, "\0") && str_contains($f, 'a\5cb\00'), "backslash or NUL not escaped: {$f}");

// Every login used to write a LOG_WARNING syslog line with the client IP and
// the address, mapped or not; it goes to Tachyon's own log now, when mapped
$src = file_get_contents(dirname(__DIR__).'/plugins/ldap-login-mapping/index.php');
check(!str_contains($src, 'syslog('), 'every login still writes a syslog warning');

echo "ldap-login-mapping-filter: ok\n";
