<?php
/**
 *
 * Age Verification extension for the phpBB Forum Software package
 *
 * @copyright (c) 2026, phpBB Modders, https://www.phpbbmodders.com/
 * @license GNU General Public License, version 2 (GPL-2.0)
 *
 */

namespace phpbbmodders\ageverification\tests;

use phpbb\config\config;
use phpbbmodders\ageverification\gate;

/**
 * gate with a fixed "today", so birthday tests do not depend on the date,
 * and a switch for running from the command line
 */
class gate_fixed_date extends gate
{
	/** @var string */
	public $today = '2026-11-21';

	/** @var bool */
	public $cli = false;

	protected function today()
	{
		return new \DateTime($this->today);
	}

	protected function is_cli()
	{
		return $this->cli;
	}
}

/**
 * Tests \phpbbmodders\ageverification\gate
 */
class gate_test extends \phpbb_test_case
{
	/** @var config */
	protected $config;

	/** @var \PHPUnit\Framework\MockObject\MockObject|\phpbb\db\driver\driver_interface */
	protected $db;

	/** @var \PHPUnit\Framework\MockObject\MockObject|\phpbb\request\request_interface */
	protected $request;

	/** @var \PHPUnit\Framework\MockObject\MockObject|\phpbb\user */
	protected $user;

	/** @var string Value of the visitor's "old enough" cookie: age and answer version */
	protected $cookie = '';

	/** @var int Value of the visitor's "too young" cookie: answer version */
	protected $refused = 0;

	/** @var string Value of the mode request variable */
	protected $mode = '';

	protected function setUp(): void
	{
		parent::setUp();

		$this->config = new config([
			'ageverification_enabled'		=> 1,
			'ageverification_min_age'		=> 18,
			'ageverification_require_login'	=> 0,
			'ageverification_version'		=> 1,
			'ageverification_guest_time'	=> 30,
			'ageverification_guest_unit'	=> 'd',
			'ageverification_birthday_skip'	=> 0,
			'cookie_name'			=> 'phpbb3_test',
		]);
		$this->db = $this->getMockBuilder('\phpbb\db\driver\driver_interface')->getMock();
		$this->db->method('sql_build_array')->willReturnCallback(function ($query, $data) {
			return implode(', ', array_map(function ($column, $value) {
				return $column . ' = ' . $value;
			}, array_keys($data), $data));
		});
		$this->request = $this->getMockBuilder('\phpbb\request\request_interface')->getMock();
		$this->request->method('variable')->willReturnCallback(function ($name, $default) {
			if ($name === 'phpbb3_test_ageverification')
			{
				return $this->cookie;
			}
			if ($name === 'phpbb3_test_ageverification_no')
			{
				return $this->refused;
			}
			return ($name === 'mode') ? $this->mode : $default;
		});
		$this->user = $this->getMockBuilder('\phpbb\user')->disableOriginalConstructor()->getMock();
		$this->user->data = ['user_id' => 1, 'is_registered' => false, 'is_bot' => false, 'user_ageverification' => 0, 'user_birthday' => ''];
		$this->user->page = ['page_name' => 'index.php', 'page' => 'index.php'];
	}

	/**
	 * @return gate_fixed_date
	 */
	protected function get_gate()
	{
		return new gate_fixed_date($this->config, $this->db, $this->request, $this->user, 'phpbb_forums', 'phpbb_topics', 'phpbb_users');
	}

	/**
	 * @return array
	 */
	public static function min_age_data()
	{
		return [
			[18, 18],
			[21, 21],
			[0, gate::MIN_AGE_LOWEST],
			[500, gate::MIN_AGE_HIGHEST],
		];
	}

	/**
	 * @dataProvider min_age_data
	 */
	public function test_min_age($setting, $expected)
	{
		$this->config['ageverification_min_age'] = $setting;
		$this->assertSame($expected, $this->get_gate()->min_age());
	}

	public function test_bot()
	{
		$this->assertFalse($this->get_gate()->is_bot());
		$this->user->data['is_bot'] = true;
		$this->assertTrue($this->get_gate()->is_bot());
	}

	public function test_guest_without_cookie_is_not_confirmed()
	{
		$this->assertFalse($this->get_gate()->is_confirmed());
	}

	public function test_guest_cookie()
	{
		$this->cookie = '18.1';
		$this->assertTrue($this->get_gate()->is_confirmed());

		// Raising the minimum age asks again
		$this->config['ageverification_min_age'] = 21;
		$this->assertFalse($this->get_gate()->is_confirmed());
	}

