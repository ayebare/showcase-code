<?php

namespace Piccolo\Settings;

/**
 * Keyring's screen settings class.
 *
 * This class is responsible for:
 *  - Rendering the screen where a keyring Google mail connection token is chosen.
 *  - Processing token generating token requests.
 */
class KeyringApiScreen {

	/**
	 * Nonce action for the token form
	 *
	 * @var string
	 */
	const NONCE_ACTION = 'get_gmail_token_nonce-action';

	/**
	 * Nonce field name for the token form
	 *
	 * @var string
	 */
	const NONCE_NAME = 'get_gmail_token_nonce-field';

	/**
	 * Constructor
	 *
	 * Use PHP dependency injection to expose the APISupport methods to this class.
	 *
	 * @since 0.0.1
	 *
	 * @param \Piccolo\Gmail\Keyring\APISupport $keyring_support
	 */
	public function __construct( $keyring_support ) {
		$this->keyring_support = $keyring_support;
	}

	/**
	 * Register the page with appropriate WP hooks.
	 *
	 * @since 0.0.1
	 *
	 * @return void
	 */
	public function init() {
		add_action( 'admin_init', array( $this, 'configure' ) );
		add_action( 'admin_menu', array( $this, 'add_admin_page' ) );
		add_action( 'admin_post_save_keyring_token', array( $this, 'handle_token_requests' ) );
	}

	/**
	 * Configure the options page.
	 */
	public function configure() {
		// Register settings.
		$this->add_setting_fields();
	}

	/**
	 * Add settings field
	 *
	 * @since 0.0.1
	 *
	 * @return void
	 */
	public function add_setting_fields() {
		$section = $page = $this->get_menu_slug();

		add_settings_field(
			'keyring_api_token',
			__( 'Auto Share', 'piccolo' ),
			array( $this, 'render_auto_share_field' ),
			$page,
			$section
		);
	}

	/**
	 * Get the page menu slug
	 *
	 * @since 0.0.1
	 *
	 * @return string $slug
	 */
	protected function get_menu_slug() {
		return 'google_mail_authentication';
	}

	/**
	 * Adds the admin page to the menu.
	 *
	 * @since 0.0.1
	 *
	 * @return void
	 */
	public function add_admin_page() {
		$page_title = $this->get_page_title();
		$menu_title = $this->get_menu_title();
		$menu_slug  = $this->get_menu_slug();

		add_submenu_page(
			'piccolo',
			$page_title,
			$menu_title,
			'install_plugins',
			$menu_slug,
			array(
				$this,
				'render_page',
			)
		);
	}

	/**
	 * Get the page title
	 *
	 * @since 0.0.1
	 *
	 * @return string $title
	 */
	public function get_page_title() {
		return esc_html__( 'Google Mail Authentication', 'piccolo' );
	}

	/**
	 * Get the menu title.
	 *
	 * @since 0.0.1
	 *
	 * @return string $title
	 */
	public function get_menu_title() {
		return esc_html__( 'Gmail Settings', 'piccolo' );
	}

	/**
	 * Render options page.
	 *
	 * @since 0.0.1
	 *
	 * @return void
	 */
	public function render_page() {
		$service = \Keyring::get_service_by_name( PICCOLO_GMAIL_KEYRING_SLUG );
		?>
		<div class="wrap">
			<h1><?php echo esc_html( $this->get_page_title() ); ?></h1>
			<?php
			if ( ! $service ) {
				$this->render_no_keyring_service_html();

				return;
			} elseif ( ! $service->is_configured() ) {
				$this->render_configure_keyring_html();

				return;
			}

			$this->render_token_selection_form( $service );
			?>
		</div>
		<?php
	}

	/**
	 * Render the Access token field
	 *
	 * @since 0.0.1
	 *
	 * @return void
	 */
	public function render_no_keyring_service_html() {
		?>
		<p class="error"><?php echo esc_html__( 'It looks like you don\'t have the Google Mail service for Keyring installed.', 'piccolo' ); ?></p>
		<?php
	}

	/**
	 * Render the Access token field
	 *
	 * @since 0.0.1
	 *
	 * @return void
	 */
	public function render_configure_keyring_html() {
		?>
		<p class="error"><?php echo esc_html__( 'Before you can use the Google email importer, you need to configure the Googlel Mail service within Keyring.', 'piccolo' ); ?></p>
		<?php

		/**
		 * Add configure Google Mail configuration Service button if:
		 * - Keyring is not configured for headless mode: In headless there's nowhere (known) to link to
		 * - The service has a UI to link to
		 * - The user has the relevant permission to connect to the service
		 */
		if ( current_user_can( 'install_plugins' ) && ! KEYRING__HEADLESS_MODE && has_action( 'keyring_' . PICCOLO_GMAIL_KEYRING_SLUG . '_manage_ui' ) ) {
			$manage_kr_nonce = wp_create_nonce( 'keyring-manage' );
			$manage_nonce    = wp_create_nonce( 'keyring-manage-' . PICCOLO_GMAIL_KEYRING_SLUG );
			echo '<p><a href="' . esc_url(
				\Keyring_Util::admin_url(
					PICCOLO_GMAIL_KEYRING_SLUG,
					array(
						'action'   => 'manage',
						'kr_nonce' => $manage_kr_nonce,
						'nonce'    => $manage_nonce,
					)
				)
			) . '" class="button-primary">' . esc_html__( 'Configure Google Mail Service', 'piccolo' ) . '</a></p>';
		}
	}

