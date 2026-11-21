<?php
/**
 *
 * Age Verification extension for the phpBB Forum Software package
 *
 * @copyright (c) 2026, phpBB Modders, https://www.phpbbmodders.com/
 * @license GNU General Public License, version 2 (GPL-2.0)
 *
 */

namespace phpbbmodders\ageverification\event;

use phpbb\controller\helper;
use phpbb\db\driver\driver_interface;
use phpbb\event\data;
use phpbb\language\language;
use phpbb\request\request_interface;
use phpbb\user;
use phpbbmodders\ageverification\gate;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Sends visitors who have not passed the gate to it, and adds the per-forum
 * setting to ACP → Manage forums
 */
class listener implements EventSubscriberInterface
{
	/** @var driver_interface */
	protected $db;

	/** @var helper */
	protected $helper;

	/** @var language */
	protected $language;

	/** @var request_interface */
	protected $request;

	/** @var user */
	protected $user;

	/** @var gate */
	protected $gate;

	/**
	 * @param driver_interface		$db
	 * @param helper				$helper
	 * @param language				$language
	 * @param request_interface		$request
	 * @param user					$user
	 * @param gate					$gate
	 */
	public function __construct(driver_interface $db, helper $helper, language $language, request_interface $request, user $user, gate $gate)
	{
		$this->db = $db;
		$this->helper = $helper;
		$this->language = $language;
		$this->request = $request;
		$this->user = $user;
		$this->gate = $gate;
	}

	/**
	 * {@inheritdoc}
	 */
	public static function getSubscribedEvents()
	{
		return [
			'core.user_setup_after'					=> 'check_board',
			'core.viewforum_modify_page_title'		=> 'check_forum',
			'core.viewtopic_before_f_read_check'	=> 'check_forum',
			'core.modify_posting_auth'				=> 'check_forum',

			'core.search_modify_param_before'				=> 'hide_from_search',
			'core.feed_base_modify_item_sql'				=> 'hide_from_feeds',
			'core.download_file_send_to_browser_before'		=> 'check_attachment',

			'core.acp_manage_forums_request_data'		=> 'acp_forum_request',
			'core.acp_manage_forums_initialise_data'	=> 'acp_forum_initialise',
			'core.acp_manage_forums_display_form'		=> 'acp_forum_display',
		];
	}

	/**
	 * Board-wide gate: runs on every page once the visitor's session is set up
	 *
	 * @return void
	 */
	public function check_board()
	{
		if (!$this->gate->board_gated() || $this->gate->is_exempt_page() || $this->gate->is_bot() || $this->gate->is_confirmed())
		{
			return;
		}

		$this->send_to_gate();
	}

	/**
	 * Per-forum gate: forum pages, topics and posting in a gated forum or a
	 * forum below one
	 *
	 * @param data $event
	 * @return void
	 */
	public function check_forum(data $event)
	{
		// The board-wide gate already covers every forum
		if ($this->gate->board_gated() || $this->gate->is_bot())
		{
			return;
		}

		$forum_id = (int) $event['forum_id'];
		if ($forum_id <= 0 || $this->gate->is_confirmed() || !$this->gate->forum_gated($forum_id))
		{
			return;
		}

		$this->send_to_gate();
	}

	/**
	 * Leave forums that ask out of search results, including the active
	 * topics and unanswered lists, for visitors who have not confirmed
	 *
	 * @param data $event
	 * @return void
	 */
	public function hide_from_search(data $event)
	{
		if (!$this->gate->hide_gated_forums() || !($forum_ids = $this->gate->gated_forum_ids()))
		{
			return;
		}

		$event['ex_fid_ary'] = array_values(array_unique(array_merge($event['ex_fid_ary'], $forum_ids)));
	}

	/**
	 * Leave forums that ask out of feeds for visitors who have not confirmed
	 *
	 * @param data $event
	 * @return void
	 */
	public function hide_from_feeds(data $event)
	{
		if (!$this->gate->hide_gated_forums() || !($forum_ids = $this->gate->gated_forum_ids()))
		{
			return;
		}

		$sql_ary = $event['sql_ary'];
		$from = (array) ($sql_ary['FROM'] ?? []);

		// Every feed reads posts, topics or forums; filter on the first of
		// those its query uses
		$alias = $from[POSTS_TABLE] ?? $from[TOPICS_TABLE] ?? $from[FORUMS_TABLE] ?? '';
		if ($alias === '')
		{
			return;
		}

		$where = trim((string) ($sql_ary['WHERE'] ?? ''));
		$filter = $this->db->sql_in_set($alias . '.forum_id', $forum_ids, true);
		$sql_ary['WHERE'] = ($where !== '') ? '(' . $where . ') AND ' . $filter : $filter;
		$event['sql_ary'] = $sql_ary;
	}

	/**
	 * Attachments in forums that ask, opened directly, go through the gate
	 * too
	 *
	 * @param data $event
	 * @return void
	 */
	public function check_attachment(data $event)
	{
		$attachment = $event['attachment'];
		if (!empty($attachment['in_message']) || empty($attachment['topic_id']) || !$this->gate->hide_gated_forums())
		{
			return;
		}

		if ($this->gate->forum_gated($this->gate->topic_forum_id((int) $attachment['topic_id'])))
		{
			$this->send_to_gate();
		}
	}

	/**
	 * Save the forum's setting from ACP → Manage forums
	 *
	 * @param data $event
	 * @return void
	 */
	public function acp_forum_request(data $event)
	{
		$forum_data = $event['forum_data'];
		$forum_data['forum_ageverification'] = $this->request->variable('forum_ageverification', 0) ? 1 : 0;
		$event['forum_data'] = $forum_data;
	}

	/**
	 * New forums start without the gate
	 *
	 * @param data $event
	 * @return void
	 */
	public function acp_forum_initialise(data $event)
	{
		if ($event['action'] === 'add')
		{
			$forum_data = $event['forum_data'];
			$forum_data['forum_ageverification'] = 0;
			$event['forum_data'] = $forum_data;
		}
	}

	/**
	 * Show the forum's setting in ACP → Manage forums
	 *
	 * @param data $event
	 * @return void
	 */
	public function acp_forum_display(data $event)
	{
		$this->language->add_lang('acp_ageverification', 'phpbbmodders/ageverification');

		$template_data = $event['template_data'];
		$template_data['S_AGEVERIFICATION_FORUM'] = !empty($event['forum_data']['forum_ageverification']);
		$template_data['S_AGEVERIFICATION_BOARD'] = $this->gate->board_gated();
		$event['template_data'] = $template_data;
	}

	/**
	 * Redirect to the gate, which sends the visitor back here once they pass
	 *
	 * @return void
	 */
	protected function send_to_gate()
	{
		$page = $this->gate->clean_redirect($this->user->page['page'] ?? '');
		$params = ($page !== '') ? ['redirect' => $page] : [];

		redirect($this->helper->route('phpbbmodders_ageverification_gate', $params, false, false, UrlGeneratorInterface::ABSOLUTE_URL));
	}
}
