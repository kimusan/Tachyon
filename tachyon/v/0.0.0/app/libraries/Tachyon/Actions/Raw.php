<?php

namespace Tachyon\Actions;

trait Raw
{
	/**
	 * @throws \MailSo\RuntimeException
	 */
	public function RawViewAsPlain() : bool
	{
		$oAccount = $this->getAccountFromToken();
		$sRawKey = $this->GetActionParam('RawKey', '');
		$aValues = $this->decodeRawKey($sRawKey);
		if (!empty($aValues['folder']) && !empty($aValues['uid'])
		 && !empty($aValues['accountHash']) && $aValues['accountHash'] === $oAccount->Hash()
		) {
			$this->verifyCacheByKey($sRawKey);
			$this->initMailClientConnection();
			\header('Content-Type: text/plain');
			return $this->MailClient()->MessageMimeStream(
				function ($rResource) use ($sRawKey) {
					if (\is_resource($rResource)) {
						$this->cacheByKey($sRawKey);
						\MailSo\Base\Utils::FpassthruWithTimeLimitReset($rResource);
					}
				},
				(string) $aValues['folder'],
				(int) $aValues['uid'],
				isset($aValues['mimeIndex']) ? (string) $aValues['mimeIndex'] : ''
			);
		}
		return false;
	}

	public function RawDownload() : bool
	{
		return $this->rawSmart(true);
	}

	public function RawView() : bool
	{
		return $this->rawSmart(false);
	}

	public function RawViewThumbnail() : bool
	{
		return $this->rawSmart(false, true);
	}

	public function RawUserBackground() : bool
	{
		$sRawKey = (string) $this->GetActionParam('RawKey', '');
		$this->verifyCacheByKey($sRawKey);

		$oAccount = $this->getAccountFromToken();

		$mData = $this->StorageProvider()->Get($oAccount,
			\Tachyon\Providers\Storage\Enumerations\StorageType::CONFIG,
			'background'
		);

		if (!empty($mData)) {
			$mData = \json_decode($mData, true);
			if (!empty($mData['ContentType']) && !empty($mData['Raw'])) {
				$this->cacheByKey($sRawKey);

				\header('Content-Type: '.$mData['ContentType']);
				echo \base64_decode($mData['Raw']);
				unset($mData);

				return true;
			}
		}

		return false;
	}

	private function clearFileName(string $sFileName, string $sContentType, string $sMimeIndex, int $iMaxLength = 250): string
	{
		$sFileName = !\strlen($sFileName) ? \preg_replace('/[^a-zA-Z0-9]/', '.', (empty($sMimeIndex) ? '' : $sMimeIndex . '.') . $sContentType) : $sFileName;
		$sClearedFileName = \MailSo\Base\Utils::StripSpaces(\preg_replace('/[\.]+/', '.', $sFileName));
		$sExt = \MailSo\Base\Utils::GetFileExtension($sClearedFileName);

		if (10 < $iMaxLength && $iMaxLength < \strlen($sClearedFileName) - \strlen($sExt)) {
			$sClearedFileName = \substr($sClearedFileName, 0, $iMaxLength) . (empty($sExt) ? '' : '.' . $sExt);
		}

		return \MailSo\Base\Utils::SecureFileName(\MailSo\Base\Utils::Utf8Clear($sClearedFileName));
	}

