<?php
/**
 *
 * Age Verification extension for the phpBB Forum Software package
 *
 * @copyright (c) 2026, phpBB Modders, https://www.phpbbmodders.com/
 * @license GNU General Public License, version 2 (GPL-2.0)
 *
 */

namespace phpbbmodders\ageverification;

use phpbb\config\config;
use phpbb\db\driver\driver_interface;
use phpbb\request\request_interface;
use phpbb\user;

/**
 * Decides who has to pass the age gate, and remembers who has passed it.
 *
 * A visitor has passed when their cookie, or for members their account,
 * holds an age at least as high as the board's current minimum age. Raising
 * the minimum age therefore asks everyone again. Cookies also carry the
 * board's answer version, which the ACP raises to ask everyone again.
 */
class gate
{
	/** Cookie names, added to the board's cookie name prefix: "yes, old enough" and "no, too young" */
	const COOKIE = 'ageverification';
	const COOKIE_REFUSED = 'ageverification_no';

	/** Units the guest cookie lifetime can be set in, and their length in seconds */
	const GUEST_UNITS = ['m' => 60, 'h' => 3600, 'd' => 86400];

	/** Longest guest cookie lifetime accepted, in seconds (ten years) */
	const GUEST_MAX_SECONDS = 315360000;

	/** Longest page address remembered for the return trip, in characters */
	const MAX_REDIRECT_LENGTH = 2000;

	/** Seconds the list of forums that ask stays cached; Manage forums clears it on every change */
	const FORUM_CACHE_TTL = 3600;

	/** Lowest and highest minimum age the ACP accepts */
	const MIN_AGE_LOWEST = 1;
	const MIN_AGE_HIGHEST = 99;

	/** Routes that never show the gate: the gate itself, cron, password resets and the like, and the 4.0 web app manifest */
	const EXEMPT_ROUTES = ['/ageverification', '/cron', '/user', '/manifest'];

	/** ucp.php modes that never show the gate, so nobody is locked out of logging in or reading the board's terms */
	const EXEMPT_UCP_MODES = ['activate', 'confirm', 'delete_cookies', 'login', 'login_link', 'logout', 'privacy', 'resend_act', 'sendpassword', 'terms'];

	/** @var config */
	protected $config;

	/** @var driver_interface */
	protected $db;

	/** @var request_interface */
	protected $request;

	/** @var user */
	protected $user;

	/** @var string */
	protected $forums_table;

	/** @var string */
	protected $topics_table;

	/** @var string */
	protected $users_table;

	/** @var int[]|null Forums that ask, with the forums below them, once looked up */
	protected $gated_forum_ids;

	/**
	 * @param config				$config
	 * @param driver_interface		$db
	 * @param request_interface		$request
	 * @param user					$user
	 * @param string				$forums_table
	 * @param string				$topics_table
	 * @param string				$users_table
	 */
	public function __construct(config $config, driver_interface $db, request_interface $request, user $user, $forums_table, $topics_table, $users_table)
	{
		$this->config = $config;
		$this->db = $db;
		$this->request = $request;
		$this->user = $user;
		$this->forums_table = $forums_table;
		$this->topics_table = $topics_table;
		$this->users_table = $users_table;
	}

	/**
	 * The age visitors must confirm
	 *
	 * @return int
	 */
	public function min_age()
	{
		$age = (int) $this->config['ageverification_min_age'];
		return min(max($age, self::MIN_AGE_LOWEST), self::MIN_AGE_HIGHEST);
	}

	/**
	 * Whether the whole board is behind the gate
	 *
	 * @return bool
	 */
	public function board_gated()
	{
		return !empty($this->config['ageverification_enabled']);
	}

	/**
	 * The board's answer version; the ACP raises it to ask everyone again
	 *
	 * @return int
	 */
	public function version()
	{
		return max(1, (int) $this->config['ageverification_version']);
	}

	/**
	 * Whether guests have to log in or register instead of confirming
	 *
	 * @return bool
	 */
	public function login_required()
	{
		return !empty($this->config['ageverification_require_login']);
	}

	/**
	 * How long the guest cookie remembers a confirmation, in seconds
	 *
	 * @return int
	 */
	public function guest_seconds()
	{
		$unit = (string) $this->config['ageverification_guest_unit'];
		$seconds = max(1, (int) $this->config['ageverification_guest_time']) * (self::GUEST_UNITS[$unit] ?? self::GUEST_UNITS['d']);

		return min($seconds, self::GUEST_MAX_SECONDS);
	}

	/**
	 * Search engine bots never see the gate, so gated pages stay indexed
	 *
	 * @return bool
	 */
	public function is_bot()
	{
		return !empty($this->user->data['is_bot']);
	}

