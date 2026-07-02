<?php

namespace Tachyon\Util\Rtf\Groups;

use Tachyon\Util\Rtf\ControlWord;
use Tachyon\Util\Rtf\Group;
use Tachyon\Util\Rtf\Text;

/**
 * <colortbl>   '{' \colortbl <colordef>+ '}'
 * <colordef>   <themecolor>? & \ctintN? & \cshadeN? \redN? & \greenN? & \blueN? ';'
 * <themecolor> \cmaindarkone | \cmainlightone | \cmaindarktwo | \cmainlighttwo |
 *              \caccentone | \caccenttwo | \caccentthree | \caccentfour | \caccentfive |
 *              \caccentsix | \chyperlink | \cfollowedhyperlink | \cbackgroundone |
 *              \ctextone | \cbackgroundtwo | \ctexttwo
 */
class ColorTable extends Group
{
	private static $themecolors = [
		'cmaindarkone', 'cmainlightone', 'cmaindarktwo', 'cmainlighttwo',
		'caccentone', 'caccenttwo', 'caccentthree', 'caccentfour', 'caccentfive',
		'caccentsix', 'chyperlink', 'cfollowedhyperlink', 'cbackgroundone',
		'ctextone', 'cbackgroundtwo', 'ctexttwo'
	];

	public function getColors(): array
	{
		$colortbl = [
			null
//			'inherit'
//			'initial'
//			'unset'
		];
		$color = [];
		foreach ($this->children as $i => $entity) {
			if ($entity instanceof ControlWord) {
				if (isset(static::$themecolors[$entity->word])) {
					$color['themecolor'] = $entity->word;
				} else {
					$color[$entity->word] = $entity->parameter;
				}
			} else if ($entity instanceof Text) {
				// This is a delimiter ';' === $entity->text
				if ($i) {
					if (isset($color['red'])) {
						$colortbl[] = "rgb({$color['red']},{$color['green']},{$color['blue']})";
						// hex string format
//						$colortbl[] = \sprintf('#%02x%02x%02x', $color['red'], $color['green'], $color['blue']);
					} else if (isset($color['themecolor'])) {
						$colortbl[] = "var(--rtf-{$color['themecolor']})";
					}
				}
				$color = [];
			}
		}
		return $colortbl;
	}
}
