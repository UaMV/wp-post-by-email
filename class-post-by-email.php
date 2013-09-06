<?php
/**
 * Post By Email
 *
 * @package   PostByEmail
 * @author    Kat Hagan <kat@codebykat.com>
 * @license   GPL-2.0+
 * @link      http://codebykat.wordpress.com
 * @copyright 2013 Kat Hagan
 */

/**
 * Plugin class.
 *
 * @package PostByEmail
 * @author  Kat Hagan <kat@codebykat.com>
 */
class Post_By_Email {

	/**
	 * Plugin version, used for cache-busting of style and script file references.
	 *
	 * @since   0.9.0
	 *
	 * @var     string
	 */
	protected $version = '0.9.7';

	/**
	 * Unique identifier for your plugin.
	 *
	 * Use this value (not the variable name) as the text domain when internationalizing strings of text. It should
	 * match the Text Domain file header in the main plugin file.
	 *
	 * @since    0.9.0
	 *
	 * @var      string
	 */
	protected $plugin_slug = 'post-by-email';

	/**
	 * Instance of this class.
	 *
	 * @since    0.9.0
	 *
	 * @var      object
	 */
	protected static $instance = null;

	/**
	 * Plugin include path (used for autoloading libraries).
	 *
	 * @since    0.9.7
	 *
	 * @var      string
	 */
	public static $path;

	/**
	 * Default settings.
	 *
	 * @since    0.9.8
	 *
	 * @var      array
	 */
	protected $default_options = array(
		'mailserver_url' => 'mail.example.com',
		'mailserver_login' => 'login@example.com',
		'mailserver_pass' => 'password',
		'mailserver_port' => 110,
		'default_email_category' => '');


	/**
	 * Initialize the plugin by setting localization, filters, and administration functions.
	 *
	 * @since     0.9.0
	 */
	private function __construct() {
		// Load plugin text domain
		add_action( 'plugins_loaded', array( $this, 'load_plugin_textdomain' ) );

		// Enable autoloading
		add_action( 'plugins_loaded', array( $this, 'load' ) );

		// add hooks to check for mail
		add_action( 'wp-mail.php', array( $this, 'manual_check_email' ) );
		add_action( 'post-by-email-wp-mail.php', array( $this, 'check_email' ) );
	}

	/**
	 * Return an instance of this class.
	 *
	 * @since     0.9.0
	 *
	 * @return    object    A single instance of this class.
	 */
	public static function get_instance() {

		// If the single instance hasn't been set, set it now.
		if ( null == self::$instance ) {
			self::$instance = new self;
		}

		return self::$instance;
	}

	/**
	 * Fired when the plugin is activated.
	 *
	 * @since    0.9.0
	 *
	 * @param    boolean    $network_wide    True if WPMU superadmin uses "Network Activate" action, false if WPMU is disabled or plugin is activated on an individual blog.
	 */
	function activate( $network_wide ) {
		// set up plugin options
		$options = get_option( 'post_by_email_options' );

		if( ! $options ) {
			$options = $this->default_options;

			// if old global options exist, copy them into plugin options
			foreach( array_keys( $this->default_options ) as $optname ) {
				if( get_option( $optname ) ) {
					$options[ $optname ] = get_option( $optname );
					//delete_option( $optname );			
				}
			}

			update_option( 'post_by_email_options', $options );
		}

		// schedule hourly mail checks with wp_cron
		if( ! wp_next_scheduled( 'post-by-email-wp-mail.php' ) ) {
			wp_schedule_event( current_time( 'timestamp', 1 ), 'hourly', 'post-by-email-wp-mail.php' );
		}
	}

	/**
	* Fired when the plugin is deactivated.
	*
	* @since    0.9.8
	*/
	function deactivate() {
		wp_clear_scheduled_hook( 'post-by-email-wp-mail.php' );
	}

	/**
	 * Load the plugin text domain for translation.
	 *
	 * @since    0.9.0
	 */
	public function load_plugin_textdomain() {
		$domain = $this->plugin_slug;
		$locale = apply_filters( 'plugin_locale', get_locale(), $domain );

		load_textdomain( $domain, WP_LANG_DIR . '/' . $domain . '/' . $domain . '-' . $locale . '.mo' );
		load_plugin_textdomain( $domain, FALSE, dirname( plugin_basename( __FILE__ ) ) . '/lang/' );
	}

	/**
	 * Run "check_email" when not called by wp_cron
	 *
	 * @since    0.9.9
	 */
	public function manual_check_email() {
		// update scheduled check so next one is an hour from last manual check
		wp_clear_scheduled_hook( 'post-by-email-wp-mail.php' );
		wp_schedule_event( current_time( 'timestamp', 1 ) + HOUR_IN_SECONDS, 'hourly', 'post-by-email-wp-mail.php' );

		self::check_email();

		wp_safe_redirect( admin_url( 'tools.php?page=post-by-email' ) );
	}

