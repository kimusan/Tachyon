<?php
define('PLUGINS_DEST_DIR', __DIR__ . '/dist/releases/plugins');

is_dir(PLUGINS_DEST_DIR) || mkdir(PLUGINS_DEST_DIR, 0777, true);
$files = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator(PLUGINS_DEST_DIR, RecursiveDirectoryIterator::SKIP_DOTS),
    RecursiveIteratorIterator::CHILD_FIRST
);
foreach ($files as $fileinfo) {
    $fileinfo->isDir() || unlink($fileinfo->getRealPath());
}

$terser = ROOT_DIR . '/node_modules/terser/bin/terser';

// --release-tag v3.0.1 → use absolute GitHub download URLs in packages.json
$releaseTag = $options['release-tag'] ?? null;
$githubBase = $releaseTag
	? "https://github.com/kimusan/Tachyon/releases/download/{$releaseTag}/"
	: null;

$manifest = [];

// Load AbstractPlugin so plugin classes can extend it
if (is_file(ROOT_DIR . '/tachyon/v/0.0.0/app/libraries/Tachyon/Plugins/AbstractPlugin.php')) {
	require ROOT_DIR . '/tachyon/v/0.0.0/app/libraries/Tachyon/Plugins/AbstractPlugin.php';
	// Alias for plugins that still use the RainLoop namespace
	class_alias(\Tachyon\Plugins\AbstractPlugin::class, \RainLoop\Plugins\AbstractPlugin::class);
} else {
	require ROOT_DIR . '/tachyon/v/0.0.0/app/libraries/RainLoop/Plugins/AbstractPlugin.php';
}

$keys = [
	'author',
	'category',
	'description',
	'file',
	'id',
	'license',
	'name',
	'release',
	'required',
	'type',
	'url',
	'version'
];

foreach (glob(ROOT_DIR . '/plugins/*', GLOB_NOSORT | GLOB_ONLYDIR) as $dir) {
	if (is_file("{$dir}/index.php") && !strpos($dir, '.bak')) {
		require "{$dir}/index.php";
		$name = basename($dir);
		$class = new ReflectionClass(str_replace('-', '', $name) . 'Plugin');
		$manifest_item = [];
		foreach ($class->getConstants() as $key => $value) {
			$key = \strtolower($key);
			if (in_array($key, $keys)) {
				$manifest_item[$key] = $value;
			}
		}
		$version = $manifest_item['version'] ?? '0';
		if (0 < floatval($version)) {
			echo "+ {$name} {$version}\n";

			// Minify JavaScript
			foreach (glob("{$dir}/*.js") as $file) {
				if (!strpos($file,'.min')) {
					$mfile = str_replace('.js', '.min.js', $file);
					passthru("{$terser} {$file} --output {$mfile} --compress 'drop_console' --ecma 6 --mangle");
				}
			}
			foreach (glob("{$dir}/js/*.js") as $file) {
				if (!strpos($file,'.min')) {
					$mfile = str_replace('.js', '.min.js', $file);
					passthru("{$terser} {$file} --output {$mfile} --compress 'drop_console' --ecma 6 --mangle");
				}
			}

			$archive = "{$name}-{$version}.tgz";
			$manifest_item['type'] = 'plugin';
			$manifest_item['id']   = $name;
			$manifest_item['file'] = $githubBase
				? $githubBase . $archive
				: "plugins/{$archive}";

			$tar_destination = PLUGINS_DEST_DIR . "/{$name}-{$version}.tar";
			$tgz_destination = PLUGINS_DEST_DIR . "/{$archive}";
			@unlink($tgz_destination);
			@unlink("{$tar_destination}.gz");
			$tar = new PharData($tar_destination);
			$tar->buildFromDirectory('./plugins/', '/' . \preg_quote("./plugins/{$name}/", '/') . '((?!\.bak).)*$/');
			$tar->compress(Phar::GZ);
			unlink($tar_destination);
			rename("{$tar_destination}.gz", $tgz_destination);

			if (isset($options['sign'])) {
				passthru('gpg --local-user 1016E47079145542F8BA133548208BA13290F3EB --armor --detach-sign '.escapeshellarg($tgz_destination), $return_var);
				$manifest_item['pgp_sig'] = trim(preg_replace('/-----(BEGIN|END) PGP SIGNATURE-----/', '', file_get_contents($tgz_destination.'.asc')));
			}
			ksort($manifest_item);
			$manifest[$name] = $manifest_item;

		} else {
			echo "- {$name} {$version}\n";
		}
	} else {
		echo "- " . basename($dir) . "\n";
	}
}

ksort($manifest);
$json = json_encode(array_values($manifest), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

// Write to build output dir
file_put_contents(dirname(PLUGINS_DEST_DIR) . '/packages.json', $json . "\n");

// Also write to repo root so raw.githubusercontent.com serves the current version
file_put_contents(ROOT_DIR . '/packages.json', $json . "\n");
echo "packages.json written (" . count($manifest) . " plugins)\n";

return;