	/**
	 * @return array
	 */
	public static function guest_seconds_data()
	{
		return [
			[30, 'd', 30 * 86400],
			[12, 'h', 12 * 3600],
			[15, 'm', 15 * 60],
			[0, 'm', 60],
			[5, 'x', 5 * 86400],
			[999999, 'd', gate::GUEST_MAX_SECONDS],
		];
	}

	/**
	 * @dataProvider guest_seconds_data
	 */
	public function test_guest_seconds($time, $unit, $expected)
	{
		$this->config['ageverification_guest_time'] = $time;
		$this->config['ageverification_guest_unit'] = $unit;
		$this->assertSame($expected, $this->get_gate()->guest_seconds());
	}

	public function test_login_required_ignores_guest_cookie()
	{
		$this->config['ageverification_require_login'] = 1;
		$this->cookie = '18.1';

		$gate = $this->get_gate();
		$this->assertFalse($gate->is_confirmed());
		$this->assertFalse($gate->can_confirm());
	}

	public function test_login_required_members_still_confirm()
	{
		$this->config['ageverification_require_login'] = 1;
		$this->user->data['is_registered'] = true;

		$gate = $this->get_gate();
		$this->assertTrue($gate->can_confirm());
		$this->assertFalse($gate->is_confirmed());

		$this->user->data['user_ageverification'] = 18;
		$this->assertTrue($gate->is_confirmed());
	}

	public function test_guests_can_confirm_without_login_required()
	{
		$this->assertTrue($this->get_gate()->can_confirm());
	}

	public function test_register_exempt_only_when_login_required()
	{
		$this->user->page['page_name'] = 'ucp.php';
		$this->mode = 'register';
		$this->assertFalse($this->get_gate()->is_exempt_page());

		$this->config['ageverification_require_login'] = 1;
		$this->assertTrue($this->get_gate()->is_exempt_page());
	}

	public function test_old_cookie_version_no_longer_counts()
	{
		$this->cookie = '18.1';
		$this->config['ageverification_version'] = 2;
		$this->assertFalse($this->get_gate()->is_confirmed());

		$this->cookie = '18';
		$this->config['ageverification_version'] = 1;
		$this->assertFalse($this->get_gate()->is_confirmed());
	}

	public function test_refused_cookie()
	{
		$gate = $this->get_gate();
		$this->assertFalse($gate->is_refused());
		$this->assertTrue($gate->can_confirm());

		$this->refused = 1;
		$this->assertTrue($gate->is_refused());
		$this->assertFalse($gate->can_confirm());

		// "Ask everyone again" forgets a "no" too
		$this->config['ageverification_version'] = 2;
		$this->assertFalse($gate->is_refused());
	}

	public function test_birthday_too_young()
	{
		$this->user->data['is_registered'] = true;
		$this->user->data['user_birthday'] = ' 1- 1-2015';
		$this->user->data['user_ageverification'] = 18;

		// Ignored while the birthday setting is off
		$gate = $this->get_gate();
		$this->assertFalse($gate->birthday_too_young());
		$this->assertTrue($gate->is_confirmed());

		$this->config['ageverification_birthday_skip'] = 1;
		$this->assertTrue($gate->birthday_too_young());
		$this->assertTrue($gate->is_refused());
		$this->assertFalse($gate->can_confirm());
		$this->assertFalse($gate->is_confirmed());
	}

	public function test_birthday_without_year_is_not_too_young()
	{
		$this->user->data['is_registered'] = true;
		$this->user->data['user_birthday'] = '15- 3-   0';
		$this->config['ageverification_birthday_skip'] = 1;

		$gate = $this->get_gate();
		$this->assertFalse($gate->birthday_too_young());
		$this->assertTrue($gate->can_confirm());
	}

	public function test_gated_forum_ids()
	{
		$this->db->expects($this->once())->method('sql_query')->with($this->callback(function ($sql) {
			return strpos($sql, 'g.forum_ageverification = 1') !== false && strpos($sql, 'f.left_id >= g.left_id') !== false;
		}), gate::FORUM_CACHE_TTL);
		$this->db->method('sql_fetchrow')->willReturnOnConsecutiveCalls(['forum_id' => '3'], ['forum_id' => '7'], false);

		$gate = $this->get_gate();
		$this->assertSame([3, 7], $gate->gated_forum_ids());
		// Looked up once per page
		$this->assertTrue($gate->forum_gated(7));
		$this->assertFalse($gate->forum_gated(4));
		$this->assertFalse($gate->forum_gated(0));
	}

