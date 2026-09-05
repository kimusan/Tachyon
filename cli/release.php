#!/usr/bin/php
<?php
define('ROOT_DIR', dirname(__DIR__));
chdir(ROOT_DIR);

$options = getopt('', ['aur','docker','skip-gulp','debian','nextcloud','owncloud','cpanel','sign']);

$gulp = trim(`which gulp`);
if (!$gulp) {
	exit('gulp not installed, run as root: npm install --global gulp-cli');
}

$package = json_decode(file_get_contents('package.json'));

/**
 * The key --sign signs with. It was the upstream maintainer's fingerprint,
 * repeated at each call, so anyone else building a signed release either
 * edited eight lines or produced artifacts attributed to someone who had not
 * signed them. Set TACHYON_SIGNING_KEY to use another key without touching
 * this file.
 */
define('SIGNING_KEY', getenv('TACHYON_SIGNING_KEY') ?: '2AF665D52CE22BDEFC606AD1DAD08242CFE59866');

/**
 * Update files that contain version
 */
// cloudron
$file = ROOT_DIR . '/integrations/cloudron/Dockerfile';
file_put_contents($file, preg_replace('/VERSION=[0-9.]+/', "VERSION={$package->version}", file_get_contents($file)));
$file = ROOT_DIR . '/integrations/cloudron/DESCRIPTION.md';
file_put_contents($file, preg_replace('/<upstream>[^<]*</', "<upstream>{$package->version}<", file_get_contents($file)));
// virtualmin
$file = ROOT_DIR . '/integrations/virtualmin/tachyon.pl';
file_put_contents($file, preg_replace('/return \\( "[0-9]+\\.[0-9]+\\.[0-9]+" \\)/', "return ( \"{$package->version}\" )", file_get_contents($file)));

// Arch User Repository, see build/arch/PKGBUILD
$options['aur'] = isset($options['aur']);

// Docker build
$options['docker'] = isset($options['docker']);
if ($options['docker'] && !($docker = trim(`which docker`))) {
	exit('docker not found');
}
if ($options['docker'] && $options['aur']) {
	exit('Conflict between docker and aur');
}

$destPath = "build/dist/releases/webmail/{$package->version}/";
is_dir($destPath) || mkdir($destPath, 0777, true);

$zip_destination = "{$destPath}tachyon-{$package->version}.zip";
$tar_destination = "{$destPath}tachyon-{$package->version}.tar";

@unlink($zip_destination);
@unlink($tar_destination);
@unlink("{$tar_destination}.gz");

if (!isset($options['skip-gulp'])) {
	echo "\x1b[33;1m === Gulp === \x1b[0m\n";
	passthru($gulp, $return_var);
	if ($return_var) {
		exit("gulp failed with error code {$return_var}\n");
	}

	$cmddir = escapeshellcmd(ROOT_DIR) . '/tachyon/v/0.0.0/static';

	if ($gzip = trim(`which gzip`)) {
		echo "\x1b[33;1m === Gzip *.js and *.css === \x1b[0m\n";
		passthru("{$gzip} -k --best {$cmddir}/js/*.js");
		passthru("{$gzip} -k --best {$cmddir}/js/min/*.js");
		passthru("{$gzip} -k --best {$cmddir}/css/admin*.css");
		passthru("{$gzip} -k --best {$cmddir}/css/app*.css");
		unlink(ROOT_DIR . '/tachyon/v/0.0.0/static/js/boot.js.gz');
		unlink(ROOT_DIR . '/tachyon/v/0.0.0/static/js/min/boot.min.js.gz');
	}

	if ($brotli = trim(`which brotli`)) {
		echo "\x1b[33;1m === Brotli *.js and *.css === \x1b[0m\n";
		passthru("{$brotli} -k --best {$cmddir}/js/*.js");
		passthru("{$brotli} -k --best {$cmddir}/js/min/*.js");
		passthru("{$brotli} -k --best {$cmddir}/css/admin*.css");
		passthru("{$brotli} -k --best {$cmddir}/css/app*.css");
		unlink(ROOT_DIR . '/tachyon/v/0.0.0/static/js/boot.js.br');
		unlink(ROOT_DIR . '/tachyon/v/0.0.0/static/js/min/boot.min.js.br');
	}
}

// Temporary rename folder to speed up PharData
//if (!rename('tachyon/v/0.0.0', "tachyon/v/{$package->version}")){
if (!rename('tachyon/v/0.0.0', "tachyon/v/{$package->version}")) {
	exit('Failed to temporary rename tachyon/v/0.0.0');
}
register_shutdown_function(function(){
	// Rename folder back to original
	@rename("tachyon/v/{$GLOBALS['package']->version}", 'tachyon/v/0.0.0');
});