	/**
	 * Message, Message Attachment or Zip
	 */
	private function rawSmart(bool $bDownload, bool $bThumbnail = false) : bool
	{
		$sRawKey = (string) $this->GetActionParam('RawKey', '');

		$oAccount = $this->getAccountFromToken();
		$aValues = $this->decodeRawKey($sRawKey);
		if (empty($aValues['accountHash']) || $aValues['accountHash'] !== $oAccount->Hash()) {
			return false;
		}

		$sRange = \MailSo\Base\Http::GetHeader('Range');
		$aMatch = array();
		$sRangeStart = $sRangeEnd = '';
		$bIsRangeRequest = false;
		if (!empty($sRange) && 'bytes=0-' !== \strtolower($sRange)
		 && \preg_match('/^bytes=([0-9]+)-([0-9]*)/i', \trim($sRange), $aMatch))
		{
			$sRangeStart = $aMatch[1];
			$sRangeEnd = $aMatch[2];
			$bIsRangeRequest = true;
		} else {
			$this->verifyCacheByKey($sRawKey);
		}

		$sMimeIndex = isset($aValues['mimeIndex']) ? (string) $aValues['mimeIndex'] : '';
		$sContentTypeIn = isset($aValues['mimeType']) ? (string) $aValues['mimeType'] : '';
		$sFileNameIn = isset($aValues['fileName']) ? (string) $aValues['fileName'] : '';

		if (!empty($aValues['fileHash'])) {
			$rResource = $this->FilesProvider()->GetFile($oAccount, (string) $aValues['fileHash']);
			if (!\is_resource($rResource)) {
				return false;
			}
			// https://github.com/the-djmaze/snappymail/issues/144
			if ('.pdf' === \substr($sFileNameIn,-4)) {
				$sContentTypeOut = 'application/pdf'; // application/octet-stream
			} else {
				$sContentTypeOut = $sContentTypeIn
					?: \Tachyon\Util\File\MimeType::fromFilename($sFileNameIn)
					?: 'application/octet-stream';
			}
			$sFileNameOut = $this->clearFileName($sFileNameIn, $sContentTypeIn, $sMimeIndex);
			\header('Content-Type: '.$sContentTypeOut);
			\MailSo\Base\Http::setContentDisposition('attachment', ['filename' => $sFileNameOut]);
			\header('Accept-Ranges: none');
			\header('Content-Transfer-Encoding: binary');
			\MailSo\Base\Utils::FpassthruWithTimeLimitReset($rResource);
			return true;
		}

		$sFolder = isset($aValues['folder']) ? (string) $aValues['folder'] : '';
		$iUid = isset($aValues['uid']) ? (int) $aValues['uid'] : 0;
		if (empty($sFolder) || 1 > $iUid) {
			return false;
		}

		$this->initMailClientConnection();

		$self = $this;
		return $this->MailClient()->MessageMimeStream(
			function($rResource, $sContentType, $sFileName, $sMimeIndex = '') use (
				$self, $sRawKey, $sContentTypeIn, $sFileNameIn, $bDownload, $bThumbnail,
				$bIsRangeRequest, $sRangeStart, $sRangeEnd
			) {
				if (\is_resource($rResource)) {
					\MailSo\Base\Utils::ResetTimeLimit();

					$self->cacheByKey($sRawKey);

					$self->logWrite(\print_r([
						$sFileName,
						$sContentType,
						$sFileNameIn,
						$sContentTypeIn
					],true), \LOG_DEBUG, 'RAW');

					if ($sFileNameIn) {
						$sFileName = $sFileNameIn;
					}
					$sFileName = $self->clearFileName($sFileName, $sContentType, $sMimeIndex);

					if ('.pdf' === \substr($sFileName, -4)) {
						// https://github.com/the-djmaze/snappymail/issues/144
						$sContentType = 'application/pdf';
					} else {
						$sContentType = $sContentTypeIn
							?: $sContentType
//							?: \Tachyon\Util\File\MimeType::fromStream($rResource, $sFileName)
							?: \Tachyon\Util\File\MimeType::fromFilename($sFileName)
							?: 'application/octet-stream';
					}

					if (!$bDownload) {
						$bDetectImageOrientation = $self->Config()->Get('labs', 'image_exif_auto_rotate', false)
							// Mostly only JPEG has EXIF metadata
							&& 'image/jpeg' == $sContentType;
						try
						{
							if ($bThumbnail) {
								$oImage = static::loadImage($rResource, $bDetectImageOrientation, 48);
								\MailSo\Base\Http::setContentDisposition('inline', ['filename' => $sFileName.'_thumb60x60.png']);
								$oImage->show('png');
//								$oImage->show('webp'); // Little Britain: "Safari says NO"
								exit;
							} else if ($bDetectImageOrientation) {
								$oImage = static::loadImage($rResource, $bDetectImageOrientation);
								\MailSo\Base\Http::setContentDisposition('inline', ['filename' => $sFileName]);
								$oImage->show();
//								$oImage->show('webp'); // Little Britain: "Safari says NO"
								exit;
							}
						}
						catch (\Throwable $oException)
						{
							$self->Logger()->WriteExceptionShort($oException);
							\MailSo\Base\Http::StatusHeader(500);
							exit;
						}
					}

					// The type comes from the message: whoever sent the mail decides it.
					// Viewed inline under this origin, text/html (or XML, ...) becomes a
					// page of the sender's choosing, so only a few types are shown inline
					// as declared.
					$sContentType = static::servedContentType($sContentType, $bDownload);

					if (!\headers_sent()) {
						// SVG is shown inline because messages legitimately use it for
						// logos and signatures, and an <img> never runs script. Opened
						// directly it is a document, so add a second policy for it: a
						// browser enforces every Content-Security-Policy header it gets,
						// and sandbox with no allow- tokens gives an opaque origin with
						// scripting off whatever the policy above says. Only for SVG,
						// since sandboxing would stop a PDF reaching the built-in viewer.
						if ('image/svg+xml' === $sContentType && !$bDownload) {
							\header('Content-Security-Policy: sandbox', false);
						}
						\header('Content-Type: '.$sContentType);
						\MailSo\Base\Http::setContentDisposition($bDownload ? 'attachment' : 'inline', ['filename' => $sFileName]);
						\header('Accept-Ranges: bytes');
						\header('Content-Transfer-Encoding: binary');
					}

					$sLoadedData = null;
					if ($bIsRangeRequest || !$bDownload) {
						$sLoadedData = \stream_get_contents($rResource);
					}

					\MailSo\Base\Utils::ResetTimeLimit();

					if ($sLoadedData) {
						if ($bIsRangeRequest && (\strlen($sRangeStart) || \strlen($sRangeEnd))) {
							$iFullContentLength = \strlen($sLoadedData);

							\MailSo\Base\Http::StatusHeader(206);

							$iRangeStart = \max(0, \intval($sRangeStart));
							$iRangeEnd = \max(0, \intval($sRangeEnd));

							if ($iRangeEnd && $iRangeStart < $iRangeEnd) {
								$sLoadedData = \substr($sLoadedData, $iRangeStart, $iRangeEnd - $iRangeStart);
							} else if ($iRangeStart) {
								$sLoadedData = \substr($sLoadedData, $iRangeStart);
							}

							$iContentLength = \strlen($sLoadedData);

							if (0 < $iContentLength) {
								\header('Content-Length: '.$iContentLength);
								\header('Content-Range: bytes '.$sRangeStart.'-'.($iRangeEnd ?: $iFullContentLength - 1).'/'.$iFullContentLength);
							}
						} else {
							\header('Content-Length: '.\strlen($sLoadedData));
						}

						echo $sLoadedData;

						unset($sLoadedData);
					} else {
						\MailSo\Base\Utils::FpassthruWithTimeLimitReset($rResource);
					}
				}
			}, $sFolder, $iUid, $sMimeIndex
		);
	}

