<?php
/*
Plugin Name: Content Checkup Reminder
Description: Set up email notifications for when your website page content has gone untouched for awhile.
Version: 1.0
Author: Michael Beckwith
Author URI: http://michaelbox.net
License: WTFPL
Text Domain: content-checkup
*/

class Content_Checkup {

	public function __construct() {
		add_action( 'init', array( $this, 'load_textdomain' ) );
		add_action( 'admin_menu', array( $this, 'content_checkup_add_page' ) );
		add_action( 'admin_init', array( $this, 'content_checkup_settings_init' ) );
		add_action( 'admin_init', array( $this, 'cron_reminder_init' ) );
		add_action( 'content_checkup_cron_hook', array( $this, 'cron_reminder_check' ) );
		add_action( 'admin_head', array( $this, 'content_checkup_style' ) );
	}

	/**
	 * Load our text domain for translations
	 *
	 * @uses load_plugin_textdomain()
	 *
	 * @since 1.0
	 */
	public function load_textdomain() {
		load_plugin_textdomain( 'content-checkup', '', dirname( plugin_basename( __FILE__ ) ) . '/langs/' );
	}

	/**
	 * Check the cron schedule and add or remove pending schedulings
	 *
	 * @uses get_option()
	 * @uses wp_next_scheduled()
	 * @uses wp_schedule_event()
	 * @uses wp_unschedule_event()
	 *
	 * @since 1.0
	 */
	public function cron_reminder_init() {
		//load the option value
		$options = get_option( 'content-checkup-reminder' );

		// if the option is enabled and not already scheduled lets schedule it
		if ( $options['enable'] && !wp_next_scheduled( 'content_checkup_cron_hook' ) ) {
			wp_schedule_event( time(), 'daily', 'content_checkup_cron_hook' );
		// if the option is NOT enabled and scheduled lets unschedule it
		} elseif ( !$options['enable'] && wp_next_scheduled( 'content_checkup_cron_hook' ) ) {
			//get time of next scheduled run
			$timestamp = wp_next_scheduled( 'content_checkup_cron_hook' );
			//unschedule custom action hook
			wp_unschedule_event( $timestamp, 'content_checkup_cron_hook' );
		}
	}

	/**
	 * Creates a custom hook for cron scheduling and sends reminder if time has elapsed for threshold
	 *
	 * @uses WPDB Class
	 * @uses wp_mail()
	 *
	 * @since 1.0
	 */
	public function cron_reminder_check() {
		global $wpdb;
		$options = get_option( 'content-checkup-reminder' );

		//check if we have the transient, then if we have a timeframe, and if the timeframe isn't null.
		if ( false === ( $isitsent = get_transient( 'content_checkup_sent' ) ) && $options['timeframe'] && $options['timeframe'] != '' ) {
			if ( $options['timeframe'] == '0' ) {
				return;
			} else {
				$timeframe = strtotime( '-' . absint( $options['timeframe'] ) . ' days' );
			}
			//retrieve ID, post_title, and post_modified time
			$sql = " SELECT ID as id, post_title as title, post_modified as modified FROM $wpdb->posts WHERE post_status = 'publish' AND post_type = 'page' ";
			$content = $wpdb->get_results( $wpdb->prepare( $sql ) );

			$email_to       = ( isset( $options['email'] ) ) ? $options['email'] : get_option( 'admin_email' );
			$email_subject  = ( isset( $options['email_subject'] ) ) ? $options['email_subject'] : __( 'Content Checkup Reminder', 'content-checkup' );
			$email_msg      = ( isset( $options['email_msg'] ) ) ? $options['email_msg'] : __( 'Check on your website content. Make sure it is all still accurate and up-to-date. The following pages could use a check:', 'content-checkup' );

			foreach ( $content as $page ) {
				if ( strtotime( $page->post_modified ) <= $timeframe ) {
					$email_msg .= '<p><a href="' . get_permalink( $page->ID ) . '">' . $page->post_title . '</a></p>';
				}
			}
			$sent = wp_mail( $email_to, $email_subject, $email_msg );

			$time = ( $options['timeframe'] * 24 * 60 * 60 ) - ( 24 * 60 * 60 );
			set_transient( 'content_checkup_sent', true, $time );
		}

		return;
	}

