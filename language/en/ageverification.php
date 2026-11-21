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
	'AGEVERIFICATION_TITLE'			=> 'Age confirmation',
	// %1$s board name, %2$d minimum age
	'AGEVERIFICATION_DEFAULT_TEXT'	=> '%1$s is for adults only. Please confirm that you are %2$d or older to continue.',
	'AGEVERIFICATION_ENTER'			=> 'I am %d or older',
	'AGEVERIFICATION_LEAVE'			=> 'I am under %d',
	'AGEVERIFICATION_LEAVE_TEXT'	=> 'Sorry, you must be %d or older to view this board.',
	'AGEVERIFICATION_BACK'			=> 'Back to the age confirmation',
	'AGEVERIFICATION_COOKIES_NEEDED'	=> 'Your browser did not keep your answer. Please allow cookies for this board, or log in, and try again.',
	// %d minimum age
	'AGEVERIFICATION_LOGIN_TEXT'	=> 'Please log in to confirm that you are %d or older. You only need to confirm once.',
	'AGEVERIFICATION_LOGIN_REGISTER_TEXT'	=> 'Please log in, or register an account, to confirm that you are %d or older. You only need to confirm once.',
]);
