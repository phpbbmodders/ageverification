<?php
/**
 *
 * Age Verification extension for the phpBB Forum Software package
 *
 * @copyright (c) 2026, phpBB Modders, https://www.phpbbmodders.com/
 * @license GNU General Public License, version 2 (GPL-2.0)
 *
 */

namespace phpbbmodders\ageverification\tests\event;

use phpbb\event\data;
use phpbbmodders\ageverification\event\listener;
use phpbbmodders\ageverification\gate;

/**
 * Tests \phpbbmodders\ageverification\event\listener
 */
class listener_test extends \phpbb_test_case
{
	/** @var \PHPUnit\Framework\MockObject\MockObject|gate */
	protected $gate;

	/** @var \PHPUnit\Framework\MockObject\MockObject|\phpbb\request\request_interface */
	protected $request;

	/** @var \PHPUnit\Framework\MockObject\MockObject|\phpbb\db\driver\driver_interface */
	protected $db;

	protected function setUp(): void
	{
		parent::setUp();

		$this->gate = $this->getMockBuilder(gate::class)->disableOriginalConstructor()->getMock();
		$this->request = $this->getMockBuilder('\phpbb\request\request_interface')->getMock();
		$this->db = $this->getMockBuilder('\phpbb\db\driver\driver_interface')->getMock();
		$this->db->method('sql_in_set')->willReturnCallback(function ($field, $ids, $negate) {
			return $field . ($negate ? ' NOT IN (' : ' IN (') . implode(', ', $ids) . ')';
		});
	}

	/**
	 * Listener whose redirect is recorded instead of sent
	 *
	 * @return \PHPUnit\Framework\MockObject\MockObject|listener
	 */
	protected function get_listener()
	{
		$helper = $this->getMockBuilder('\phpbb\controller\helper')->disableOriginalConstructor()->getMock();
		$language = $this->getMockBuilder('\phpbb\language\language')->disableOriginalConstructor()->getMock();
		$user = $this->getMockBuilder('\phpbb\user')->disableOriginalConstructor()->getMock();

		return $this->getMockBuilder(listener::class)
			->setConstructorArgs([$this->db, $helper, $language, $this->request, $user, $this->gate])
			->onlyMethods(['send_to_gate'])
			->getMock();
	}

	public function test_construct()
	{
		$this->assertInstanceOf('\Symfony\Component\EventDispatcher\EventSubscriberInterface', $this->get_listener());
	}

	public function test_subscribed_events()
	{
		$events = array_keys(listener::getSubscribedEvents());

		foreach (['core.search_modify_param_before', 'core.feed_base_modify_item_sql', 'core.download_file_send_to_browser_before', 'core.user_setup_after', 'core.viewforum_modify_page_title', 'core.viewtopic_before_f_read_check', 'core.modify_posting_auth', 'core.acp_manage_forums_request_data', 'core.acp_manage_forums_initialise_data', 'core.acp_manage_forums_display_form'] as $event)
		{
			$this->assertContains($event, $events);
		}
	}

	/**
	 * @return array
	 */
	public static function check_board_data()
	{
		return [
			'gate off'		=> [false, false, false, false, false],
			'exempt page'	=> [true, true, false, false, false],
			'bot'			=> [true, false, true, false, false],
			'confirmed'		=> [true, false, false, true, false],
			'sent to gate'	=> [true, false, false, false, true],
		];
	}

	/**
	 * @dataProvider check_board_data
	 */
	public function test_check_board($board_gated, $exempt, $bot, $confirmed, $sent)
	{
		$this->gate->method('board_gated')->willReturn($board_gated);
		$this->gate->method('is_exempt_page')->willReturn($exempt);
		$this->gate->method('is_bot')->willReturn($bot);
		$this->gate->method('is_confirmed')->willReturn($confirmed);

		$listener = $this->get_listener();
		$listener->expects($sent ? $this->once() : $this->never())->method('send_to_gate');
		$listener->check_board();
	}

	/**
	 * @return array
	 */
	public static function check_forum_data()
	{
		return [
			'board gate covers it'	=> [true, false, 5, false, true, false],
			'bot'					=> [false, true, 5, false, true, false],
			'no forum'				=> [false, false, 0, false, true, false],
			'confirmed'				=> [false, false, 5, true, true, false],
			'forum not gated'		=> [false, false, 5, false, false, false],
			'sent to gate'			=> [false, false, 5, false, true, true],
		];
	}

