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

/**
 * ACP module information
 */
class main_info
{
	public function module()
	{
		return [
			'filename'	=> '\phpbbmodders\ageverification\acp\main_module',
			'title'		=> 'ACP_AGEVERIFICATION',
			'modes'		=> [
				'settings'	=> [
					'title'	=> 'ACP_AGEVERIFICATION_SETTINGS',
					'auth'	=> 'ext_phpbbmodders/ageverification && acl_a_board',
					'cat'	=> ['ACP_AGEVERIFICATION'],
				],
			],
		];
	}
}
