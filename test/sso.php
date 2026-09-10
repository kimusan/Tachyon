<?php

// Enable the Tachyon API and include the index file
$_ENV['TACHYON_INCLUDE_AS_API'] = true;
require __DIR__ . '/../index.php';

// The credentials of the account to log in. Supply these from your own
// application; they are not read from the request.
$sEmail = '';
$sPassword = '';

/**
 * Get SSO hash
 */
$aAdditionalOptions = array(
	// One of /tachyon/v/0.0.0/app/localization/*
//	'language' => 'en'
);
$bUseTimeout = true; // 10 seconds
$ssoHash = \Tachyon\Api::CreateUserSsoHash($sEmail, $sPassword, $aAdditionalOptions, $bUseTimeout);

// redirect to webmail sso url
\header('Location: https://yourdomain.com/?sso&hash='.$ssoHash);

// Destroy the SSO hash
//\Tachyon\Api::ClearUserSsoHash($ssoHash);