	/**
	 * Whether the visitor has passed the gate.
	 *
	 * A member who passed as a guest (before logging in or registering) has
	 * that answer copied to their account, so it follows them to other
	 * devices. When login is required, a guest has never passed.
	 *
	 * @return bool
	 */
	public function is_confirmed()
	{
		$age = $this->min_age();
		$registered = !empty($this->user->data['is_registered']);

		// A birthday that says too young outweighs an earlier answer
		if ($this->birthday_too_young())
		{
			return false;
		}

		if ($registered && (int) ($this->user->data['user_ageverification'] ?? 0) >= $age)
		{
			return true;
		}

		if ($registered && !empty($this->config['ageverification_birthday_skip']) && $this->birthday_age() >= $age)
		{
			return true;
		}

		if (!$registered && $this->login_required())
		{
			return false;
		}

		if ($this->cookie_age() >= $age)
		{
			if ($registered)
			{
				$this->store_for_user($age);
			}
			return true;
		}

		return false;
	}

	/**
	 * Whether the visitor may confirm with the button: members always, guests
	 * only when login is not required
	 *
	 * @return bool
	 */
	public function can_confirm()
	{
		return !$this->is_refused() && (!empty($this->user->data['is_registered']) || !$this->login_required());
	}

	/**
	 * Whether the visitor is too young: they said so (remembered in a cookie
	 * for as long as a "yes" would be), or, when birthdays are used, their
	 * profile birthday says so
	 *
	 * @return bool
	 */
	public function is_refused()
	{
		if ($this->birthday_too_young())
		{
			return true;
		}

		$version = (int) $this->request->variable($this->config['cookie_name'] . '_' . self::COOKIE_REFUSED, 0, false, request_interface::COOKIE);
		return $version === $this->version();
	}

	/**
	 * Remember that the visitor said they are too young
	 *
	 * @return void
	 */
	public function refuse()
	{
		$this->user->set_cookie(self::COOKIE_REFUSED, (string) $this->version(), time() + $this->guest_seconds());
	}

	/**
	 * Whether a logged-in member's profile birthday shows they are younger
	 * than the minimum age, when the birthday setting is on
	 *
	 * @return bool
	 */
	public function birthday_too_young()
	{
		if (empty($this->user->data['is_registered']) || empty($this->config['ageverification_birthday_skip']))
		{
			return false;
		}

		$age = $this->birthday_age();
		return $age >= 0 && $age < $this->min_age();
	}

	/**
	 * Record that the visitor confirmed the current minimum age: in a cookie,
	 * and on the account of a member
	 *
	 * @return void
	 */
	public function confirm()
	{
		$age = $this->min_age();

		$this->user->set_cookie(self::COOKIE, $age . '.' . $this->version(), time() + $this->guest_seconds());

		if (!empty($this->user->data['is_registered']))
		{
			$this->store_for_user($age);
		}
	}

	/**
	 * Whether the current page never shows the gate: the ACP, command-line
	 * scripts, login and related ucp.php pages, and the routes in
	 * EXEMPT_ROUTES
	 *
	 * @return bool
	 */
	public function is_exempt_page()
	{
		// The ACP, the installer, and command-line scripts such as phpBB's
		// CLI or extensions' maintenance tools
		if (defined('ADMIN_START') || defined('IN_INSTALL') || $this->is_cli())
		{
			return true;
		}

		$page_name = (string) ($this->user->page['page_name'] ?? '');

		if ($page_name === 'ucp.php')
		{
			$mode = $this->request->variable('mode', '');

			// Guests who must log in also need to be able to register
			return in_array($mode, self::EXEMPT_UCP_MODES, true) || ($mode === 'register' && $this->login_required());
		}

		$slash = strpos($page_name, '/');
		if ($slash === false)
		{
			return false;
		}

		// Only the front controllers handle routes: app.php on every
		// version, index.php on phpBB 4.0, and the board root when URLs are
		// rewritten
		$script = substr($page_name, 0, $slash);
		$route_scripts = ['', 'app.php'];
		if (phpbb_version_compare(PHPBB_VERSION, '4.0.0-dev', '>='))
		{
			$route_scripts[] = 'index.php';
		}

		if (!in_array($script, $route_scripts, true))
		{
			return false;
		}

		$path = substr($page_name, $slash);
		foreach (self::EXEMPT_ROUTES as $route)
		{
			if ($path === $route || strpos($path, $route . '/') === 0)
			{
				return true;
			}
		}

		return false;
	}

	/**
	 * Whether a forum, or a forum above it, is set to show the gate
	 *
	 * @param int $forum_id
	 * @return bool
	 */
	public function forum_gated($forum_id)
	{
		return (int) $forum_id > 0 && in_array((int) $forum_id, $this->gated_forum_ids(), true);
	}

