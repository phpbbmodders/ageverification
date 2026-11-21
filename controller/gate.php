<?php
/**
 *
 * Age Verification extension for the phpBB Forum Software package
 *
 * @copyright (c) 2026, phpBB Modders, https://www.phpbbmodders.com/
 * @license GNU General Public License, version 2 (GPL-2.0)
 *
 */

namespace phpbbmodders\ageverification\controller;

use phpbb\config\config;
use phpbb\config\db_text;
use phpbb\controller\helper;
use phpbb\language\language;
use phpbb\request\request_interface;
use phpbb\template\template;
use phpbbmodders\ageverification\gate as gate_service;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * The gate page, where visitors confirm their age, and the page shown to
 * those who say they are too young when no exit address is set
 */
class gate
{
	/** Form key name */
	const FORM_KEY = 'phpbbmodders_ageverification';

	/** @var config */
	protected $config;

	/** @var db_text */
	protected $config_text;

	/** @var helper */
	protected $helper;

	/** @var language */
	protected $language;

	/** @var request_interface */
	protected $request;

	/** @var template */
	protected $template;

	/** @var gate_service */
	protected $gate;

	/** @var string */
	protected $php_ext;

	/**
	 * @param config				$config
	 * @param db_text				$config_text
	 * @param helper				$helper
	 * @param language				$language
	 * @param request_interface		$request
	 * @param template				$template
	 * @param gate_service			$gate
	 * @param string				$php_ext
	 */
	public function __construct(config $config, db_text $config_text, helper $helper, language $language, request_interface $request, template $template, gate_service $gate, $php_ext)
	{
		$this->config = $config;
		$this->config_text = $config_text;
		$this->helper = $helper;
		$this->language = $language;
		$this->request = $request;
		$this->template = $template;
		$this->gate = $gate;
		$this->php_ext = $php_ext;
	}

	/**
	 * Show the gate, or record the visitor's confirmation and send them on
	 * to the page they asked for
	 *
	 * @return \Symfony\Component\HttpFoundation\Response
	 */
	public function handle()
	{
		$this->language->add_lang('ageverification', 'phpbbmodders/ageverification');

		// Visitors who said they are too young, and members whose birthday
		// says so, only get the "too young" page
		if ($this->gate->is_refused())
		{
			return $this->render_leave(true);
		}

		$redirect = $this->gate->clean_redirect($this->request->variable('redirect', '', true));
		$error = '';

		if ($this->request->is_set_post('ageverification_leave'))
		{
			if (check_form_key(self::FORM_KEY))
			{
				$this->gate->refuse();

				$exit_url = (string) $this->config['ageverification_exit_url'];
				if ($exit_url !== '')
				{
					redirect(htmlspecialchars_decode($exit_url, ENT_COMPAT), false, true);
				}

				return $this->render_leave(true);
			}

			$error = $this->language->lang('FORM_INVALID');
		}

		$can_confirm = $this->gate->can_confirm();

		if ($can_confirm && $this->request->is_set_post('ageverification_confirm'))
		{
			if (check_form_key(self::FORM_KEY))
			{
				$this->gate->confirm();

				// Come back here once to see whether the browser kept the
				// cookie, so guests with cookies turned off are told why they
				// are asked again
				$params = ['checked' => 1] + (($redirect !== '') ? ['redirect' => $redirect] : []);
				redirect($this->helper->route('phpbbmodders_ageverification_gate', $params, false, false, UrlGeneratorInterface::ABSOLUTE_URL));
			}

			$error = $this->language->lang('FORM_INVALID');
		}
		else if ($this->gate->is_confirmed())
		{
			$this->go_to($redirect);
		}
		else if ($this->request->variable('checked', 0))
		{
			$error = $this->language->lang('AGEVERIFICATION_COOKIES_NEEDED');
		}

		add_form_key(self::FORM_KEY);

		$age = $this->gate->min_age();

		if (!$can_confirm)
		{
			// Back to the page they asked for after logging in, where the
			// member is asked once more and then remembered
			$login_redirect = ($redirect !== '') ? $redirect : 'index.' . $this->php_ext;
			$this->template->assign_vars([
				'U_AGEVERIFICATION_LOGIN'		=> append_sid(generate_board_url() . '/ucp.' . $this->php_ext, ['mode' => 'login', 'redirect' => $login_redirect]),
				'U_AGEVERIFICATION_REGISTER'	=> ((int) $this->config['require_activation'] !== USER_ACTIVATION_DISABLE) ? append_sid(generate_board_url() . '/ucp.' . $this->php_ext, ['mode' => 'register']) : '',
			]);
		}

		$this->template->assign_vars([
			'S_AGEVERIFICATION_PAGE'		=> true,
			'S_AGEVERIFICATION_CAN_CONFIRM'	=> $can_confirm,
			'AGEVERIFICATION_ERROR'		=> $error,
			'AGEVERIFICATION_MIN_AGE'	=> $age,
			'AGEVERIFICATION_REDIRECT'	=> $redirect,
			'AGEVERIFICATION_TEXT'		=> $this->gate_text($age),
			'U_AGEVERIFICATION_ACTION'	=> $this->helper->route('phpbbmodders_ageverification_gate'),
		]);

		return $this->helper->render('@phpbbmodders_ageverification/ageverification_body.html', $this->language->lang('AGEVERIFICATION_TITLE'));
	}

	/**
	 * Page for visitors who say they are too young
	 *
	 * @return \Symfony\Component\HttpFoundation\Response
	 */
	public function leave()
	{
		$this->language->add_lang('ageverification', 'phpbbmodders/ageverification');

		return $this->render_leave($this->gate->is_refused());
	}

	/**
	 * The "too young" page
	 *
	 * @param bool $refused Whether the answer is remembered, so there is no way back to the buttons
	 * @return \Symfony\Component\HttpFoundation\Response
	 */
	protected function render_leave($refused)
	{
		$this->template->assign_vars([
			'S_AGEVERIFICATION_PAGE'	=> true,
			'S_AGEVERIFICATION_REFUSED'	=> $refused,
			'AGEVERIFICATION_MIN_AGE'	=> $this->gate->min_age(),
			'U_AGEVERIFICATION_GATE'	=> $this->helper->route('phpbbmodders_ageverification_gate'),
		]);

		return $this->helper->render('@phpbbmodders_ageverification/ageverification_leave.html', $this->language->lang('AGEVERIFICATION_TITLE'));
	}

	/**
	 * The admin's welcome text, or the built-in text when none is set
	 *
	 * @param int $age
	 * @return string HTML
	 */
	protected function gate_text($age)
	{
		$text = (string) $this->config_text->get('ageverification_text');

		if (trim($text) === '')
		{
			return '<p>' . $this->language->lang('AGEVERIFICATION_DEFAULT_TEXT', $this->config['sitename'], $age) . '</p>';
		}

		return generate_text_for_display($text, $this->config['ageverification_text_uid'], $this->config['ageverification_text_bitfield'], (int) $this->config['ageverification_text_flags']);
	}

	/**
	 * Send the visitor to a page on the board
	 *
	 * @param string $page Relative to the board root, already cleaned; empty for the index
	 * @return void
	 */
	protected function go_to($page)
	{
		redirect(append_sid(generate_board_url() . '/' . $page));
	}
}
