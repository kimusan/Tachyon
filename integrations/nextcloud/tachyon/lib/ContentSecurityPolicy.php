<?php

namespace OCA\Tachyon;

class ContentSecurityPolicy extends \OCP\AppFramework\Http\ContentSecurityPolicy {

	/**
	 * Only public methods are used to build this policy. Nextcloud's protected
	 * properties are not API and have changed under us more than once: NC34 no
	 * longer has inlineScriptAllowed or evalScriptAllowed at all, so setting them
	 * did nothing but look like it worked.
	 */
	function __construct() {
		$CSP = \Tachyon\Api::getCSP();

		foreach ($CSP->get('script-src') as $sSource) {
			// Scripts are allowed by nonce, see getTachyonNonce() below
			if ("'unsafe-inline'" !== $sSource) {
				$this->addAllowedScriptDomain($sSource);
			}
		}

		/**
		 * Knockout evaluates its binding strings, so it needs unsafe-eval.
		 * NC34 dropped allowEvalScript() and only keeps allowEvalWasm(), so the token
		 * has to go in as a script source. buildPolicy() implodes that array into
		 * script-src verbatim, and strict-dynamic does not suppress unsafe-eval.
		 */
		$this->addAllowedScriptDomain("'unsafe-eval'");

		// Nextcloud only sets 'strict-dynamic' when browserSupportsCspV3() ?
		\method_exists($this, 'useStrictDynamic')
			? $this->useStrictDynamic(true) // NC24+
			: $this->addAllowedScriptDomain("'strict-dynamic'");

		foreach ($CSP->get('img-src') as $sSource) {
			$this->addAllowedImageDomain($sSource);
		}

		foreach ($CSP->get('style-src') as $sSource) {
			// covered by allowInlineStyle() below
			if ("'unsafe-inline'" !== $sSource) {
				$this->addAllowedStyleDomain($sSource);
			}
		}
		$this->allowInlineStyle(true);

		foreach ($CSP->get('frame-src') as $sSource) {
			$this->addAllowedFrameDomain($sSource);
		}

		// No report-to. Tachyon's CSP only emits one from __toString() when reporting
		// is enabled and never stores it, and Nextcloud has its own reporting endpoint.
	}

	public function getTachyonNonce() {
		static $sNonce;
		if (!$sNonce) {
			/**
			 * Nextcloud exposes no public API for its CSP nonce.
			 * OCP\Security\IContentSecurityPolicyNonceManager has never existed in any
			 * release, and the private OC\Security\CSP class is not safe to depend on.
			 * Tachyon controls both the policy it returns here and the inline script tag
			 * in index_embed.php, so it mints its own nonce and declares it. Unconditional,
			 * because Nextcloud knows nothing about this one and will not add it for us.
			 */
			$sNonce = \Tachyon\Util\UUID::generate();
			$this->addAllowedScriptDomain("'nonce-{$sNonce}'");
		}
		return $sNonce;
	}

}