	/**
	 * Add our options page
	 *
	 * @uses add_options_page()
	 *
	 * @since 1.0
	 */
	public function content_checkup_add_page() {
		global $content_checkup_hook;
		$content_checkup_hook = add_options_page( __( 'Content Checkup Reminder Settings', 'content-checkup' ), __( 'Content Checkup Reminder Settings', 'content-checkup' ), 'manage_options', 'content_checkup_options', array( $this, 'content_checkup_do_page' ) );
	}

	/**
	 * Render our options page
	 *
	 * @uses settings_fields()
	 * @uses do_settings_sections()
	 *
	 * @since 1.0
	 */
	public function content_checkup_do_page() { ?>
		<div class="wrap">
			<?php screen_icon();?><h2><?php _e( 'Content Checkup Reminder Settings', 'content-checkup' ); ?></h2>
			<form action="options.php" method="post">
			<?php
			settings_fields( 'content-checkup-reminder' );
			do_settings_sections( 'content_checkup_do_options' );
			?>
			<?php submit_button(); ?>
			</form>
		</div>
	<?php }

	/**
	 * Add some text explaining the page. Appears before the fields.
	 *
	 * @since 1.0
	 */
	public function content_checkup_do_section() {
		echo '<p>' . __( 'Here you can enable or disable the plugin completely and set an amount of days you want to elapse before you start getting notified. Days can be up to 30 total.', 'content-checkup' ) . '</p>';
	}

	/**
	 * Register our settings and add our form fields
	 *
	 * @uses register_setting()
	 * @uses add_settings_section()
	 * @uses add_settings_field()
	 *
	 * @since 1.0
	 */
	public function content_checkup_settings_init() {
		//content-checkup-reminder needs to match the array provided in the name value of add_settings_field.
		register_setting( 'content-checkup-reminder', 'content-checkup-reminder', array( $this, 'content_checkup_validate' ) );
		$options = get_option( 'content-checkup-reminder' );

		add_settings_section( 'content_checkup_settings', __( 'Content Checkup Reminder', 'content-checkup' ), array( $this, 'content_checkup_do_section' ), 'content_checkup_do_options' );
		add_settings_field( 'content_checkup_enable', '<label for="content_checkup_enable">' . __( 'Enable Content Reminder', 'content-checkup' ) . '</label>', array( $this, 'content_checkup_input_fields' ), 'content_checkup_do_options', 'content_checkup_settings', array(
			'id' => 'content_checkup_enable',
			'type' => 'checkbox',
			'name' => 'content-checkup-reminder[enable]',
			'value' => $options['enable'] ) );
		add_settings_field( 'content_checkup_crontimeframe', '<label for="content_checkup_crontimeframe">' . __( 'Amount of days between notifications?', 'content-checkup' ) . '</label>', array( $this, 'content_checkup_input_fields' ), 'content_checkup_do_options', 'content_checkup_settings', array(
			'class' => 'short-text',
			'id' => 'content_checkup_crontimeframe',
			'type' => 'text',
			'name' => 'content-checkup-reminder[timeframe]',
			'value' => $options['timeframe'],
			'description' => 'value between 1 and 30' ) );
		add_settings_field( 'content_checkup_email', '<label for="content_checkup_email">' . __( 'Email to send notification to?', 'content-checkup' ) . '</label>', array( $this, 'content_checkup_input_fields' ), 'content_checkup_do_options', 'content_checkup_settings', array(
			'class' => 'short-text',
			'id' => 'content_checkup_email',
			'type' => 'email',
			'name' => 'content-checkup-reminder[email]',
			'value' => $options['email'],
			'description' => 'email@domain.com' ) );
		add_settings_field( 'content_checkup_subject', '<label for="content_checkup_subject">' . __( 'Subject for the email?', 'content-checkup' ) . '</label>', array( $this, 'content_checkup_input_fields' ), 'content_checkup_do_options', 'content_checkup_settings', array(
			'class' => 'short-text',
			'id' => 'content_checkup_subject',
			'type' => 'text',
			'name' => 'content-checkup-reminder[email_subject]',
			'value' => $options['email_subject'],
			'description' => 'Content check reminder' ) );
		add_settings_field( 'content_checkup_msg', '<label for="content_checkup_msg">' . __( 'Message for the email?', 'content-checkup' ) . '</label>', array( $this, 'content_checkup_input_fields' ), 'content_checkup_do_options', 'content_checkup_settings', array(
			'class' => 'large-text',
			'id' => 'content_checkup_msg',
			'type' => 'textarea',
			'name' => 'content-checkup-reminder[email_msg]',
			'value' => $options['email_msg'],
			'description' => 'Go update your content' ) );
	}

