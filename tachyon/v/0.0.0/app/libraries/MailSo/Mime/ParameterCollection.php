<?php

/*
 * This file is part of MailSo.
 *
 * (c) 2014 Usenko Timur
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace MailSo\Mime;

/**
 * @category MailSo
 * @package Mime
 */
class ParameterCollection extends \MailSo\Base\Collection
{
	function __construct(string $sRawParams = '')
	{
		parent::__construct();

		\strlen($sRawParams) && $this->Parse($sRawParams);
	}

	public function append($oParameter, bool $bToTop = false) : void
	{
		assert($oParameter instanceof Parameter);
		parent::offsetSet(\strtolower($oParameter->Name()), $oParameter);
	}

	public function ParameterValueByName(string $sName) : string
	{
		$oParam = $this->getParameter($sName);
		return $oParam ? $oParam->Value() : '';
	}

	public function getParameter(string $sName) : ?Parameter
	{
		$sName = \strtolower(\trim($sName));
		return parent::offsetExists($sName) ? parent::offsetGet($sName) : null;
	}

	public function setParameter(string $sName, string $sValue) : void
	{
		$oParam = $this->getParameter($sName);
		if ($oParam) {
			$oParam->setValue($sValue);
		} else {
			$this->append(new Parameter(\trim($sName), $sValue));
		}
	}

	public function Parse(string $sRawParams) : self
	{
		$this->Clear();

		$aDataToParse = \explode(';', $sRawParams);

		foreach ($aDataToParse as $sParam) {
			$this->append(Parameter::FromString($sParam));
		}

		$this->reParseParameters();

		return $this;
	}

	public function ToString(bool $bConvertSpecialsName = false) : string
	{
		$aResult = array();
		foreach ($this as $oParam) {
			$sLine = $oParam->ToString($bConvertSpecialsName);
			if (\strlen($sLine)) {
				$aResult[] = $sLine;
			}
		}
		return \count($aResult) ? \implode('; ', $aResult) : '';
	}

	public function __toString() : string
	{
		return $this->ToString();
	}

/*
	#[\ReturnTypeWillChange]
	public function jsonSerialize()
	{
		$aResult = array();
		foreach ($this as $oParam) {
			$aResult[$oParam->Name()] = $oParam->Value();
		}
		return array(
			'@Object' => 'Collection/ParameterCollection',
			'@Collection' => $aResult
		);
	}
*/

	/**
	 * RFC 2231: name*0, name*1 ... is one value cut into sections, and a star
	 * after the name or the section number marks that part as percent encoded,
	 * the first one led by charset'language'. Both forms come up: name*0="..."
	 * from mailers cutting a long plain file name, name*0*=utf-8''... from
	 * those encoding it.
	 */
	private function reParseParameters() : void
	{
		$aDataToReParse = $this->getArrayCopy();

		$this->Clear();

		$aPreParams = array();
		$aCharsets = array();
		foreach ($aDataToReParse as $oParam) {
			$aMatch = array();
			if (\preg_match('/^([^*]+)\*(\d*)(\*?)$/D', $oParam->Name(), $aMatch)) {
				$sName = $aMatch[1];
				$iSection = (int) $aMatch[2];
				$sValue = $oParam->Value();
				if ('' === $aMatch[2] || '*' === $aMatch[3]) {
					if (0 === $iSection && \preg_match("/^([^']*)'[^']*'(.*)$/sD", $sValue, $aMatch)) {
						\strlen($aMatch[1]) && $aCharsets[$sName] = $aMatch[1];
						$sValue = $aMatch[2];
					}
					// rawurldecode(): a + is a +, only %XX is encoded
					$sValue = \rawurldecode($sValue);
				}
				$aPreParams[$sName][$iSection] = $sValue;
			} else {
				$this->append($oParam);
			}
		}

		foreach ($aPreParams as $sName => $aValues) {
			\ksort($aValues);
			$sResult = \implode($aValues);
			if (isset($aCharsets[$sName])) {
				$sResult = \MailSo\Base\Utils::ConvertEncoding($sResult,
					$aCharsets[$sName], \MailSo\Base\Enumerations\Charset::UTF_8);
			}
			$this->append(new Parameter($sName, $sResult));
		}
	}
}
