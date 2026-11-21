<?php
/**
 *
 * Age Verification extension for the phpBB Forum Software package
 *
 * @copyright (c) 2026, phpBB Modders, https://www.phpbbmodders.com/
 * @license GNU General Public License, version 2 (GPL-2.0)
 *
 */

namespace phpbbmodders\ageverification\acp;

use phpbbmodders\ageverification\gate;

/**
 * ACP settings page: where the gate appears, the minimum age, how long the
 * answer is remembered, and the gate page's text and exit address
 */
class main_module
{
	/** Longest welcome text accepted, in characters */
	const MAX_TEXT_LENGTH = 10000;

	/** Longest exit address accepted; config values hold 255 characters */
	const MAX_URL_LENGTH = 255;

	public $u_action;
	public $tpl_name;
	public $page_title;

	/**
	 * @param string $id	Module ID
	 * @param string $mode	Module mode
	 */
	public function main($id, $mode)
	{
		global $config, $db, $phpbb_container, $language, $request, $template, $user, $phpbb_log;

		/** @var \phpbb\config\db_text $config_text */
		$config_text = $phpbb_container->get('config_text');

		$language->add_lang('acp_ageverification', 'phpbbmodders/ageverification');
		$this->tpl_name = 'acp_ageverification_settings';
		$this->page_title = 'ACP_AGEVERIFICATION_SETTINGS';

		add_form_key('phpbbmodders_ageverification_acp');

		// Ask everyone again: forget every member's saved answer, and make
		// every guest cookie out of date by raising the answer version
		if ($request->is_set_post('ageverification_reset') || $request->variable('action', '') === 'reset')
		{
			if (confirm_box(true))
			{
				$sql = 'UPDATE ' . USERS_TABLE . '
					SET ' . $db->sql_build_array('UPDATE', ['user_ageverification' => 0]) . '
					WHERE user_ageverification <> 0';
				$db->sql_query($sql);
				$config->increment('ageverification_version', 1);

				$phpbb_log->add('admin', $user->data['user_id'], $user->ip, 'LOG_AGEVERIFICATION_RESET');
				trigger_error($language->lang('AGEVERIFICATION_RESET_DONE') . adm_back_link($this->u_action));
			}

			confirm_box(false, $language->lang('AGEVERIFICATION_RESET_CONFIRM'), build_hidden_fields(['action' => 'reset']));
			redirect($this->u_action);
		}

		if ($request->is_set_post('submit'))
		{
			if (!check_form_key('phpbbmodders_ageverification_acp'))
			{
				trigger_error($language->lang('FORM_INVALID') . adm_back_link($this->u_action), E_USER_WARNING);
			}

			$min_age = $request->variable('ageverification_min_age', 0);
			if ($min_age < gate::MIN_AGE_LOWEST || $min_age > gate::MIN_AGE_HIGHEST)
			{
				trigger_error($language->lang('AGEVERIFICATION_MIN_AGE_INVALID', gate::MIN_AGE_LOWEST, gate::MIN_AGE_HIGHEST) . adm_back_link($this->u_action), E_USER_WARNING);
			}

			$guest_time = $request->variable('ageverification_guest_time', 0);
			$guest_unit = $request->variable('ageverification_guest_unit', 'd');
			if (!isset(gate::GUEST_UNITS[$guest_unit]) || $guest_time < 1 || $guest_time * gate::GUEST_UNITS[$guest_unit] > gate::GUEST_MAX_SECONDS)
			{
				trigger_error($language->lang('AGEVERIFICATION_GUEST_TIME_INVALID') . adm_back_link($this->u_action), E_USER_WARNING);
			}

			$exit_url = trim($request->variable('ageverification_exit_url', '', true));
			// Checked as typed, and as stored: the request escapes & and quotes, which makes it longer
			if ($exit_url !== '' && (strlen($exit_url) > self::MAX_URL_LENGTH || !$this->valid_url(htmlspecialchars_decode($exit_url, ENT_COMPAT))))
			{
				trigger_error($language->lang('AGEVERIFICATION_EXIT_URL_INVALID') . adm_back_link($this->u_action), E_USER_WARNING);
			}

			$text = $request->variable('ageverification_text', '', true);
			if (utf8_strlen($text) > self::MAX_TEXT_LENGTH)
			{
				trigger_error($language->lang('AGEVERIFICATION_TEXT_TOO_LONG', self::MAX_TEXT_LENGTH) . adm_back_link($this->u_action), E_USER_WARNING);
			}

			$uid = $bitfield = '';
			$flags = 0;
			generate_text_for_storage($text, $uid, $bitfield, $flags, true, true, true);

			$config->set('ageverification_enabled', (int) $request->variable('ageverification_enabled', false));
			$config->set('ageverification_min_age', $min_age);
			$config->set('ageverification_require_login', (int) $request->variable('ageverification_require_login', false));
			$config->set('ageverification_guest_time', $guest_time);
			$config->set('ageverification_guest_unit', $guest_unit);
			$config->set('ageverification_birthday_skip', (int) $request->variable('ageverification_birthday_skip', false));
			$config->set('ageverification_exit_url', $exit_url);
			$config->set('ageverification_text_uid', $uid);
			$config->set('ageverification_text_bitfield', $bitfield);
			$config->set('ageverification_text_flags', $flags);
			$config_text->set('ageverification_text', $text);

			$phpbb_log->add('admin', $user->data['user_id'], $user->ip, 'LOG_AGEVERIFICATION_SETTINGS');
			trigger_error($language->lang('CONFIG_UPDATED') . adm_back_link($this->u_action));
		}

		foreach (array_keys(gate::GUEST_UNITS) as $unit)
		{
			$template->assign_block_vars('ageverification_guest_units', [
				'VALUE'		=> $unit,
				'LABEL'		=> $language->lang('AGEVERIFICATION_UNIT_' . strtoupper($unit)),
				'S_CURRENT'	=> $unit === $config['ageverification_guest_unit'],
			]);
		}

		$text = generate_text_for_edit((string) $config_text->get('ageverification_text'), $config['ageverification_text_uid'], (int) $config['ageverification_text_flags']);

		$template->assign_vars([
			'U_ACTION'					=> $this->u_action,
			'S_AGEVERIFICATION_ENABLED'			=> !empty($config['ageverification_enabled']),
			'S_AGEVERIFICATION_BIRTHDAY_SKIP'	=> !empty($config['ageverification_birthday_skip']),
			'AGEVERIFICATION_MIN_AGE'			=> (int) $config['ageverification_min_age'],
			'AGEVERIFICATION_MIN_AGE_LOWEST'	=> gate::MIN_AGE_LOWEST,
			'AGEVERIFICATION_MIN_AGE_HIGHEST'	=> gate::MIN_AGE_HIGHEST,
			'S_AGEVERIFICATION_REQUIRE_LOGIN'	=> !empty($config['ageverification_require_login']),
			'AGEVERIFICATION_GUEST_TIME'		=> (int) $config['ageverification_guest_time'],
			'AGEVERIFICATION_EXIT_URL'			=> $config['ageverification_exit_url'],
			'AGEVERIFICATION_URL_MAX_LENGTH'	=> self::MAX_URL_LENGTH,
			'AGEVERIFICATION_TEXT'				=> $text['text'],
		]);
	}

	/**
	 * Whether an exit address is a full http or https address that fits in
	 * a config value
	 *
	 * @param string $url
	 * @return bool
	 */
	protected function valid_url($url)
	{
		return strlen($url) <= self::MAX_URL_LENGTH
			&& preg_match('#^https?://[^\s/?\#]+#i', $url)
			&& filter_var($url, FILTER_VALIDATE_URL) !== false;
	}
}