	/**
	 * @dataProvider check_forum_data
	 */
	public function test_check_forum($board_gated, $bot, $forum_id, $confirmed, $forum_gated, $sent)
	{
		$this->gate->method('board_gated')->willReturn($board_gated);
		$this->gate->method('is_bot')->willReturn($bot);
		$this->gate->method('is_confirmed')->willReturn($confirmed);
		$this->gate->method('forum_gated')->willReturn($forum_gated);

		$listener = $this->get_listener();
		$listener->expects($sent ? $this->once() : $this->never())->method('send_to_gate');
		$listener->check_forum(new data(['forum_id' => $forum_id]));
	}

	public function test_hide_from_search()
	{
		$this->gate->method('hide_gated_forums')->willReturn(true);
		$this->gate->method('gated_forum_ids')->willReturn([3, 7]);
		$event = new data(['ex_fid_ary' => [2, 3]]);

		$this->get_listener()->hide_from_search($event);

		$this->assertSame([2, 3, 7], $event['ex_fid_ary']);
	}

	public function test_search_untouched_for_confirmed_visitors()
	{
		$this->gate->method('hide_gated_forums')->willReturn(false);
		$this->gate->expects($this->never())->method('gated_forum_ids');
		$event = new data(['ex_fid_ary' => [2]]);

		$this->get_listener()->hide_from_search($event);

		$this->assertSame([2], $event['ex_fid_ary']);
	}

	/**
	 * @return array
	 */
	public static function feed_data()
	{
		return [
			'posts'		=> [[POSTS_TABLE => 'p', TOPICS_TABLE => 't'], 'p.post_visibility = 1', '(p.post_visibility = 1) AND p.forum_id NOT IN (3, 7)'],
			'topics'	=> [[TOPICS_TABLE => 't', FORUMS_TABLE => 'f'], 't.topic_id > 0', '(t.topic_id > 0) AND t.forum_id NOT IN (3, 7)'],
			'forums'	=> [[FORUMS_TABLE => 'f'], '', 'f.forum_id NOT IN (3, 7)'],
		];
	}

	/**
	 * @dataProvider feed_data
	 */
	public function test_hide_from_feeds($from, $where, $expected)
	{
		$this->gate->method('hide_gated_forums')->willReturn(true);
		$this->gate->method('gated_forum_ids')->willReturn([3, 7]);
		$event = new data(['sql_ary' => ['SELECT' => '*', 'FROM' => $from, 'WHERE' => $where]]);

		$this->get_listener()->hide_from_feeds($event);

		$this->assertSame($expected, $event['sql_ary']['WHERE']);
	}

	/**
	 * @return array
	 */
	public static function attachment_data()
	{
		return [
			'gated forum'		=> [['in_message' => 0, 'topic_id' => 9], true, true, true],
			'other forum'		=> [['in_message' => 0, 'topic_id' => 9], true, false, false],
			'confirmed'			=> [['in_message' => 0, 'topic_id' => 9], false, true, false],
			'private message'	=> [['in_message' => 1, 'topic_id' => 0], true, true, false],
		];
	}

	/**
	 * @dataProvider attachment_data
	 */
	public function test_check_attachment($attachment, $hide, $forum_gated, $sent)
	{
		$this->gate->method('hide_gated_forums')->willReturn($hide);
		$this->gate->method('topic_forum_id')->willReturn(5);
		$this->gate->method('forum_gated')->willReturn($forum_gated);

		$listener = $this->get_listener();
		$listener->expects($sent ? $this->once() : $this->never())->method('send_to_gate');
		$listener->check_attachment(new data(['attachment' => $attachment]));
	}

	public function test_acp_forum_request()
	{
		$this->request->method('variable')->with('forum_ageverification', 0)->willReturn(1);
		$event = new data(['forum_data' => ['forum_name' => 'Adults']]);

		$this->get_listener()->acp_forum_request($event);

		$this->assertSame(1, $event['forum_data']['forum_ageverification']);
	}

	public function test_acp_forum_initialise()
	{
		$event = new data(['action' => 'add', 'forum_data' => []]);
		$this->get_listener()->acp_forum_initialise($event);
		$this->assertSame(0, $event['forum_data']['forum_ageverification']);

		$event = new data(['action' => 'edit', 'forum_data' => ['forum_ageverification' => 1]]);
		$this->get_listener()->acp_forum_initialise($event);
		$this->assertSame(1, $event['forum_data']['forum_ageverification']);
	}

	public function test_acp_forum_display()
	{
		$this->gate->method('board_gated')->willReturn(true);
		$event = new data(['forum_data' => ['forum_ageverification' => 1], 'template_data' => []]);

		$this->get_listener()->acp_forum_display($event);

		$this->assertTrue($event['template_data']['S_AGEVERIFICATION_FORUM']);
		$this->assertTrue($event['template_data']['S_AGEVERIFICATION_BOARD']);
	}
}