	/**
	 * Every forum that asks, with the forums below them
	 *
	 * @return int[]
	 */
	public function gated_forum_ids()
	{
		if ($this->gated_forum_ids === null)
		{
			$sql = 'SELECT DISTINCT f.forum_id
				FROM ' . $this->forums_table . ' f, ' . $this->forums_table . ' g
				WHERE g.forum_ageverification = 1
					AND f.left_id >= g.left_id
					AND f.right_id <= g.right_id';
			$result = $this->db->sql_query($sql, self::FORUM_CACHE_TTL);
			$this->gated_forum_ids = [];
			while ($row = $this->db->sql_fetchrow($result))
			{
				$this->gated_forum_ids[] = (int) $row['forum_id'];
			}
			$this->db->sql_freeresult($result);
		}

		return $this->gated_forum_ids;
	}

	/**
	 * Forum a topic is in, 0 if the topic does not exist
	 *
	 * @param int $topic_id
	 * @return int
	 */
	public function topic_forum_id($topic_id)
	{
		$sql = 'SELECT forum_id
			FROM ' . $this->topics_table . '
			WHERE topic_id = ' . (int) $topic_id;
		$result = $this->db->sql_query($sql);
		$forum_id = (int) $this->db->sql_fetchfield('forum_id');
		$this->db->sql_freeresult($result);

		return $forum_id;
	}

	/**
	 * Whether the visitor has to be asked before seeing a forum's content
	 * outside the forum itself (search, feeds, attachments): only when forums
	 * are chosen one by one, since the board-wide setting already covers
	 * every page
	 *
	 * @return bool
	 */
	public function hide_gated_forums()
	{
		return !$this->board_gated() && !$this->is_bot() && !$this->is_confirmed();
	}

	/**
	 * The page to return to after the gate, relative to the board root, or
	 * an empty string for the board index. Anything that could lead off the
	 * board is dropped.
	 *
	 * @param string $page
	 * @return string
	 */
	public function clean_redirect($page)
	{
		$page = trim(htmlspecialchars_decode((string) $page, ENT_COMPAT));

		if ($page === ''
			|| strlen($page) > self::MAX_REDIRECT_LENGTH
			|| preg_match('#[\x00-\x1f\x7f\\\\]#', $page)
			|| strpos($page, '//') !== false
			|| strpos($page, '..') !== false
			|| preg_match('#^[a-z][a-z0-9+.-]*:#i', $page)
			|| $page[0] === '/')
		{
			return '';
		}

		return $page;
	}

	/**
	 * Whether PHP runs from the command line, where there is no visitor to ask
	 *
	 * @return bool
	 */
	protected function is_cli()
	{
		return PHP_SAPI === 'cli';
	}

	/**
	 * Age the visitor's cookie confirmed, 0 if none
	 *
	 * @return int
	 */
	protected function cookie_age()
	{
		$value = $this->request->variable($this->config['cookie_name'] . '_' . self::COOKIE, '', false, request_interface::COOKIE);
		$parts = explode('.', $value);

		if (count($parts) !== 2 || (int) $parts[1] !== $this->version())
		{
			return 0;
		}

		return (int) $parts[0];
	}

	/**
	 * Member's age from the birthday in their profile, -1 when it has no year
	 *
	 * phpBB stores birthdays as "dd-mm-yyyy" with spaces for padding, and a
	 * year of 0 when the member left it out.
	 *
	 * @return int
	 */
	protected function birthday_age()
	{
		$parts = explode('-', (string) ($this->user->data['user_birthday'] ?? ''));
		if (count($parts) !== 3)
		{
			return -1;
		}

		[$day, $month, $year] = array_map('intval', array_map('trim', $parts));
		if ($year <= 0 || !checkdate($month, $day, $year))
		{
			return -1;
		}

		$today = $this->today();
		$age = (int) $today->format('Y') - $year;
		if ((int) $today->format('n') < $month || ((int) $today->format('n') === $month && (int) $today->format('j') < $day))
		{
			$age--;
		}

		return $age;
	}

	/**
	 * Today's date in the member's time zone
	 *
	 * @return \DateTimeInterface
	 */
	protected function today()
	{
		return $this->user->create_datetime();
	}

	/**
	 * Save the confirmed age on the member's account
	 *
	 * @param int $age
	 * @return void
	 */
	protected function store_for_user($age)
	{
		$user_id = (int) $this->user->data['user_id'];

		$sql = 'UPDATE ' . $this->users_table . '
			SET ' . $this->db->sql_build_array('UPDATE', ['user_ageverification' => (int) $age]) . '
			WHERE user_id = ' . $user_id;
		$this->db->sql_query($sql);

		$this->user->data['user_ageverification'] = (int) $age;
	}
}