	/**
	 * Used to construct settings page input fields, depending on type passed in
	 *
	 * @param $args Array of attributes and values to display with the input field. Passed in via add_settings_field()
	 *
	 * @since 1.0
	 */
	function content_checkup_input_fields( $args ) {
		extract( wp_parse_args( $args, array(
			'class' => null,
			'id' => null,
			'type' => null,
			'name' => null,
			'value' => '',
			'description' => null
		) ) );
		switch( $type ) {
			case 'checkbox':
				echo '<input id="'. esc_attr( $id ) . '" name="'. esc_attr( $name ) . '" type="' . $type . '" value="true" ' . checked( $value, true, false ) . ' />';
				break;
			case 'text':
				echo '<input type="' . $type . '" class="' . esc_attr( $class ) . '" id="'. esc_attr( $id ) . '" name="'. esc_attr( $name ) . '" placeholder="' . esc_attr( $description ) . '" value="' . esc_attr( $value ) . '" />';
				break;
			case 'email':
				echo '<input type="' . $type . '" class="' . esc_attr( $class ) . '" id="'. esc_attr( $id ) . '" name="'. esc_attr( $name ) . '" placeholder="' . esc_attr( $description ) . '" value="' . esc_attr( $value ) . '" />';
				break;
			case 'textarea':
				echo '<textarea class="' . esc_attr( $class ) . '" id="'. esc_attr( $id ) . '" name="'. esc_attr( $name ) . '" placeholder="' . esc_attr( $description ) . '">' . esc_attr( $value ) . '</textarea>';
				break;
			default:
				echo '<input type="text" class="' . esc_attr( $class ) . '" id="'. esc_attr( $id ) . '" name="'. esc_attr( $name ) . '" placeholder="' . esc_attr( $description ) . '" value="' . esc_attr( $value ) . '" />';
		}
	}

	/**
	 * Validate our settings fields
	 *
	 * @uses add_settings_error()
	 *
	 * @param $input Array being validated.
	 *
	 * @since 1.0
	 */
	function content_checkup_validate( $input ) {
		print_r($input);
		if ( empty( $input ) ) {
			add_settings_error( 'noinput', 'content_checkup_no_input', __( 'No inputs were provided to validate', 'content-checkup' ), 'error' );
		}
		$newinput['enable'] = ( isset( $input['enable'] ) && true == $input['enable'] ? true : false );
		if ( $input['timeframe'] && !preg_match( '/^0*(30|[1-9]\d{0,2})$/', $input['timeframe'] ) ) {
			add_settings_error( 'timeframe', 'content_checkup_timeframe_error', __( 'Please enter a number between 1 and 30 for the "Amount of days" value.', 'content-checkup' ), 'error' );
		} else {
			$newinput['timeframe'] = sanitize_text_field( $input['timeframe'] );
		}

		if ( $input['email'] && !is_email( $input['email'] ) ) {
			add_settings_error( 'email', 'content_checkup_email_error', __( 'Please enter a valid email address', 'content-checkup' ), 'error' );
		} else {
			$newinput['email'] = sanitize_text_field( $input['email'] );
		}

		$newinput['email_subject'] = sanitize_text_field( $input['email_subject'] );
		$newinput['email_msg'] = sanitize_text_field( $input['email_msg'] );

		return $newinput;
	}

	/**
	 * Adds quick styles for placeholder text color
	 *
	 * @since 1.0
	 */
	function content_checkup_style() {
		if ( isset( $_GET['page'] ) && 'content_checkup_options' == $_GET['page'] ) {
		//Styling touchup. Mostly placeholder font color.
		echo '<style> .form-table ::-webkit-input-placeholder { color: rgb(160, 160, 160); } .form-table :-moz-placeholder { color: rgb(160, 160, 160); }</style>';
		}
	}

}
// Have a nice day!
$turn_and_cough = new Content_Checkup;