	/**
	 * Check for new messages and post them to the blog.
	 *
	 * @since    0.9.0
	 */
	public function check_email() {
		// Only check at this interval for new messages.
		if ( ! defined( 'WP_MAIL_INTERVAL' ) )
			define( 'WP_MAIL_INTERVAL', 5 * MINUTE_IN_SECONDS );

		$last_checked = get_transient( 'mailserver_last_checked' );

		$options = get_option( 'post_by_email_options' );
		$options['last_checked'] = current_time( 'timestamp' );
		update_option( 'post_by_email_options', $options );

		if ( $last_checked && ! WP_DEBUG ) {
			$log_message = __( 'Slow down cowboy, no need to check for new mails so often!', 'post-by-email' );
			self::save_log_message( $log_message );
			return;
		}

		set_transient( 'mailserver_last_checked', true, WP_MAIL_INTERVAL );

		// if options aren't set, there's nothing to do, move along
		foreach( array( 'mailserver_url', 'mailserver_login', 'mailserver_pass' ) as $optname ) {
			if( ! $options[$optname] || $options[$optname] == $this->default_options[$optname] ) {
				$log_message = __( 'Options not set; skipping.', 'post-by-email' );
				self::save_log_message( $log_message );
				return;
			}
		}

		$time_difference = get_option( 'gmt_offset' ) * HOUR_IN_SECONDS;

		$phone_delim = '::';

		$pop3 = new Horde_Imap_Client_Socket_Pop3( array( 'username' => $options['mailserver_login'],
															'password' => $options['mailserver_pass'],
															'hostspec' => $options['mailserver_url'],
															'port' => $options['mailserver_port'] ) );
		$pop3->_setInit( 'authmethod', 'USER' );

		try {
			$pop3->login();
			$test = $pop3->search( 'INBOX' );
			$uids = $test['match'];
		}
		catch( Horde_Imap_Client_Exception $e ) {
			self::save_log_message( __( 'An error occurred: ', 'post-by-email') . $e->getMessage() );
			return;
		}

		if( 0 === sizeof( $uids ) ) {
			$pop3->shutdown();
			self::save_log_message( __( 'There doesn&#8217;t seem to be any new mail.', 'post-by-email' ) );
			return;
		}


		foreach( $uids as $id ) {
			$log_message = '';

			$uid = new Horde_Imap_Client_Ids( $id );

			// get headers
			$headerquery = new Horde_Imap_Client_Fetch_Query();
			$headerquery->headerText( array() );
			$headerlist = $pop3->fetch( 'INBOX', $headerquery, array(
				'ids' => $uid
			));

			$headers = $headerlist->first()->getHeaderText( 0, Horde_Imap_Client_Data_Fetch::HEADER_PARSE );

			/* Subject */
			// Captures any text in the subject before $phone_delim as the subject
			$subject = $headers->getValue( 'Subject' );
			$subject = explode( $phone_delim, $subject );
			$subject = $subject[0];

			/* Author */
			$post_author = 1;
			$author_found = false;

			// Set the author using the email address (From or Reply-To, the last used)
			// otherwise use the site admin
			$author = $headers->getValue( 'From' );
			$replyto = $headers->getValue( 'Reply-To' );

			if ( ! $author_found ) {
				if ( preg_match( '|[a-z0-9_.-]+@[a-z0-9_.-]+(?!.*<)|i', $author, $matches ) )
					$author = $matches[0];
				else
					$author = trim( $author );
				$author = sanitize_email( $author );
				if ( is_email( $author ) ) {
					$log_message .= '<p>' . sprintf( __( 'Author is %s', 'post-by-email' ), $author ) . '</p>';
					$userdata = get_user_by( 'email', $author );
					if ( ! empty( $userdata ) ) {
						$post_author = $userdata->ID;
						$author_found = true;
					}
				}
			}


			/* Date */
			$date = $headers->getValue( 'Date' );
			$dmonths = array( 'Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec' );

			// of the form '20 Mar 2002 20:32:37'
			$ddate = trim( $date );
			if ( strpos( $ddate, ',' ) ) {
				$ddate = trim( substr( $ddate, strpos( $ddate, ',' ) + 1, strlen( $ddate ) ) );
			}

			$date_arr = explode( ' ', $ddate );
			$date_time = explode( ':', $date_arr[3] );

			$ddate_H = $date_time[0];
			$ddate_i = $date_time[1];
			$ddate_s = $date_time[2];

			$ddate_m = $date_arr[1];
			$ddate_d = $date_arr[0];
			$ddate_Y = $date_arr[2];

			for ( $j = 0; $j < 12; $j++ ) {
				if ( $ddate_m == $dmonths[$j] ) {
					$ddate_m = $j+1;
				}
			}

			$time_zn = intval( $date_arr[4] ) * 36;
			$ddate_U = gmmktime( $ddate_H, $ddate_i, $ddate_s, $ddate_m, $ddate_d, $ddate_Y );
			$ddate_U = $ddate_U - $time_zn;
			$post_date = gmdate( 'Y-m-d H:i:s', $ddate_U + $time_difference );
			$post_date_gmt = gmdate( 'Y-m-d H:i:s', $ddate_U );



			/* Message body */
			$query = new Horde_Imap_Client_Fetch_Query();
			$query->structure();

			$list = $pop3->fetch( 'INBOX', $query, array(
				'ids' => $uid
			));

			$part = $list->first()->getStructure();
			$id = $part->findBody();
			$body = $part->getPart( $id );

			$query2 = new Horde_Imap_Client_Fetch_Query();
			$query2->bodyPart( $id, array(
				'decode' => true,
				'peek' => true
			));

			$list2 = $pop3->fetch( 'INBOX', $query2, array(
				'ids' => $uid
			));

			$message2 = $list2->first();
			$content = $message2->getBodyPart( $id );
			if ( ! $message2->getBodyPartDecode( $id ) ) {
				// Quick way to transfer decode contents
				$body->setContents( $content );
				$content = $body->getContents();
			}


			// Set $post_status based on $author_found and on author's publish_posts capability
			if ( $author_found ) {
				$user = new WP_User( $post_author );
				$post_status = ( $user->has_cap( 'publish_posts' ) ) ? 'publish' : 'pending';
			} else {
				// Author not found in DB, set status to pending. Author already set to admin.
				$post_status = 'pending';
			}

			$subject = trim( $subject );

			$content = strip_tags( $content, '<img><p><br><i><b><u><em><strong><strike><font><span><div>' );
			$content = trim( $content );

			//Give Post-By-Email extending plugins full access to the content
			//Either the raw content or the content of the last quoted-printable section
			$content = apply_filters( 'wp_mail_original_content', $content );

			// Captures any text in the body after $phone_delim as the body
			$content = explode( $phone_delim, $content );
			$content = empty( $content[1] ) ? $content[0] : $content[1];

			$content = trim( $content );

			$post_content = apply_filters( 'phone_content' , $content );

			$post_title = xmlrpc_getposttitle( $content );

			if ( '' == $post_title )
				$post_title = $subject;

			$post_category = array( $options['default_email_category'] );

			$post_data = compact( 'post_content','post_title','post_date','post_date_gmt','post_author','post_category', 'post_status' );
			$post_data = wp_slash( $post_data );

			$post_ID = wp_insert_post( $post_data );
			if ( is_wp_error( $post_ID ) )
				$log_message .= "\n" . $post_ID->get_error_message();

			// We couldn't post, for whatever reason. Better move forward to the next email.
			if ( empty( $post_ID ) )
				continue;

			do_action( 'publish_phone', $post_ID );

			$log_message .= "\n<p>" . sprintf( __( 'Author: %s', 'post-by-email' ), esc_html( $post_author ) ) . '</p>';
			$log_message .= "\n<p>" . sprintf( __( 'Posted title: %s', 'post-by-email' ), esc_html( $post_title ) ) . '</p>';

			self::save_log_message($log_message);

		} // end foreach

		// delete all processed emails
		try {
			$pop3->store( 'INBOX', array(
				'add' => array( Horde_Imap_Client::FLAG_DELETED ),
				'ids' => $uids
			) );
		}
		catch ( Horde_Imap_Client_Exception $e ) {
			self::save_log_message( __( 'An error occurred: ', 'post-by-email') . $e->getMessage() );
		}

		$pop3->shutdown();
	}

