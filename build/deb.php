<?php
echo "\x1b[33;1m === Debian === \x1b[0m\n";

$deb_version = "{$package->version}-1";
define('DEB_SOURCE_DIR', __DIR__ . '/deb');
define('DEB_DEST_DIR', DEB_SOURCE_DIR . "/tachyon_{$deb_version}_all");
// Holds the changelog and files list that dpkg-gencontrol and dpkg-genchanges share
define('DEB_META_DIR', DEB_DEST_DIR . '.meta');
is_dir(DEB_DEST_DIR) && passthru('rm -dfr '.escapeshellarg(DEB_DEST_DIR));
is_dir(DEB_META_DIR) && passthru('rm -dfr '.escapeshellarg(DEB_META_DIR));

$dir = DEB_DEST_DIR . '/DEBIAN';
mkdir($dir, 0755, true);
copy(DEB_SOURCE_DIR . '/debian/postinst', $dir . '/postinst');
chmod($dir . '/postinst', 0755);
copy(DEB_SOURCE_DIR . '/debian/conffiles', $dir . '/conffiles');

$dir = DEB_DEST_DIR . '/var/lib/tachyon';
mkdir($dir, 0755, true);
file_put_contents($dir . '/VERSION', $package->version);
copy('data/README.md', "{$dir}/README.md");

$dir = DEB_DEST_DIR . '/usr/share/doc/tachyon';
mkdir($dir, 0755, true);
copy('CODE_OF_CONDUCT.md', "{$dir}/CODE_OF_CONDUCT.md");
copy('CONTRIBUTING.md', "{$dir}/CONTRIBUTING.md");
copy('README.md', "{$dir}/README.md");

// Move files into package directory
$dir = DEB_DEST_DIR . '/usr/share/tachyon';
mkdir($dir, 0755, true);
passthru('cp -r "' . dirname(__DIR__) . '/tachyon" "' . $dir . '"');

rename("{$dir}/tachyon/v/0.0.0", "{$dir}/tachyon/v/{$package->version}");

$data = file_get_contents('index.php');
file_put_contents("{$dir}/index.php", str_replace('0.0.0', $package->version, $data));

$data = file_get_contents('_include.php');
file_put_contents("{$dir}/include.php", preg_replace('@(external-tachyon-data-folder/\'\);)@', "\$1\ndefine('APP_DATA_FOLDER_PATH', '/var/lib/tachyon/');", $data));

mkdir(DEB_META_DIR, 0755);
preg_match('/^Maintainer: (.*)$/m', file_get_contents(DEB_SOURCE_DIR . '/debian/control'), $maintainer);
$changelog = "tachyon ({$deb_version}) stable; urgency=medium

  * Release notes: https://github.com/kimusan/Tachyon/releases/tag/v{$package->version}

 -- {$maintainer[1]}  " . gmdate('r') . "
";
file_put_contents(DEB_META_DIR . '/changelog', $changelog);
file_put_contents(DEB_DEST_DIR . '/usr/share/doc/tachyon/changelog.Debian.gz', gzencode($changelog, 9));
$dpkg_args = ' -c' . escapeshellarg(DEB_SOURCE_DIR . '/debian/control')
	. ' -l' . escapeshellarg(DEB_META_DIR . '/changelog')
	. ' -f' . escapeshellarg(DEB_META_DIR . '/files');

passthru('dpkg-gencontrol' . $dpkg_args . ' -P' . escapeshellarg(DEB_DEST_DIR));
passthru('dpkg --build ' . escapeshellarg(DEB_DEST_DIR));

$TARGET_DIR = __DIR__ . "/dist/releases/webmail/{$package->version}/";

passthru('mv '
	. escapeshellarg(DEB_DEST_DIR.'.deb') . ' '
	. escapeshellarg($TARGET_DIR . basename(DEB_DEST_DIR.'.deb'))
);

passthru('dpkg-genchanges -b' . $dpkg_args
	. ' -u' . escapeshellarg($TARGET_DIR)
	. ' -O' . escapeshellarg($TARGET_DIR . basename(DEB_DEST_DIR) . '.changes'));

passthru('rm -dfr ' . escapeshellarg(DEB_DEST_DIR) . ' ' . escapeshellarg(DEB_META_DIR));

// https://github.com/the-djmaze/snappymail/issues/185#issuecomment-1059420588
$cwd = getcwd();
chdir($TARGET_DIR);
passthru('dpkg-scanpackages . /dev/null > '.escapeshellarg($TARGET_DIR . 'Packages'));
passthru('dpkg-scanpackages . /dev/null | gzip -9c > '.escapeshellarg($TARGET_DIR . 'Packages.gz'));
$size = filesize($TARGET_DIR . 'Packages');
$gz_size = filesize($TARGET_DIR . 'Packages.gz');
$Release = 'Origin: Tachyon Repository
Label: Tachyon
Suite: stable
Codename: stable
Version: 1.0
Architectures: all
Components: main
Description: Tachyon webmail repository
Date: ' . gmdate('r') . '
MD5Sum:
 ' . hash_file('md5', $TARGET_DIR . 'Packages') . ' ' . $size . ' Packages
 ' . hash_file('md5', $TARGET_DIR . 'Packages.gz') . ' ' . $gz_size . ' Packages.gz
SHA1:
 ' . hash_file('sha1', $TARGET_DIR . 'Packages') . ' ' . $size . ' Packages
 ' . hash_file('sha1', $TARGET_DIR . 'Packages.gz') . ' ' . $gz_size . ' Packages.gz
SHA256:
 ' . hash_file('sha256', $TARGET_DIR . 'Packages') . ' ' . $size . ' Packages
 ' . hash_file('sha256', $TARGET_DIR . 'Packages.gz') . ' ' . $gz_size . ' Packages.gz
';
file_put_contents($TARGET_DIR . 'Release', $Release);
chdir($cwd);
