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
	'ACP_AGEVERIFICATION_SETTINGS_EXPLAIN'	=> 'Visitors confirm they are old enough on a page with two buttons before they can view the board, or the forums you choose. This is an honour system: it does not check anyone’s real age. Search engine bots are never asked.',

	'AGEVERIFICATION_GATE_SETTINGS'			=> 'Where visitors are asked',
	'AGEVERIFICATION_ENABLED'				=> 'Ask on the whole board',
	'AGEVERIFICATION_ENABLED_EXPLAIN'		=> 'Every page asks for confirmation first, except logging in, the privacy policy and terms of use, and the Administration Control Panel. To ask only in some forums, leave this off and set <strong>Ask for age verification in this forum</strong> under <strong>Forums → Manage forums</strong>.',
	'AGEVERIFICATION_MIN_AGE'				=> 'Minimum age',
	'AGEVERIFICATION_MIN_AGE_EXPLAIN'		=> 'Raising it asks everyone to confirm again.',
	'AGEVERIFICATION_MIN_AGE_INVALID'		=> 'The minimum age must be a whole number from %1$d to %2$d.',

	'AGEVERIFICATION_REMEMBER_SETTINGS'		=> 'Remembering the answer',
	'AGEVERIFICATION_REQUIRE_LOGIN'			=> 'Require login',
	'AGEVERIFICATION_REQUIRE_LOGIN_EXPLAIN'	=> 'Guests cannot confirm with the button. They are asked to log in or register instead, and confirm once as a member.',
	'AGEVERIFICATION_GUEST_TIME'			=> 'Ask guests again after',
	'AGEVERIFICATION_GUEST_TIME_EXPLAIN'	=> 'How long a cookie remembers that a guest confirmed. Members only confirm once: their answer is saved on their account. Not used when login is required.',
	'AGEVERIFICATION_GUEST_UNIT'			=> 'Unit for asking guests again',
	'AGEVERIFICATION_GUEST_TIME_INVALID'	=> 'The time before guests are asked again must be a whole number of at least 1, and no longer than ten years.',
	'AGEVERIFICATION_UNIT_M'				=> 'Minutes',
	'AGEVERIFICATION_UNIT_H'				=> 'Hours',
	'AGEVERIFICATION_UNIT_D'				=> 'Days',
	'AGEVERIFICATION_BIRTHDAY_SKIP'			=> 'Skip members with a birthday',
	'AGEVERIFICATION_BIRTHDAY_SKIP_EXPLAIN'	=> 'Logged-in members whose profile birthday includes a year showing they are old enough are never asked. Members whose birthday shows they are too young get the “too young” page. Members without a birthday year are asked once.',

	'AGEVERIFICATION_PAGE_SETTINGS'			=> 'Age confirmation page',
	'AGEVERIFICATION_TEXT'					=> 'Welcome text',
	'AGEVERIFICATION_TEXT_EXPLAIN'			=> 'Shown above the buttons. BBCode, smilies and links are allowed. Leave empty for the built-in text, which names the board and the minimum age.',
	'AGEVERIFICATION_TEXT_TOO_LONG'			=> 'The welcome text is too long: it can be up to %d characters.',
	'AGEVERIFICATION_EXIT_URL'				=> 'Address for visitors who are too young',
	'AGEVERIFICATION_EXIT_URL_EXPLAIN'		=> 'Where the “I am under …” button leads, starting with https:// or http://. Leave empty to show a short message on the board instead.',
	'AGEVERIFICATION_EXIT_URL_INVALID'		=> 'The address for visitors who are too young must be a full web address starting with https:// or http://, up to 255 characters.',

	'AGEVERIFICATION_RESET'					=> 'Ask everyone again',
	'AGEVERIFICATION_RESET_EXPLAIN'			=> 'Forgets every saved answer, from members and guests, so everyone is asked the next time they visit. Members whose birthday is used are not affected.',
	'AGEVERIFICATION_RESET_CONFIRM'			=> 'Are you sure you want to ask everyone to confirm their age again?',
	'AGEVERIFICATION_RESET_DONE'			=> 'Everyone will be asked to confirm their age again.',

	'AGEVERIFICATION_FORUM'					=> 'Ask for age verification in this forum',
	'AGEVERIFICATION_FORUM_EXPLAIN'			=> 'Visitors confirm their age before viewing this forum, the forums below it, and their topics. Until they do, these forums are left out of search results and feeds. Topic titles can still appear on the board index and in Who is online.',
	'AGEVERIFICATION_FORUM_BOARD_ON'		=> 'Age verification is on for the whole board, so this setting only takes effect if that is turned off.',
]);