echo "\x1b[33;1m === Zip/Tar === \x1b[0m\n";

$zip = new ZipArchive();
if (!$zip->open($zip_destination, ZIPARCHIVE::CREATE)) {
	exit("Failed to create {$zip_destination}");
}

$tar = new PharData($tar_destination);

$files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator('tachyon/v'), RecursiveIteratorIterator::SELF_FIRST);
foreach ($files as $file) {
	$file = str_replace('\\', '/', $file);
	//echo "{$file}\n";
	// Ignore "." and ".." folders
	if (!in_array(substr($file, strrpos($file, '/')+1), array('.', '..'))) {
		if (is_dir($file)) {
			$zip->addEmptyDir($file);
		} else if (is_file($file)) {
			$zip->addFile($file);
		}
	}
}

if ($options['docker']) {
	$tar->buildFromDirectory('./tachyon/', "@v/{$package->version}@");
} else {
	$tar->buildFromDirectory('./', "@tachyon/v/{$package->version}@");
}

$zip->addFile('data/.htaccess');
$tar->addFile('data/.htaccess');

$zip->addFromString('data/VERSION', $package->version);
$tar->addFromString('data/VERSION', $package->version);

$zip->addFile('data/README.md');
$tar->addFile('data/README.md');

if ($options['aur']) {
	$data = '<?php
function __get_custom_data_full_path()
{
	return \'/var/lib/tachyon\';
}
';
	$zip->addFromString('include.php', $data);
	$tar->addFromString('include.php', $data);
} else {
	$zip->addFile('_include.php');
	$tar->addFile('_include.php');
}

$zip->addFile('.htaccess');
$tar->addFile('.htaccess');

$index = file_get_contents('index.php');
$index = str_replace('0.0.0', $package->version, $index);
$zip->addFromString('index.php', $index);
$tar->addFromString('index.php', $index);

$zip->addFile('README.md');
$tar->addFile('README.md');

$zip->close();

$tar->compress(Phar::GZ);
unlink($tar_destination);
$tar_destination .= '.gz';

echo "{$zip_destination} created\n{$tar_destination} created\n";

if (isset($options['nextcloud'])) {
	require(ROOT_DIR . '/build/nextcloud.php');
}

if (isset($options['owncloud'])) {
	require(ROOT_DIR . '/build/owncloud.php');
}

if (isset($options['cpanel'])) {
	require(ROOT_DIR . '/build/cpanel.php');
}

rename("tachyon/v/{$package->version}", 'tachyon/v/0.0.0');

echo "\x1b[33;1m === Plugins === \x1b[0m\n";
$options['release-tag'] = "v{$package->version}";
// plugins.php is required into this scope and reuses $tar_destination for each
// plugin it builds, unlinking it as it goes. Everything below still means the
// core archive, so it keeps its own reference: --sign was signing the last
// plugin tar, which no longer existed, and the AUR b2sums hashed the same
// missing file.
$core_tar = $tar_destination;
$core_zip = $zip_destination;
require(ROOT_DIR . '/build/plugins.php');

