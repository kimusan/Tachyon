<?php

// SASL\Login registers the password with the log masker, so the application has
// to be bootstrapped. Include index.php as an API rather than a web request.
$_ENV['TACHYON_INCLUDE_AS_API'] = true;
require __DIR__ . '/../index.php';

$LOGIN = new \Tachyon\Util\SASL\Login;
$LOGIN->base64 = true;
var_dump($LOGIN->authenticate('john', 'doe', 'VXNlcm5hbWU6'));
var_dump($LOGIN->authenticate('john', 'doe', 'VXNlcm5hbWU6CG'));
// the password step is challenge(), not authenticate()
var_dump($LOGIN->challenge('UGFzc3dvcmQ6'));
