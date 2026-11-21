<?php
/**
 *
 * Age Verification extension for the phpBB Forum Software package
 *
 * @copyright (c) 2026, phpBB Modders, https://www.phpbbmodders.com/
 * @license GNU General Public License, version 2 (GPL-2.0)
 *
 */

namespace phpbbmodders\ageverification\migrations\v10x;

/**
 * Adds the per-forum and per-member columns, the settings and the ACP page
 */
class install extends \phpbb\db\migration\migration
{
	/**
	 * {@inheritdoc}
	 */
	public function effectively_installed()
	{
		return $this->db_tools->sql_column_exists($this->table_prefix . 'users', 'user_ageverification');
	}

	/**
	 * {@inheritdoc}
	 */
	public static function depends_on()
	{
		return ['\phpbb\db\migration\data\v330\v330'];
	}

	/**
	 * forum_ageverification: 1 when the forum and the forums below it are gated
	 * user_ageverification: the minimum age the member last confirmed, 0 for none
	 *
	 * {@inheritdoc}
	 */
	public function update_schema()
	{
		return [
			'add_columns' => [
				$this->table_prefix . 'forums' => [
					'forum_ageverification' => ['BOOL', 0],
				],
				$this->table_prefix . 'users' => [
					'user_ageverification' => ['USINT', 0],
				],
			],
		];
	}

	/**
	 * {@inheritdoc}
	 */
	public function revert_schema()
	{
		return [
			'drop_columns' => [
				$this->table_prefix . 'forums' => [
					'forum_ageverification',
				],
				$this->table_prefix . 'users' => [
					'user_ageverification',
				],
			],
		];
	}

	/**
	 * {@inheritdoc}
	 */
	public function update_data()
	{
		return [
			// Gate the whole board (0 no, 1 yes); off so nothing changes until the admin chooses
			['config.add', ['ageverification_enabled', 0]],
			// Age visitors must confirm
			['config.add', ['ageverification_min_age', 18]],
			// Guests must log in or register instead of confirming (0 no, 1 yes)
			['config.add', ['ageverification_require_login', 0]],
			// How long the guest cookie remembers a confirmation: a number and
			// a unit (m minutes, h hours, d days)
			['config.add', ['ageverification_guest_time', 30]],
			['config.add', ['ageverification_guest_unit', 'd']],
			// Skip the gate for members whose birthday shows they are old enough (0 no, 1 yes)
			['config.add', ['ageverification_birthday_skip', 0]],
			// Where "I am under ..." leads; empty shows a message on the board
			['config.add', ['ageverification_exit_url', '']],
			// Raised by "Ask everyone again", so older guest cookies stop counting
			['config.add', ['ageverification_version', 1]],
			// Welcome text, stored with BBCode like posts
			['config_text.add', ['ageverification_text', '']],
			['config.add', ['ageverification_text_uid', '']],
			['config.add', ['ageverification_text_bitfield', '']],
			['config.add', ['ageverification_text_flags', OPTION_FLAG_BBCODE | OPTION_FLAG_SMILIES | OPTION_FLAG_LINKS]],

			['module.add', ['acp', 'ACP_CAT_DOT_MODS', 'ACP_AGEVERIFICATION']],
			['module.add', ['acp', 'ACP_AGEVERIFICATION', [
				'module_basename'	=> '\phpbbmodders\ageverification\acp\main_module',
				'modes'				=> ['settings'],
			]]],
		];
	}
}
