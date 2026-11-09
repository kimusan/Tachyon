<?php

// Run with: php test/theme-accents.php
// A theme can declare palettes in a theme.json next to its styles.css. The id
// ends up in the data-accent attribute on <html> and in the user's settings,
// the swatch in a style and the name in the settings screen, and a custom
// theme's manifest is written by whoever installed it, so anything that is not
// a plain id, a short name and a hex colour has to be dropped on the way in.
//
// Uses a throwaway custom theme under themes/ at the repo root, which is
// removed again at the end, and the bundled Modern and Tachyon themes.
//
// Verified in the Nextcloud test env: picking Modern shows its seven swatches,
// Plum sets data-accent="plum" and --m-accent #6d3fd1 (#a98bff in dark mode),
// the choice survives a reload, and switching to a theme without palettes
// removes the attribute and hides the row.

function check(bool $condition, string $message): void
{
	if (!$condition) {
		throw new RuntimeException($message);
	}
}

$sRoot = dirname(__DIR__);
$sCustom = "{$sRoot}/themes/AccentTest";
$bThemesDir = is_dir("{$sRoot}/themes");
is_dir($sCustom) || mkdir($sCustom, 0700, true);
file_put_contents("{$sCustom}/styles.css", ':root{}');
file_put_contents("{$sCustom}/theme.json", json_encode([
	'default' => 'missing',
	'accents' => [
		'Bad Id'  => ['name' => 'Bad id', 'swatch' => '#123456'],
		'red'     => ['name' => 'Red', 'swatch' => 'red'],
		'tag'     => ['name' => '<b>x</b>', 'swatch' => '#abc'],
		'blank'   => ['name' => ' ', 'swatch' => '#abc'],
		'style'   => ['name' => 'Style', 'swatch' => '#abc;background:url(x)'],
		'nested'  => 'not an object',
		'42'      => ['name' => 'Numeric id', 'swatch' => '#ABC'],
		'sea-1'   => ['name' => 'Sea', 'swatch' => '#1a2b3c']
	]
]));

try {
	$_ENV['TACHYON_INCLUDE_AS_API'] = true;
	require "{$sRoot}/index.php";
	$oActions = \Tachyon\Api::Actions();

	$aCustom = $oActions->GetThemeAccents('AccentTest@custom');
	check(['42', 'sea-1'] === array_map('strval', array_keys($aCustom['accents'] ?? [])),
		'custom theme palettes: '.json_encode($aCustom));
	check('42' === $aCustom['default'], 'an unknown default does not fall back to the first palette: '.$aCustom['default']);

	$aModern = $oActions->GetThemeAccents('Modern');
	check('modern' === ($aModern['default'] ?? null), 'Modern has no default palette');
	check(['modern', 'evergreen', 'midnight', 'clay', 'graphite', 'ink', 'plum'] === array_keys($aModern['accents']),
		'Modern palettes: '.json_encode(array_keys($aModern['accents'] ?? [])));

	$aTachyon = $oActions->GetThemeAccents('Tachyon');
	check('blue' === ($aTachyon['default'] ?? null), 'Tachyon has no default palette');
	check(['blue', 'teal', 'green', 'purple', 'pink', 'orange', 'graphite'] === array_keys($aTachyon['accents']),
		'Tachyon palettes: '.json_encode(array_keys($aTachyon['accents'] ?? [])));

	check([] === $oActions->GetThemeAccents('Default'), 'a theme without theme.json has palettes');
	check([] === $oActions->GetThemeAccents('../themes/Modern'), 'a theme name that is not installed is read');
	check([] === $oActions->GetThemeAccents('AccentTest'), 'a custom theme is read without its @custom suffix');

	check('plum' === $oActions->ValidateThemeAccent('Modern', 'plum'), 'a declared palette is refused');
	check('' === $oActions->ValidateThemeAccent('Modern', 'nope'), 'an undeclared palette is accepted');
	check('' === $oActions->ValidateThemeAccent('Default', 'plum'), 'a palette is accepted for a theme without any');
	check('' === $oActions->ValidateThemeAccent('Tachyon', 'plum'), "another theme's palette is accepted");
	check('' === $oActions->ValidateThemeAccent('AccentTest@custom', 'tag'), 'a dropped palette is accepted');

	// The upstream variants map to Modern with their palette once removed
	check('Modern' === $oActions->ValidateTheme('ModernPlum@custom'), 'ModernPlum@custom does not become Modern');
	$oProp = new ReflectionProperty($oActions, 'sMergedThemeAccent');
	check('plum' === $oProp->getValue($oActions), 'ModernPlum@custom loses its palette');

	// Every palette but the default needs its block in the stylesheet, in light,
	// dark and system dark, or picking it changes nothing
	foreach (['Modern' => $aModern, 'Tachyon' => $aTachyon] as $sTheme => $aTheme) {
		$sCss = file_get_contents("{$sRoot}/tachyon/v/0.0.0/themes/{$sTheme}/styles.css");
		foreach (array_keys($aTheme['accents']) as $sId) {
			if ($aTheme['default'] !== $sId) {
				$sAt = preg_quote(":root[data-accent=\"{$sId}\"]", '/');
				check(1 === preg_match_all("/{$sAt}\\s*\\{/", $sCss), "{$sTheme} {$sId}: no light block");
				check(1 === preg_match_all("/{$sAt}\\[data-color-scheme=\"dark\"\\]\\s*\\{/", $sCss), "{$sTheme} {$sId}: no dark block");
				check(1 === preg_match_all("/{$sAt}:not\\(\\[data-color-scheme=\"light\"\\]\\)\\s*\\{/", $sCss), "{$sTheme} {$sId}: no system dark block");
			}
		}
	}
} finally {
	unlink("{$sCustom}/styles.css");
	unlink("{$sCustom}/theme.json");
	rmdir($sCustom);
	$bThemesDir || rmdir("{$sRoot}/themes");
}

echo "theme-accents: ok\n";