	/**
	 * Save a message to the log file
	 *
	 * @since    0.9.9
	 *
	 * @param    string    $error    Error message to save to the log.
	 */
	protected function save_log_message( $message ) {
		$log = get_option( 'post_by_email_log', array() );

		array_unshift( $log, array( 'timestamp'=>current_time( 'timestamp' ), 'message'=>$message ) );

		if( ! add_option( 'post_by_email_log', $log, '', 'no' ) ) {
			update_option( 'post_by_email_log', $log );
		}
	}

	/**
	 * Set the plugin include path and register the autoloader.
	 *
	 * @since 0.9.7
	 */
	public static function load() {
		self::$path = __DIR__;
		spl_autoload_register( array( get_called_class(), 'autoload' ) );
	}

	/**
	 * Autoload class libraries as needed.
	 *
	 * @since 0.9.7
	 *
	 * @param    string    $class    Class name of requested object.
	 */
	public static function autoload( $class ) {
		// We're only interested in autoloading Horde includes.
		if( 0 !== strpos( $class, 'Horde' ) ) {
			return;
		}

		// Replace all underscores in the class name with slashes.
		// ex: we expect the class Horde_Imap_Client to be defined in Horde/Imap/Client.php.
		$filename = str_replace( array( '_', '\\' ), '/', $class );
		$filename = self::$path . '/include/' . $filename . '.php';
		if( file_exists( $filename ) ) {
			require_once( $filename );
		}
	}
}