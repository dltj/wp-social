<?php
/*
Plugin Name: Social
Plugin URI: http://mailchimp.com/social-plugin-for-wordpress/
Description: Broadcast newly published posts and pull in discussions using integrations with Twitter and Facebook. Brought to you by <a href="http://mailchimp.com">MailChimp</a>.
Version: 1.5
Author: Crowd Favorite
Author URI: http://crowdfavorite.com/
*/

if (!class_exists('Social')) { // try to avoid double-loading...

/**
 * Social Core
 *
 * @package Social
 */
final class Social {

	/**
	 * @var  string  URL of the API
	 */
	public static $api_url = 'https://sopresto.mailchimp.com/';

	/**
	 * @var  string  version number
	 */
	public static $version = '1.5';

	/**
	 * @var  string  internationalization key
	 */
	public static $i18n = 'social';

	/**
	 * @var  string  CRON lock directory.
	 */
	public static $cron_lock_dir = null;

	/**
	 * @var  Social_Log  logger
	 */
	private static $log = null;

	/**
	 * @var  array  default options
	 */
	protected static $options = array(
		'debug' => false,
		'install_date' => false,
		'installed_version' => false,
		'broadcast_format' => '{title}: {content} {url}',
		'twitter_anywhere_api_key' => null,
		'system_cron_api_key' => null,
		'system_crons' => '0'
	);

	/**
	 * @var  Social  instance of Social
	 */
	public static $instance = null;

	/**
	 * Loads the instance of Social.
	 *
	 * @static
	 * @return Social
	 */
	public static function instance() {
		if (self::$instance === null) {
			self::$instance = new self;
		}

		return self::$instance;
	}

	/**
	 * Handles the auto loading of classes.
	 *
	 * @static
	 * @param  string  $class
	 * @return bool
	 */
	public static function auto_load($class) {
		if (substr($class, 0, 7) == 'Social_') {
			try {
				$file = SOCIAL_PATH.'lib/'.str_replace('_', '/', strtolower($class)).'.php';
				$file = apply_filters('social_auto_load_file', $file, $class);
				if (file_exists($file)) {
					require $file;

					return true;
				}

				return false;
			}
			catch (Exception $e) {
				Social::log(sprintf(__('Failed to auto load class %s.', Social::$i18n), $class));
			}
		}

		return true;
	}

	/**
	 * Returns the broadcast format tokens.
	 *
	 * @static
	 * @return array
	 */
	public static function broadcast_tokens() {
		$defaults = array(
			'{url}' => __('Blog post\'s permalink'),
			'{title}' => __('Blog post\'s title'),
			'{content}' => __('Blog post\'s content'),
			'{date}' => __('Blog post\'s date'),
			'{author}' => __('Blog post\'s author'),
		);
		return apply_filters('social_broadcast_tokens', $defaults);
	}

	/**
	 * Sets or gets an option based on the key defined.
	 *
	 * @static
	 * @throws Exception
	 * @param  string  $key     option key
	 * @param  mixed   $value   option value
	 * @param  bool    $update  update option?
	 * @return bool|mixed
	 */
	public static function option($key, $value = null, $update = false) {
		if ($value === null) {
			$value = get_option('social_'.$key);
			Social::$options[$key] = $value;

			return $value;
		}

		Social::$options[$key] = $value;
		if ($update) {
			update_option('social_'.$key, $value);
		}
		return false;
	}

	/**
	 * Add a message to the log.
	 *
	 * @static
	 * @param  string  $message  message to add to the log
	 * @param  array   $args     arguments to pass to the writer
	 * @return void
	 */
	public static function log($message, array $args = null) {
		Social::$log->write($message, $args);
	}

	/**
	 * @var  array  connected services
	 */
	private $_services = array();

	/**
	 * @var  bool  social enabled?
	 */
	private $_enabled = false;

	/**
	 * Returns an array of all of the services.
	 *
	 * @return array
	 */
	public function services() {
		return $this->_services;
	}

	/**
	 * Returns a service by access key.
	 *
	 * [!!] If an invalid key is provided an exception will be thrown.
	 *
	 * @throws Exception
	 * @param  string  $key  service key
	 * @return Social_Service_Facebook|Social_Service_Twitter|mixed
	 */
	public function service($key) {
		if (!isset($this->_services[$key])) {
			return false;
		}

		return $this->_services[$key];
	}

	/**
	 * Initializes Social.
	 *
	 * @return void
	 */
	public function init() {
		if (version_compare(PHP_VERSION, '5.2.4', '<')) {
			deactivate_plugins(basename(__FILE__)); // Deactivate ourself
			wp_die(__("Sorry, Social requires PHP 5.2.4 or higher. Ask your host how to enable PHP 5 as the default on your servers.", Social::$i18n));
		}

		// Set the logger
		Social::$log = Social_Log::factory();

		// Register services
		$services = apply_filters('social_register_service', array());
		if (is_array($services) and count($services)) {
			$accounts = get_option('social_accounts', array());
			foreach ($services as $service) {
				if (!isset($this->_services[$service])) {
					$service_accounts = array();
					if (isset($accounts[$service]) and count($accounts[$service])) {
						$this->_enabled = true; // Flag social as enabled, we have at least one account.
						$service_accounts = $accounts[$service];
					}

					$class = 'Social_Service_'.$service;
					$this->_services[$service] = new $class($service_accounts);
				}
			}

			$personal_accounts = get_user_meta(get_current_user_id(), 'social_accounts', true);
			if (is_array($personal_accounts)) {
				foreach ($personal_accounts as $key => $_accounts) {
					if (count($_accounts)) {
						$this->_enabled = true;
						$class = 'Social_Service_'.$key.'_Account';
						foreach ($_accounts as $account) {
							$account = new $class($account);
							if (!$this->service($key)->account_exists($account->id())) {
								$this->service($key)->account($account);
							}

							$this->service($key)->account($account->id())->personal(true);
						}
					}
				}
			}
		}
		
		// Load options
		foreach (Social::$options as $key => $default) {
			$value = Social::option($key);
			if (empty($value) or !$value) {
				switch ($key) {
					case 'install_date':
						$value = current_time('timestamp', 1);
					break;
					case 'installed_version':
						$value = Social::$version;
					break;
					case 'system_cron_api_key':
						$value = wp_generate_password(16, false);
					break;
					default:
						$value = $default;
					break;
				}

				Social::option($key, $value, true);
			}

			// Upgrades
			if ($key == 'installed_version') {
				$this->upgrade($value);
			}
		}

		// JS/CSS
		if (!defined('SOCIAL_COMMENTS_JS')) {
			define('SOCIAL_COMMENTS_JS', plugins_url('assets/social.js', SOCIAL_FILE));
		}

		if (!is_admin()) {
			if (!defined('SOCIAL_COMMENTS_CSS')) {
				define('SOCIAL_COMMENTS_CSS', plugins_url('assets/comments.css', SOCIAL_FILE));
			}

			// JS/CSS
			if (SOCIAL_COMMENTS_CSS !== false) {
				wp_enqueue_style('social_comments', SOCIAL_COMMENTS_CSS, array(), Social::$version, 'screen');
			}

			if (SOCIAL_COMMENTS_JS !== false) {
				wp_enqueue_script('jquery');
			}

			// Set NONCE cookie.
			if (!isset($_COOKIE['social_auth_nonce'])) {
				$nonce = wp_create_nonce('social_authentication');
				setcookie('social_auth_nonce_'.$nonce, true, 0, '/');
			}
		}

		// JS/CSS
		if (SOCIAL_COMMENTS_JS !== false) {
			wp_enqueue_script('social_js', SOCIAL_COMMENTS_JS, array('jquery'), Social::$version, true);
		}

		// Set CRON lock directory.
		if (is_writeable(SOCIAL_PATH)) {
			Social::$cron_lock_dir = SOCIAL_PATH;
		}
		else {
			$upload_dir = wp_upload_dir();
			if (is_writeable($upload_dir['basedir'])) {
				Social::$cron_lock_dir = $upload_dir['basedir'];
			}
		}
	}

	/**
	 * Handles admin-specific operations during init.
	 *
	 * @return void
	 */
	public function admin_init() {
		// Schedule CRONs
		if ($this->option('system_crons') != '1') {
			if (wp_next_scheduled('social_cron_15_init') === false) {
				wp_schedule_event(time() + 900, 'every15min', 'social_cron_15_init');
			}

			if (wp_next_scheduled('social_cron_60_init') === false) {
				wp_schedule_event(time() + 3600, 'hourly', 'social_cron_60_init');
			}

			$this->request(wp_nonce_url(admin_url('?social_controller=cron&social_action=check_crons')));
		}
	}

	/**
	 * Handlers requests.
	 *
	 * @return void
	 */
	public function request_handler() {
		if (isset($_GET['social_controller'])) {
			Social_Request::factory()->execute();
		}
	}

	/**
	 * Adds a link to the "Settings" menu in WP-Admin.
	 *
	 * @return void
	 */
	public function admin_menu() {
		add_options_page(
			__('Social Options', Social::$i18n),
			__('Social', Social::$i18n),
			'manage_options',
			basename(SOCIAL_FILE),
			array($this, 'admin_options_form')
		);
	}

	/**
	 * Add Settings link to plugins - code from GD Star Ratings
	 *
	 * @param  array  $links
	 * @param  string  $file
	 * @return array
	 */
	public function add_settings_link($links, $file) {
		static $this_plugin;
		if (!$this_plugin) {
			$this_plugin = plugin_basename(__FILE__);
		}

		if ($file == $this_plugin) {
			$settings_link = '<a href="'.esc_url(admin_url('options-general.php?page=social.php')).'">'.__("Settings", "photosmash-galleries").'</a>';
			array_unshift($links, $settings_link);
		}
		return $links;
	}

	/**
	 * Handles the display of different messages for admin notices.
	 *
	 * @action admin_notices
	 */
	public function admin_notices() {
		if (!$this->_enabled) {
			if (current_user_can('manage_options') || current_user_can('publish_posts')) {
				$url = Social_Helper::settings_url();
				$message = sprintf(__('Social will not run until you update your <a href="%s">settings</a>.', Social::$i18n), esc_url($url));
				echo '<div class="error"><p>'.$message.'</p></div>';
			}
		}

		if (isset($_GET['page']) and $_GET['page'] == basename(SOCIAL_FILE)) {
			// CRON Lock
			if (Social::$cron_lock_dir === null) {
				$upload_dir = wp_upload_dir();
				if (isset($upload_dir['basedir'])) {
					$message = sprintf(__('Social requires that either %s or %s be writable for CRON jobs.', Social::$i18n), SOCIAL_PATH, $upload_dir['basedir']);
				}
				else {
					$message = sprintf(__('Social requires that %s is writable for CRON jobs.', Social::$i18n), SOCIAL_PATH);
				}

				echo '<div class="error"><p>'.esc_html($message).'</p></div>';
			}
		}

		// Log write error
		$error = $this->option('log_write_error');
		if ($error == '1') {
			echo '<div class="error"><p>'.
			     sprintf(__('%s needs to be writable for Social\'s logging. <a href="%" class="social_deauth">[Dismiss]</a>', Social::$i18n), SOCIAL_PATH, esc_url(admin_url('?social_controller=settings&social_action=clear_log_write_error'))).
			     '</p></div>';
		}

		// Deauthed accounts
		$deauthed = $this->option('deauthed');
		if (!empty($deauthed)) {
			foreach ($deauthed as $service => $data) {
				foreach ($data as $id => $message) {
					echo '<div class="error"><p>'.$message.' <a href="'.esc_url(admin_url('?social_controller=settings&social_action=clear_deauth&id='.$id.'&service='.$service)).'" class="social_deauth">[Dismiss]</a></p></div>';
				}
			}
		}

		// 1.5 Upgrade?
		$upgrade_1_5 = get_user_meta(get_current_user_id(), 'social_1.5_upgrade', true);
		if (!empty($upgrade_1_5)) {
			$output = 'Social needs to re-authorize in order to post to Facebook on your behalf. Please reconnect your ';
			if (current_user_can('manage_options')) {
				$output .= '<a href="%s">global</a> and ';
			}
			$output .= '<a href="%s">personal</a> accounts.';

			$output = __($output, Social::$i18n);
			if (current_user_can('manage_options')) {
				$output = sprintf($output, esc_url(Social_Helper::settings_url()), esc_url(admin_url('profile.php#social-networks')));
			}
			else {
				$output = sprintf($output, esc_url(admin_url('profile.php#social-networks')));
			}

			$dismiss = sprintf(__('<a href="%s" class="%s">[Dismiss]</a>', Social::$i18n), esc_url(admin_url('?social_controller=settings&social_action=clear_1_5_upgrade')), 'social_deauth');

			echo '<div class="error"><p>'.$output.' '.$dismiss.'</p></div>';
		}
	}

	/**
	 * Handles displaying the admin assets.
	 *
	 * @action load-profile.php
	 * @action load-post.php
	 * @action load-post-new.php
	 * @action load-settings_page_social
	 * @return void
	 */
	public function admin_resources() {
		if (!defined('SOCIAL_ADMIN_JS')) {
			define('SOCIAL_ADMIN_JS', plugins_url('assets/admin.js', SOCIAL_FILE));
		}

		if (!defined('SOCIAL_ADMIN_CSS')) {
			define('SOCIAL_ADMIN_CSS', plugins_url('assets/admin.css', SOCIAL_FILE));
		}

		if (SOCIAL_ADMIN_CSS !== false) {
			wp_enqueue_style('social_admin', SOCIAL_ADMIN_CSS, array(), Social::$version, 'screen');
		}

		if (SOCIAL_ADMIN_JS !== false) {
			wp_enqueue_script('social_admin', SOCIAL_ADMIN_JS, array(), Social::$version, true);
		}
	}

	/**
	 * Displays the admin options form.
	 *
	 * @return void
	 */
	public function admin_options_form() {
		Social_Request::factory('settings/index')->execute();
	}

	/**
	 * Shows the user's social network accounts.
	 *
	 * @param  object  $profileuser
	 * @return void
	 */
	public function show_user_profile($profileuser) {
		echo Social_View::factory('wp-admin/profile', array(
			'services' => $this->services()
		));
	}

	/**
	 * Add Meta Boxes
	 *
	 * @action do_meta_boxes
	 * @return void
	 */
	public function do_meta_boxes() {
		global $post;

		if ($this->_enabled and $post !== null) {
			foreach ($this->services() as $service) {
				if (count($service->accounts())) {
					add_meta_box('social_meta_broadcast', __('Social Broadcasting', Social::$i18n), array($this, 'add_meta_box'), 'post', 'side', 'high');
					break;
				}
			}

			if ($post->post_status == 'publish') {
				add_meta_box('social_meta_aggregation_log', __('Social Comments', Social::$i18n), array($this, 'add_meta_box_log'), 'post', 'normal', 'core');
			}
		}
	}

	/**
	 * Adds the broadcasting meta box.
	 *
	 * @return void
	 */
	public function add_meta_box() {
		global $post;

		$content = '';
		if (!in_array($post->post_status, array('publish', 'future', 'pending'))) {
			$notify = false;
			if (get_post_meta($post->ID, '_social_notify', true) == '1') {
				$notify = true;
			}

			$content = Social_View::factory('wp-admin/post/meta/broadcast/default', array(
				'post' => $post,
				'notify' => $notify,
			));
		}
		else if (in_array($post->post_status, array('future', 'pending'))) {
			$accounts = get_post_meta($post->ID, '_social_broadcast_accounts', true);
			$content = Social_View::factory('wp-admin/post/meta/broadcast/scheduled', array(
				'post' => $post,
				'services' => $this->services(),
				'accounts' => $accounts
			));
		}
		else if ($post->post_status == 'publish') {
			$ids = get_post_meta($post->ID, '_social_broadcasted_ids', true);
			$content = Social_View::factory('wp-admin/post/meta/broadcast/published', array(
				'ids' => $ids,
				'services' => $this->services()
			));
		}

		echo Social_View::factory('wp-admin/post/meta/broadcast/shell', array(
			'post' => $post,
			'content' => $content
		));
	}

	/**
	 * Adds the aggregation log meta box.
	 *
	 * @return void
	 */
	public function add_meta_box_log() {
		global $post;

		$next_run = get_post_meta($post->ID, '_social_aggregation_next_run', true);
		if (empty($next_run)) {
			$next_run = __('Not Scheduled', Social::$i18n);
		}
		else {
			$next_run = date('F j, Y, g:i a', ((int) $next_run + (get_option('gmt_offset') * 3600)));
		}

		echo Social_View::factory('wp-admin/post/meta/log/shell', array(
			'post' => $post,
			'next_run' => $next_run,
		));
	}

	/**
	 * Show the broadcast options if publishing.
	 *
	 * @param  string  $location  default post-publish location
	 * @param  int     $post_id   post ID
	 * @return string|void
	 */
	public function redirect_post_location($location, $post_id) {
		if ((isset($_POST['social_notify']) and $_POST['social_notify'] == '1') and
		    (isset($_POST['visibility']) and $_POST['visibility'] !== 'private')) {
			update_post_meta($post_id, '_social_notify', '1');
			if (isset($_POST['publish']) or isset($_POST['social_broadcast'])) {
				Social_Request::factory('broadcast/options')->post(array(
					'post_ID' => $post_id,
					'location' => $location,
				))->execute();
			}
		}
		else {
			delete_post_meta($post_id, '_social_notify');
		}
		return $location;
	}

	/**
	 * Removes post meta if the post is going to private.
	 *
	 * @param  string  $new
	 * @param  string  $old
	 * @param  object  $post
	 * @return void
	 */
	public function transition_post_status($new, $old, $post) {
		if ($new == 'private') {
			delete_post_meta($post->ID, '_social_notify');
			delete_post_meta($post->ID, '_social_broadcast_accounts');

			foreach ($this->services() as $key => $service) {
				delete_post_meta($post->ID, '_social_'.$key.'_content');
			}
		}
		else if ($new == 'publish') {
			if (isset($_POST['publish']) and !isset($_POST['social_notify'])) {
				Social_Aggregation_Queue::factory()->add($post->ID)->save();
			}

			if ($old == 'future') {
				Social_Request::factory('broadcast/run')->query(array(
					'post_ID' => $post->ID
				))->execute();
			}
		}
	}

	/**
	 * Sets the broadcasted IDs for the post.
	 *
	 * @param  int    $post_id
	 * @param  array  $broadcasted_ids
	 * @return void
	 */
	public function set_broadcasted_meta($post_id, array $broadcasted_ids) {
		if (count($broadcasted_ids)) {
			$post_id = (int) $post_id;
			$_broadcasted_ids = get_post_meta($post_id, '_social_broadcasted_ids', true);
			if (empty($_broadcasted_ids)) {
				$_broadcasted_ids = array();
			}

			foreach ($broadcasted_ids as $key => $accounts) {
				if (!isset($_broadcasted_ids[$key])) {
					$_broadcasted_ids[$key] = array();
				}

				foreach ($accounts as $account_id => $id) {
					$_broadcasted_ids[$key][$account_id] = $id;
				}
			}

			update_post_meta($post_id, '_social_broadcasted_ids', $_broadcasted_ids);
		}
	}

	/**
	 * Adds the 15 minute interval.
	 *
	 * @param  array  $schedules
	 * @return array
	 */
	public function cron_schedules($schedules) {
		$schedules['every15min'] = array(
			'interval' => 900,
			'display' => 'Every 15 minutes'
		);
		return $schedules;
	}

	/**
	 * Sends a request to initialize CRON 15.
	 *
	 * @return void
	 */
	public function cron_15_init() {
		$this->request(wp_nonce_url(site_url('?social_controller=cron&social_action=cron_15')));
	}

	/**
	 * Sends a request to initialize CRON 60.
	 *
	 * @return void
	 */
	public function cron_60_init() {
		$this->request(wp_nonce_url(site_url('?social_controller=cron&social_action=cron_60')));
	}

	/**
	 * Runs the aggregation loop.
	 * 
	 * @return void
	 */
	public function run_aggregation() {
		$queue = Social_Aggregation_Queue::factory();

		foreach ($queue->runnable() as $timestamp => $posts) {
			foreach ($posts as $id => $interval) {
				$post = get_post($id);
				if ($post !== null) {
					$queue->add($id, $interval)->save();
					$this->request(site_url('?social_controller=aggregation&social_action=run&post_id='.$id));
				}
				else {
					$queue->remove($id, $timestamp)->save();
				}
			}
		}
	}

	/**
	 * Removes the post from the aggregation queue.
	 *
	 * @param  int  $post_id
	 * @return void
	 */
	public function delete_post($post_id) {
		Social_Aggregation_Queue::factory()->remove($post_id);
	}

	/**
	 * Hides the Site Admin link for social-based users.
	 *
	 * @filter register
	 * @param  string  $link
	 * @return string
	 */
	public function register($link) {
		if (is_user_logged_in()) {
			$commenter = get_user_meta(get_current_user_id(), 'social_commenter', true);
			if ($commenter === '1') {
				return '';
			}
		}

		return $link;
	}

	/**
	 * Show the disconnect link for social-based users.
	 *
	 * @filter loginout
	 * @param  string  $link
	 * @return string
	 */
	public function loginout($link) {
		if (is_user_logged_in()) {
			$commenter = get_user_meta(get_current_user_id(), 'social_commenter', true);
			if ($commenter === '1') {
				foreach ($this->services() as $key => $service) {
					$account = reset($service->accounts());
					if ($account) {
						return $service->disconnect_url($account);
					}
				}
			}
		}
		else {
			$link = explode('>'.__('Log in'), $link);
			$link = $link[0].' id="social_login">'.__('Log in').$link[1];
		}

		return $link;
	}

	/**
	 * Overrides the default WordPress comments_template function.
	 *
	 * @return string
	 */
	public function comments_template() {
		global $post;

		if (!(is_singular() and (have_comments() or $post->comment_status == 'open'))) {
			return;
		}

		if (!defined('SOCIAL_COMMENTS_FILE')) {
			define('SOCIAL_COMMENTS_FILE', trailingslashit(dirname(SOCIAL_FILE)).'views/comments.php');
		}

		return SOCIAL_COMMENTS_FILE;
	}

	/**
	 * Returns an array of comment types that display avatars.
	 *
	 * @param  array  $types  default WordPress types
	 * @return array
	 */
	public function get_avatar_comment_types($types) {
		$types = array_merge($types, array(
			'wordpress',
			'twitter',
			'facebook',
		));
		return $types;
	}

	/**
	 * Gets the avatar based on the comment type.
	 *
	 * @param  string  $avatar
	 * @param  object  $comment
	 * @param  int     $size
	 * @param  string  $default
	 * @param  string  $alt
	 * @return string
	 */
	public function get_avatar($avatar, $comment, $size, $default, $alt) {
		$image = null;
		if (is_object($comment)) {
			$service = $this->service($comment->comment_type);
			if ($service !== false) {
				$image = get_comment_meta($comment->comment_ID, 'social_profile_image_url', true);
			}
		}
		else if ((is_string($comment) or is_int($comment)) and $default != 'force-wordpress') {
			foreach ($this->services() as $key => $service) {
				if (count($service->accounts())) {
					$account = reset($service->accounts());
					$image = $account->avatar();
				}
			}
		}

		if ($image !== null) {
			$type = '';
			if (is_object($comment)) {
				$type = $comment->comment_type;
			}
			return "<img alt='{$alt}' src='{$image}' class='avatar avatar-{$size} photo {$type}' height='{$size}' width='{$size}' />";
		}

		return $avatar;
	}

	/**
	 * Sets the comment type upon being saved.
	 *
	 * @param  int  $comment_ID
	 */
	public function comment_post($comment_ID) {
		global $wpdb, $comment_content, $commentdata;
		$type = false;
		$services = $this->services();
		if (!empty($services)) {
			$account_id = $_POST['social_post_account'];

			$url = wp_get_shortlink($commentdata['comment_post_ID']);
			if (empty($url)) {
				$url = home_url('?p='.$commentdata['comment_post_ID']);
			}
			$url .= '#comment-'.$comment_ID;
			$url_length = strlen($url) + 1;
			$comment_length = strlen($comment_content);
			$combined_length = $url_length + $comment_length;
			foreach ($services as $key => $service) {
				$max_length = $service->max_broadcast_length();
				if ($combined_length > $max_length) {
					$output = substr($comment_content, 0, ($max_length - $url_length - 3)).'...';
				} else {
					$output = $comment_content;
				}
				$output .= ' '.$url;

				foreach ($service->accounts() as $account) {
					if ($account_id == $account->id()) {
						if (isset($_POST['post_to_service'])) {
							$id = $service->broadcast($account, $output)->id();
							if ($id === false) {
								// An error occurred...
								$sql = "
									DELETE
									  FROM $wpdb->comments
									 WHERE comment_ID='$comment_ID'
								";
								$wpdb->query($sql);
								$commentdata = null;
								$comment_content = null;

								wp_die(sprintf(__('Error: Failed to post your comment to %s, please go back and try again.', Social::$i18n), $service->title()));
							}
							update_comment_meta($comment_ID, 'social_status_id', $id);
						}

						update_comment_meta($comment_ID, 'social_account_id', $account_id);
						update_comment_meta($comment_ID, 'social_profile_image_url', $account->avatar());
						update_comment_meta($comment_ID, 'social_comment_type', $service->key());

						if ($commentdata['user_ID'] != '0') {
							$sql = "
								UPDATE $wpdb->comments
								   SET comment_author='{$account->name()}',
								       comment_author_url='{$account->url()}'
								 WHERE comment_ID='$comment_ID'
							";
							$wpdb->query($sql);
						}
						break;
					}
				}

				if ($type !== false) {
					break;
				}
			}
		}
	}

	/**
	 * Displays a comment.
	 *
	 * @param  object  $comment  comment object
	 * @param  array   $args
	 * @param  int     $depth
	 */
	public function comment($comment, $args, $depth) {
		$comment_type = get_comment_meta($comment->comment_ID, 'social_comment_type', true);
		if (empty($comment_type)) {
			$comment_type = (empty($comment->comment_type) ? 'wordpress' : $comment->comment_type);
		}
		$comment->comment_type = $comment_type;
		$GLOBALS['comment'] = $comment;

		$status_url = null;
		$service = null;
		if (!in_array($comment_type, apply_filters('social_ignored_comment_types', array('wordpress', 'pingback')))) {
			$service = $this->service($comment->comment_type);
			if ($service !== false and $service->show_full_comment($comment->comment_type)) {
				$status_id = get_comment_meta($comment->comment_ID, 'social_status_id', true);
				if (!empty($status_id)) {
					$status_url = $service->status_url(get_comment_author(), $status_id);
				}

				if ($status_url === null) {
					$comment_type = 'wordpress';
				}
			}
		}

		echo Social_View::factory('comment/comment', array(
			'comment_type' => $comment_type,
			'comment' => $comment,
			'service' => $service,
			'status_url' => $status_url,
			'depth' => $depth,
			'args' => $args,
		));
	}

	/**
	 * Runs the upgrade only if the installed version is older than the current version.
	 *
	 * @param  string  $installed_version
	 * @return void
	 */
	private function upgrade($installed_version) {
		if (version_compare($installed_version, Social::$version, '<')) {
			global $wpdb;

			// 1.5
			// Find old social_notify and update to _social_notify.
			$meta_keys = array(
				'social_aggregated_replies',
				'social_broadcast_error',
				'social_broadcast_accounts',
				'social_broadcasted_ids',
				'social_aggregation_log',
				'social_twitter_content',
				'social_notify_twitter',
				'social_facebook_content',
				'social_notify_facebook',
				'social_notify',
				'social_broadcasted'
			);
			if (count($meta_keys)) {
				foreach ($meta_keys as $key) {
					$wpdb->query("
						UPDATE $wpdb->postmeta
						   SET meta_key = '_$key'
						 WHERE meta_key = '$key'
					");
				}
			}

			// Delete old useless meta
			$meta_keys = array(
				'_social_broadcasted'
			);
			if (count($meta_keys)) {
				foreach ($meta_keys as $key) {
					$wpdb->query("
						DELETE
						  FROM $wpdb->postmeta
						 WHERE meta_key = '$key'
					");
				}
			}

			// De-auth Facebook accounts for new permissions.
			if (version_compare($installed_version, '1.5', '<')) {
				// Global accounts
				$accounts = get_option('social_accounts', array());
				if (isset($accounts['facebook'])) {
					$accounts['facebook'] = array();
					update_option('social_accounts', $accounts);
				}

				// Personal accounts
				$users = get_users(array('role' => 'subscriber'));
				$ids = array(0);
				if (is_array($users)) {
					foreach ($users as $user) {
						$ids[] = $user->ID;
					}
				}

				$results = $wpdb->get_results("
					SELECT user_id, meta_value 
					  FROM $wpdb->usermeta
					 WHERE meta_key = 'social_accounts'
				");
				foreach ($results as $result) {
					$accounts = maybe_unserialize($result->meta_value);
					if (is_array($accounts) and isset($accounts['facebook'])) {
						$accounts['facebook'] = array();
						update_user_meta($result->user_id, 'social_accounts', $accounts);

						if (!in_array($result->user_id, $ids)) {
							update_user_meta($result->user_id, 'social_1.5_upgrade', true);
						}
					}
				}
			}

			Social::option('installed_version', Social::$version, true);
		}
	}

	/**
	 * Removes an account from the XMLRPC accounts.
	 *
	 * @param  string  $service
	 * @param  int     $id
	 * @return void
	 */
	public function remove_from_xmlrpc($service, $id) {
		// Remove from the XML-RPC
		$xmlrpc = $this->option('xmlrpc_accounts');
		if (!empty($xmlrpc) and isset($xmlrpc[$service])) {
			$ids = array_values($xmlrpc[$service]);
			if (in_array($id, $ids)) {
				$_ids = array();
				foreach ($ids as $id) {
					if ($id != $_GET['id']) {
						$_ids[] = $id;
					}
				}
				$xmlrpc[$_GET['service']] = $_ids;
				update_option('social_xmlrpc_accounts', $xmlrpc);
			}
		}
	}

	/**
	 * Recursively applies wp_kses() to an array/stdClass.
	 * 
	 * @param  mixed  $object
	 * @return mixed
	 */
	public function kses($object) {
		if (is_object($object)) {
			$_object = new stdClass;
		}
		else {
			$_object = array();
		}

		foreach ($object as $key => $val) {
			if (is_object($val)) {
				$_object->$key = $this->kses($val);
			}
			else if (is_array($val)) {
				$_object[$key] = $this->kses($val);
			}
			else {
				if (is_object($_object)) {
					$_object->$key = wp_kses($val, array());
				}
				else {
					$_object[$key] = wp_kses($val, array());
				}
			}
		}

		return $_object;
	}

	/**
	 * Handles the remote timeout requests for Social.
	 *
	 * @param  string  $url   url to request
	 * @param  bool    $post  set to true to do a wp_remote_post
	 * @return void
	 */
	private function request($url, $post = false) {
		$url = str_replace('&amp;', '&', wp_nonce_url($url));
		$data = array(
			'timeout' => 0.01,
			'blocking' => false,
			'sslverify' => apply_filters('https_local_ssl_verify', true),
		);

		if ($post) {
			wp_remote_post($url, $data);
		}
		else {
			wp_remote_get($url, $data);
		}
	}

} // End Social

$social_file = __FILE__;
if (isset($plugin)) {
	$social_file = $plugin;
}
else if (isset($mu_plugin)) {
	$social_file = $mu_plugin;
}
else if (isset($network_plugin)) {
	$social_file = $network_plugin;
}
$social_path = dirname($social_file);

define('SOCIAL_FILE', $social_file);
define('SOCIAL_PATH', $social_path.'/');

// Register Social's autoloading
spl_autoload_register(array('Social', 'auto_load'));

$social = Social::instance();

// General Actions
add_action('init', array($social, 'init'), 1);
add_action('init', array($social, 'request_handler'), 2);
add_action('load-settings_page_social', array($social, 'admin_init'), 1);
add_action('load-'.basename(SOCIAL_FILE), array($social, 'admin_init'), 1);
add_action('comment_post', array($social, 'comment_post'));
add_action('admin_notices', array($social, 'admin_notices'));
add_action('load-post-new.php', array($social, 'admin_resources'));
add_action('load-post.php', array($social, 'admin_resources'));
add_action('load-profile.php', array($social, 'admin_resources'));
add_action('load-settings_page_social', array($social, 'admin_resources'));
add_action('transition_post_status', array($social, 'transition_post_status'), 10, 3);
add_action('show_user_profile', array($social, 'show_user_profile'));
add_action('do_meta_boxes', array($social, 'do_meta_boxes'));
add_action('delete_post', array($social, 'delete_post'));

// CRON Actions
add_action('social_cron_15_init', array($social, 'cron_15_init'));
add_action('social_cron_60_init', array($social, 'cron_60_init'));
add_action('social_cron_15', array($social, 'run_aggregation'));

// Admin Actions
add_action('admin_menu', array($social, 'admin_menu'));

// Filters
add_filter('cron_schedules', array($social, 'cron_schedules'));
add_filter('plugin_action_links', array($social, 'add_settings_link'), 10, 2);
add_filter('redirect_post_location', array($social, 'redirect_post_location'), 10, 2);
add_filter('comments_template', array($social, 'comments_template'));
add_filter('get_avatar_comment_types', array($social, 'get_avatar_comment_types'));
add_filter('get_avatar', array($social, 'get_avatar'), 10, 5);
add_filter('register', array($social, 'register'));

// Service filters
add_filter('social_auto_load_class', array($social, 'auto_load_class'));

// Require Facebook and Twitter by default.
require SOCIAL_PATH.'social-twitter.php';
require SOCIAL_PATH.'social-facebook.php';

} // End class_exists check