	public function test_hide_gated_forums()
	{
		$this->config['ageverification_enabled'] = 0;
		$this->assertTrue($this->get_gate()->hide_gated_forums());

		$this->cookie = '18.1';
		$this->assertFalse($this->get_gate()->hide_gated_forums());

		$this->cookie = '';
		$this->config['ageverification_enabled'] = 1;
		$this->assertFalse($this->get_gate()->hide_gated_forums(), 'the board-wide setting already covers every page');
	}

	public function test_member_stored_confirmation()
	{
		$this->user->data['is_registered'] = true;
		$this->user->data['user_ageverification'] = 18;
		$this->db->expects($this->never())->method('sql_query');

		$this->assertTrue($this->get_gate()->is_confirmed());
	}

	public function test_member_cookie_is_copied_to_account()
	{
		$this->user->data['is_registered'] = true;
		$this->user->data['user_id'] = 5;
		$this->cookie = '18.1';
		$this->db->expects($this->once())->method('sql_query')->with($this->callback(function ($sql) {
			return strpos($sql, 'SET user_ageverification = 18') !== false && strpos($sql, 'WHERE user_id = 5') !== false;
		}));

		$this->assertTrue($this->get_gate()->is_confirmed());
		$this->assertSame(18, $this->user->data['user_ageverification']);
	}

	/**
	 * @return array
	 */
	public static function birthday_data()
	{
		return [
			'old enough'			=> ['21-11-2008', true],
			'one day short'			=> ['22-11-2008', false],
			'earlier month'			=> [' 1- 1-1990', true],
			'no year'				=> ['15- 3-   0', false],
			'empty'					=> ['', false],
			'not a date'			=> ['31- 2-1990', false],
			'too young'				=> [' 1- 1-2015', false],
		];
	}

	/**
	 * @dataProvider birthday_data
	 */
	public function test_birthday_skip($birthday, $expected)
	{
		$this->user->data['is_registered'] = true;
		$this->user->data['user_birthday'] = $birthday;
		$this->config['ageverification_birthday_skip'] = 1;

		$this->assertSame($expected, $this->get_gate()->is_confirmed());
	}

	public function test_birthday_ignored_when_setting_off()
	{
		$this->user->data['is_registered'] = true;
		$this->user->data['user_birthday'] = ' 1- 1-1990';

		$this->assertFalse($this->get_gate()->is_confirmed());
	}

	public function test_birthday_ignored_for_guests()
	{
		$this->user->data['user_birthday'] = ' 1- 1-1990';
		$this->config['ageverification_birthday_skip'] = 1;

		$this->assertFalse($this->get_gate()->is_confirmed());
	}

	/**
	 * @return array
	 */
	public static function exempt_page_data()
	{
		return [
			['index.php', '', false],
			['viewtopic.php', '', false],
			['ucp.php', 'register', false],
			['ucp.php', 'login', true],
			['ucp.php', 'logout', true],
			['ucp.php', 'terms', true],
			['ucp.php', 'privacy', true],
			['app.php/ageverification', '', true],
			['app.php/ageverification/leave', '', true],
			['app.php/ageverificationway', '', false],
			['app.php/cron/cron.task.core.tidy_cache', '', true],
			['app.php/user/forgot_password', '', true],
			['app.php/help/faq', '', false],
			['/ageverification', '', true],
			// Path info on a page that does not handle routes must not skip the gate
			['viewtopic.php/ageverification', '', false],
		];
	}

	/**
	 * @dataProvider exempt_page_data
	 */
	public function test_exempt_page($page_name, $mode, $expected)
	{
		$this->user->page['page_name'] = $page_name;
		$this->mode = $mode;

		$this->assertSame($expected, $this->get_gate()->is_exempt_page());
	}

	public function test_command_line_is_exempt()
	{
		$gate = $this->get_gate();
		$this->assertFalse($gate->is_exempt_page());
		$gate->cli = true;
		$this->assertTrue($gate->is_exempt_page());
	}

	/**
	 * @return array
	 */
	public static function redirect_data()
	{
		return [
			['viewtopic.php?t=1', 'viewtopic.php?t=1'],
			['viewtopic.php?f=2&amp;t=1', 'viewtopic.php?f=2&t=1'],
			['app.php/help/faq', 'app.php/help/faq'],
			['', ''],
			['https://example.com/', ''],
			['//example.com/', ''],
			['/etc/passwd', ''],
			['javascript:alert(1)', ''],
			['../outside.php', ''],
			["index.php\r\nLocation: x", ''],
			['\\\\example.com', ''],
		];
	}

	/**
	 * @dataProvider redirect_data
	 */
	public function test_clean_redirect($page, $expected)
	{
		$this->assertSame($expected, $this->get_gate()->clean_redirect($page));
	}
}