	/**
	 * Render the Access token field
	 *
	 * @since 0.0.1
	 *
	 * @param $service
	 */
	public function render_token_selection_form( $service ) {
		?>
		<div class="narrow">
			<form action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" method="post">
				<?php if ( $service->is_connected() ) : ?>
					<?php if ( $this->keyring_support->is_connected ) : ?>
						<?php /* translators: %s: Keyring plugin name and link. */ ?>
						<p>
						<?php
						echo sprintf(
							wp_kses(
								__( '<strong>You are connected</strong> to Google Mail via %s', 'piccolo' ),
								array( 'strong' => array() )
							),
							'<a href="' . esc_url( \Keyring_Util::admin_url() ) . '">Keyring</a>'
						);
						?>
							</p>
					<?php else : ?>
						<?php /* translators: %s: Keyring plugin name and link. */ ?>
						<p><?php echo sprintf( esc_html__( 'It looks like you have created a connection(s) to Google Mail via %s. Please select an existing connection, or create a new one a new one and click continue.', 'piccolo' ), '<a href="' . esc_url( \Keyring_Util::admin_url() ) . '">Keyring</a>' ); ?></p>
					<?php endif; ?>

					<?php $service->token_select_box( PICCOLO_GMAIL_KEYRING_SLUG . '_token', true ); ?>
					<input type="submit" name="connect_existing"
						   value="<?php echo esc_attr( esc_html__( 'Continue&hellip;', 'piccolo' ) ); ?>"
						   id="connect_existing"
						   class="button-primary"/>
				<?php else : ?>
					<p><?php echo esc_html__( 'To get started, we\'ll need to connect to your Google Mail account so that we can access your content.', 'piccolo' ); ?></p>
					<input type="submit" name="create_new"
						   value="<?php echo esc_attr( esc_html__( 'Connect to Google Mail', 'piccolo' ) ); ?>"
						   id="create_new" class="button-primary"/>
				<?php endif; ?>
				<?php wp_nonce_field( self::NONCE_ACTION, self::NONCE_NAME ); ?>
				<input type="hidden" name="action" value="save_keyring_token">
			</form>
		</div>
		<?php
	}

	/**
	 * Handle validation and sanitization of requests.
	 *
	 * These processes are:
	 * - Check if user has permission to save the teams chart data.
	 * - Save the chart data
	 * - Regenerate the sharing URL
	 * - Redirect the user to the settings page.
	 *
	 * @action admin_post_save_keyring_token
	 * @since 0.0.1
	 *
	 * @return void
	 */
	public function handle_token_requests() {
		// If the user tries to save without the appropriate permissions, redirect to settings page.
		if ( ! $this->can_save() ) {
			wp_die( 'Sorry, you are not allowed to perform this action.' );
		}

		// If a request is made for a new connection, pass it off to Keyring.
		if ( isset( $_POST['create_new'] ) ) {
			$this->redirect_to_service_setup();
		}

		if ( isset( $_REQUEST[ PICCOLO_GMAIL_KEYRING_SLUG . '_token' ] ) && 'new' === $_REQUEST[ PICCOLO_GMAIL_KEYRING_SLUG . '_token' ] ) {
			$this->redirect_to_service_setup();
		}

		// If a token is present, save it.
		if ( ! empty( $_REQUEST[ PICCOLO_GMAIL_KEYRING_SLUG . '_token' ] ) ) {
			$this->keyring_support->set_option( 'token_id', absint( $_REQUEST[ PICCOLO_GMAIL_KEYRING_SLUG . '_token' ] ) );
		}

		// Redirect to settings page
		$this->redirect_to_settings_page( 'success' );
	}

	/**
	 * Check if the current user has authority to process forms on the page.
	 *
	 * The method checks the user permissions and also verifies the nonce to prevent malicious attacks.
	 *
	 * @since 0.0.1
	 *
	 * @return bool true if the user can process form data, false otherwise.
	 */
	public function can_save() {
		if ( ! current_user_can( 'install_plugins' ) ) {
			return false;
		}

		if ( ! $this->is_correct_nonce() ) {
			return false;
		}

		return true;
	}

	/**
	 * Determine if the submitted form nonce is correct.
	 *
	 * @since 0.0.1
	 *
	 * @return bool true if the user can process form data, false otherwise.
	 */
	public function is_correct_nonce() {
		if ( ! isset( $_POST[ self::NONCE_NAME ] ) ) {
			return false;
		}

		if ( ! wp_verify_nonce( $_POST[ self::NONCE_NAME ], self::NONCE_ACTION ) ) {
			return false;
		}

		return true;
	}

	/**
	 * Redirects a user to the plugin chart page with an optional updated status in the parameter arguments.
	 *
	 * Set $status to success to add a success argument. Any other value will add
	 * an error argument to the redirect URL.
	 *
	 * For some situations, the developer may not wish to add a message parameter to the redirect URL at all,
	 * In that case, the $status argument should be set to null or left empty to use the default.
	 *
	 * @global $_POST
	 *
	 * @param string $status (optional) see documentation above for usage.
	 *
	 * @return void
	 */
	public function redirect_to_settings_page( $status = null ) {

		$query_args = array();

		if ( 'success' === $status ) {
			$query_args[] = array( 'updated' => 'true' );
		}

		wp_safe_redirect(
			add_query_arg(
				$query_args,
				admin_url( 'admin.php?page=google_mail_authentication' )
			)
		);

		exit();
	}

	/**
	 * Redirect into Keyring's auth handler.
	 *
	 * @since 0.0.1
	 *
	 * @return void
	 */
	public function redirect_to_service_setup() {
		\Keyring_Util::connect_to( PICCOLO_GMAIL_KEYRING_SLUG, 'keyring-' . PICCOLO_GMAIL_KEYRING_SLUG . '-importer' );
		exit;
	}

}
