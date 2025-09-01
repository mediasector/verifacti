<?php

defined('BASEPATH') or exit('No direct script access allowed');

/*
Module Name: Media Sector Verifacti Integration
Description: Verifacti API integration for Perfex CRM
Author: Asif Thebepotra, Íñigo Sastre (Media Sector)
Version: 0.3
*/
define('VERIFACTI_MODULE_NAME','verifacti');
define('VERIFACTI_MODULE_DIR',__DIR__);
if(!defined('VERIFACTI_MANUAL_SEND_ENABLED')){ define('VERIFACTI_MANUAL_SEND_ENABLED', false);} // for future toggle
register_language_files(VERIFACTI_MODULE_NAME,[VERIFACTI_MODULE_NAME]);
register_activation_hook(VERIFACTI_MODULE_NAME,'verifacti_activation_hook');
function verifacti_activation_hook(){ $CI=&get_instance(); require_once(__DIR__.'/install.php'); }
require_once VERIFACTI_MODULE_DIR.'/includes/VerifactiHooks.php';
(new VerifactiHooks())->init();