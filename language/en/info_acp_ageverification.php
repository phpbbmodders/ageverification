<?php
/**
 *
 * Age Verification extension for the phpBB Forum Software package
 *
 * @copyright (c) 2026, phpBB Modders, https://www.phpbbmodders.com/
 * @license GNU General Public License, version 2 (GPL-2.0)
 *
 */

/**
 * DO NOT CHANGE
 */
if (!defined('IN_PHPBB'))
{
	exit;
}

if (empty($lang) || !is_array($lang))
{
	$lang = [];
}

$lang = array_merge($lang, [
	'ACP_AGEVERIFICATION'			=> 'Age Verification',
	'ACP_AGEVERIFICATION_SETTINGS'	=> 'Settings',

	'LOG_AGEVERIFICATION_SETTINGS'	=> '<strong>Age Verification settings changed</strong>',
	'LOG_AGEVERIFICATION_RESET'		=> '<strong>Age Verification: everyone asked to confirm again</strong>',
]);