// Arch User Repository
if ($options['aur']) {
	// extension_loaded('blake2')
	if (!function_exists('b2sum') && $b2sum = trim(`which b2sum`)) {
		function b2sum($file) {
			$file = escapeshellarg($file);
			exec("b2sum --binary {$file} 2>&1", $output, $exitcode);
			$output = explode(' ', implode("\n", $output));
			return $output[0];
		}
	}

	$b2sums = function_exists('b2sum') ? [
		b2sum($core_tar),
		b2sum(ROOT_DIR . '/build/arch/tachyon.sysusers'),
		b2sum(ROOT_DIR . '/build/arch/tachyon.tmpfiles')
	] : [];

	// Describes build/arch/PKGBUILD, so the two have to say the same thing.
	// It described the upstream snappymail package while PKGBUILD had already
	// been rewritten for Tachyon, which left the AUR metadata pointing at
	// another project entirely.
	file_put_contents('build/arch/.SRCINFO', 'pkgbase = tachyon
	pkgdesc = Fast, secure, modern PHP webmail client
	pkgver = '.$package->version.'
	pkgrel = 1
	url = https://github.com/kimusan/Tachyon
	arch = any
	license = AGPL3
	makedepends = php
	makedepends = nodejs
	makedepends = yarn
	makedepends = gulp
	makedepends = rollup
	depends = php-fpm
	optdepends = mariadb: storage backend for contacts
	optdepends = php-pgsql: storage backend for contacts
	optdepends = php-sqlite: storage backend for contacts
	source = tachyon-'.$package->version.'.tar.gz::https://github.com/kimusan/Tachyon/archive/v'.$package->version.'.tar.gz
	source = tachyon.sysusers
	source = tachyon.tmpfiles
	b2sums = '.implode("\n	b2sums = ", $b2sums).'

pkgname = tachyon
');

	$file = ROOT_DIR . '/build/arch/PKGBUILD';
	if (is_file($file)) {
		$PKGBUILD = file_get_contents($file);
		$PKGBUILD = preg_replace('/pkgver=[0-9.]+/', "pkgver={$package->version}", $PKGBUILD);
		$PKGBUILD = preg_replace('/b2sums=\\([^)]+\\)/s', "b2sums=('".implode("'\n        '", $b2sums)."')", $PKGBUILD);
		file_put_contents($file, $PKGBUILD);
	}
}
// Debian Repository
else if (isset($options['debian'])) {
	require(ROOT_DIR . '/build/deb.php');
}
// Docker build
else if ($options['docker']) {
	echo "\x1b[33;1m === Docker === \x1b[0m\n";
	$zip_filename = "tachyon-{$package->version}.zip";
	copy($zip_destination, "./.docker/release/{$zip_filename}");
	if ($docker) {
		passthru("{$docker} build --pull " . ROOT_DIR . "/.docker/release/ --build-arg FILES_ZIP={$zip_filename} -t tachyon:{$package->version}");
	} else {
		echo "Docker not installed!\n";
	}
}

if (isset($options['sign'])) {
	echo "\x1b[33;1m === PGP Sign === \x1b[0m\n";
	// Checked once here rather than letting each gpg call fail on its own, which
	// leaves a release directory of unsigned artifacts and a run of identical errors
	exec('gpg --list-secret-keys ' . escapeshellarg(SIGNING_KEY) . ' 2>&1', $out, $code);
	if ($code) {
		exit('No secret key for ' . SIGNING_KEY . " in this keyring, nothing signed.\n");
	}
	passthru('gpg --local-user ' . escapeshellarg(SIGNING_KEY) . ' --armor --detach-sign '.escapeshellarg($core_tar), $return_var);
	passthru('gpg --local-user ' . escapeshellarg(SIGNING_KEY) . ' --armor --detach-sign '.escapeshellarg($core_zip), $return_var);
	if (isset($options['nextcloud'])) {
		passthru('gpg --local-user ' . escapeshellarg(SIGNING_KEY) . ' --armor --detach-sign '
			.escapeshellarg("{$destPath}tachyon-{$package->version}-nextcloud.tar.gz"), $return_var);
	}
	if (isset($options['owncloud'])) {
		passthru('gpg --local-user ' . escapeshellarg(SIGNING_KEY) . ' --armor --detach-sign '
			.escapeshellarg("{$destPath}tachyon-{$package->version}-owncloud.tar.gz"), $return_var);
	}
	if (isset($options['cpanel'])) {
		passthru('gpg --local-user ' . escapeshellarg(SIGNING_KEY) . ' --armor --detach-sign '
			.escapeshellarg("{$destPath}tachyon-{$package->version}-cpanel.tar.gz"), $return_var);
	}
	if (isset($options['debian'])) {
		passthru('gpg --local-user ' . escapeshellarg(SIGNING_KEY) . ' --armor --detach-sign '
			. escapeshellarg(ROOT_DIR . "/build/dist/releases/webmail/{$package->version}/" . basename(DEB_DEST_DIR.'.deb')), $return_var);
		// https://github.com/the-djmaze/snappymail/issues/185#issuecomment-1059420588
		passthru('gpg --local-user ' . escapeshellarg(SIGNING_KEY) . ' --digest-algo SHA512 --clearsign --output '
			. escapeshellarg(ROOT_DIR . "/build/dist/releases/webmail/{$package->version}/InRelease") . ' '
			. escapeshellarg(ROOT_DIR . "/build/dist/releases/webmail/{$package->version}/Release"), $return_var);
		passthru('gpg --local-user ' . escapeshellarg(SIGNING_KEY) . ' --digest-algo SHA512 -abs --output '
			. escapeshellarg(ROOT_DIR . "/build/dist/releases/webmail/{$package->version}/Release.gpg") . ' '
			. escapeshellarg(ROOT_DIR . "/build/dist/releases/webmail/{$package->version}/Release"), $return_var);
	}
}