	/**
	 * The Content-Type an attachment is served with. Inline: one of the types
	 * inlineContentType() allows, else it becomes a download. A download is
	 * always application/octet-stream: the browser saves it either way, and
	 * with the sender's type (text/javascript, say) the URL would also work as
	 * a same-origin <script src>, which Content-Disposition does not stop.
	 */
	public static function servedContentType(string $sContentType, bool &$bDownload) : string
	{
		if (!$bDownload) {
			$sInlineType = static::inlineContentType($sContentType);
			if (null !== $sInlineType) {
				return $sInlineType;
			}
			$bDownload = true;
		}
		return 'application/octet-stream';
	}

	/**
	 * The Content-Type to view an attachment inline with, or null to make it a
	 * download. Images, PDF, audio, video and plain text are shown as declared;
	 * any other text/* is shown as text/plain, so an HTML attachment displays its
	 * source instead of rendering as a page on this origin. SVG is included: mail
	 * uses it for logos and signatures, and the caller adds a sandbox policy for
	 * it so that opening one directly cannot run script either.
	 */
	public static function inlineContentType(string $sContentType) : ?string
	{
		$sType = \strtolower(\trim(\explode(';', $sContentType, 2)[0]));
		if (\in_array($sType, ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/avif', 'image/bmp',
			'image/svg+xml', 'application/pdf', 'text/plain'], true)
		 || \preg_match('#^(audio|video)/[a-z0-9.+-]+$#', $sType)
		) {
			return $sType;
		}
		if (\str_starts_with($sType, 'text/')) {
			return 'text/plain';
		}
		return null;
	}

	private static function loadImage($resource, bool $bDetectImageOrientation = false, int $iThumbnailBoxSize = 0) : \Tachyon\Util\Image
	{
		if (\extension_loaded('gmagick'))      { $handler = 'gmagick'; }
		else if (\extension_loaded('imagick')) { $handler = 'imagick'; }
		else if (\extension_loaded('gd'))      { $handler = 'gd2'; }
		else { return null; }
		$handler = 'Tachyon\Util\\Image\\'.$handler.'::createFromStream';
		$oImage = $handler($resource);

		if (!$oImage->valid()) {
			throw new \Exception('Loading image failed');
		}

		// rotateImageByOrientation
		if ($bDetectImageOrientation) {
			switch ($oImage->getOrientation())
			{
				case 2: // flip horizontal
					$oImage->flopImage();
					break;

				case 3: // rotate 180
					$oImage->rotate(180);
					break;

				case 4: // flip vertical
					$oImage->flipImage();
					break;

				case 5: // vertical flip + 90 rotate
					$oImage->flipImage();
					$oImage->rotate(90);
					break;

				case 6: // rotate 90
					$oImage->rotate(90);
					break;

				case 7: // horizontal flip + 90 rotate
					$oImage->flopImage();
					$oImage->rotate(90);
					break;

				case 8: // rotate 270
					$oImage->rotate(270);
					break;
			}
		}

		if (0 < $iThumbnailBoxSize) {
			$oImage->cropThumbnailImage($iThumbnailBoxSize, $iThumbnailBoxSize);
		}

		$oImage->stripImage();

		return $oImage;
	}

}
