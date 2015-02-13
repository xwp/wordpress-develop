<?php
/**
 * Customize Manager.
 *
 * Bootstraps the Customize experience on the server-side.
 *
 * Sets up the theme-switching process if a theme other than the active one is
 * being previewed and customized.
 *
 * Serves as a factory for Customize Controls and Settings, and
 * instantiates default Customize Controls and Settings.
 *
 * @package WordPress
 * @subpackage Customize
 * @since 3.4.0
 */
final class WP_Customize_Manager {

	/**
	 * An instance of the theme being previewed.
	 *
	 * @var WP_Theme
	 */
	protected $theme;

	/**
	 * The directory name of the previously active theme (within the theme_root).
	 *
	 * @var string
	 */
	protected $original_stylesheet;

	/**
	 * Whether this is a Customizer pageload.
	 *
	 * @var boolean
	 */
	protected $previewing = false;

	/**
	 * Methods and properties deailing with managing widgets in the Customizer.
	 *
	 * @var WP_Customize_Widgets
	 */
	public $widgets;

	/**
	 * @var WP_Customize_Setting[]
	 */
	protected $settings = array();

	/**
	 * @var array
	 */
	protected $containers = array();

	/**
	 * @var WP_Customize_Panel[]
	 */
	protected $panels = array();

	/**
	 * @var WP_Customize_Section[]
	 */
	protected $sections = array();

	/**
	 * @var WP_Customize_Control[]
	 */
	protected $controls = array();

	/**
	 * @var string
	 */
	protected $messenger_channel;

	/**
	 * @var WP_Customize_Transaction
	 */
	public $transaction;

	/**
	 * JSON-decoded value $_POST['customized'] if present in request.
	 *
	 * Used by WP_Customize_Manager::update_transaction().
	 *
	 * @var array|null
	 */
	public $post_data;

	/**
	 * Controls that may be rendered from JS templates.
	 *
	 * @since 4.1.0
	 * @access protected
	 * @var WP_Customize_Control[]
	 */
	protected $registered_control_types = array();

	/**
	 * Constructor.
	 *
	 * @since 3.4.0
	 * @since 4.2.0  Added $transaction_uuid param.
	 *
	 * @param string $transaction_uuid
	 */
	public function __construct( $transaction_uuid = null ) {
		require_once( ABSPATH . WPINC . '/class-wp-customize-transaction.php' );
		require_once( ABSPATH . WPINC . '/class-wp-customize-setting.php' );
		require_once( ABSPATH . WPINC . '/class-wp-customize-panel.php' );
		require_once( ABSPATH . WPINC . '/class-wp-customize-section.php' );
		require_once( ABSPATH . WPINC . '/class-wp-customize-control.php' );
		require_once( ABSPATH . WPINC . '/class-wp-customize-widgets.php' );

		if ( isset( $_REQUEST['customize_messenger_channel'] ) ) {
			// @todo Is this needed anymore?
			$this->messenger_channel = wp_unslash( $_REQUEST['customize_messenger_channel'] );
		}

		if ( ! did_action( 'setup_theme' ) ) {
			add_action( 'setup_theme', array( $this, 'store_post_data' ), 0 ); // note that WP_Customize_Transaction::populate_customized_post_var() happens next at priority 1
		} else {
			$this->store_post_data();
		}
		$this->transaction = new WP_Customize_Transaction( $this, $transaction_uuid );
		$this->widgets = new WP_Customize_Widgets( $this );

		add_filter( 'wp_die_handler', array( $this, 'wp_die_handler' ) );

		add_action( 'setup_theme',  array( $this, 'setup_theme' ) );
		add_action( 'wp_loaded',    array( $this, 'wp_loaded' ) );

		// Do not spawn cron (especially the alternate cron) while running the Customizer.
		remove_action( 'init', 'wp_cron' );

		// Do not run update checks when rendering the controls.
		remove_action( 'admin_init', '_maybe_update_core' );
		remove_action( 'admin_init', '_maybe_update_plugins' );
		remove_action( 'admin_init', '_maybe_update_themes' );

		add_action( 'wp_ajax_customize_update_transaction', array( $this, 'update_transaction' ) );
		add_action( 'wp_ajax_customize_save', array( $this, 'save' ) );

		add_action( 'customize_register',                 array( $this, 'register_controls' ) );
		add_action( 'customize_register',                 array( $this, 'register_dynamic_settings' ), 11 ); // allow code to create settings first
		add_action( 'customize_controls_init',            array( $this, 'prepare_controls' ) );
		add_action( 'customize_controls_enqueue_scripts', array( $this, 'enqueue_control_scripts' ) );
	}

	/**
	 * Return true if it's an AJAX request, optionally specified.
	 *
	 * @since 3.4.0
	 * @since 4.2.0 Added $action param.
	 *
	 * @param string|null $action whether the supplied Ajax action is being run.
	 * @return bool
	 */
	public function doing_ajax( $action = null ) {
		$doing_ajax = ( defined( 'DOING_AJAX' ) && DOING_AJAX );
		if ( ! $doing_ajax ) {
			return false;
		}

		if ( ! $action ) {
			return true;
		} else {
			// Note: we can't just use doing_action( "wp_ajax_{$action}" ) because we need to check before admin-ajax.php gets to that point
			return isset( $_REQUEST['action'] ) && wp_unslash( $_REQUEST['action'] ) === $action;
		}
	}

	/**
	 * Whether the URL of the preview is different than the domain of the admin.
	 *
	 * @since 4.2.0
	 *
	 * @return bool
	 */
	public function is_cross_domain() {
		$admin_origin = parse_url( admin_url() );
		$home_origin  = parse_url( home_url() );
		$cross_domain = ( strtolower( $admin_origin['host'] ) !== strtolower( $home_origin['host'] ) );
		return $cross_domain;
	}

	/**
	 * Get the URLs that are previewable, those which are allowed to be navigated to within the Preview.
	 *
	 * If the frontend and the admin are served from the same domain, load the
	 * preview over ssl if the Customizer is being loaded over ssl. This avoids
	 * insecure content warnings. This is not attempted if the admin and frontend
	 * are on different domains to avoid the case where the frontend doesn't have
	 * ssl certs. Domain mapping plugins can allow other urls in these conditions
	 * using the customize_allowed_urls filter.
	 *
	 * @since 4.2.0
	 *
	 * @return array
	 */
	public function get_allowed_urls() {
		$allowed_urls = array( home_url( '/' ) );

		if ( is_ssl() && ! $this->is_cross_domain() ) {
			$allowed_urls[] = home_url( '/', 'https' );
		}

		/**
		 * Filter the list of URLs allowed to be clicked and followed in the Customizer preview.
		 *
		 * @since 3.4.0
		 *
		 * @param array $allowed_urls An array of allowed URLs.
		 */
		$allowed_urls = array_unique( apply_filters( 'customize_allowed_urls', $allowed_urls ) );

		return $allowed_urls;
	}

	/**
	 * Get the fallback URL for the Customizer.
	 *
	 * @since 4.2.0
	 *
	 * @return string
	 */
	public function get_fallback_url( ){
		$fallback_url = add_query_arg( array(
			'preview'        => 1,
			'template'       => $this->get_template(),
			'stylesheet'     => $this->get_stylesheet(),
			'preview_iframe' => true,
			'TB_iframe'      => 'true',
		), home_url( '/' ) );
		return $fallback_url;
	}

	/**
	 * Custom wp_die wrapper. Returns either the standard message for UI
	 * or the AJAX message.
	 *
	 * @since 3.4.0
	 *
	 * @param mixed $ajax_message AJAX return
	 * @param mixed $message UI message
	 */
	protected function wp_die( $ajax_message, $message = null ) {
		if ( $this->doing_ajax() || isset( $_POST['customized'] ) ) {
			wp_die( $ajax_message );
		}

		if ( ! $message ) {
			$message = __( 'Cheatin&#8217; uh?' );
		}

		wp_die( $message );
	}

	/**
	 * Output JSON which gets passed to a parent window.
	 *
	 * @param mixed $error
	 */
	protected function wp_send_json_error( $error ) {
		if ( empty( $this->messenger_channel ) ) {
			wp_send_json_error( $error );
		} else {
			header( 'Content-Type: text/html; charset=' . get_option( 'blog_charset' ) );
			echo '<!DOCTYPE html><html>';
			wp_print_scripts( array( 'customize-preview' ) );
			$this->customize_preview_settings( compact( 'error' ) );
			echo '</html>';
			die();
		}
	}

	/**
	 * Return the AJAX wp_die() handler if it's a customized request.
	 *
	 * @since 3.4.0
	 *
	 * @return string
	 */
	public function wp_die_handler() {
		if ( $this->doing_ajax() || isset( $_POST['customized'] ) ) {
			return '_ajax_wp_die_handler';
		} else {
			return '_default_wp_die_handler'; // @todo Why not pass-through the func_get_arg( 0 )?
		}
	}

	/**
	 * Return the AJAX wp_die() handler if it's a customized request.
	 *
	 * @since 4.2.0
	 *
	 * @return string
	 */
	public function filter_preview_wp_die_handler() {
		return array( $this, 'preview_wp_die_handler' );
	}

	/**
	 * Send info back to the Customizer in the case of a failure.
	 *
	 * @param string|WP_Error  $message
	 * @param string|int       $title
	 * @param string|array|int $args
	 *
	 * @since 4.2.0
	 */
	public function preview_wp_die_handler( $message, $title, $args ) {
		ob_end_clean();
		$status = 500;
		if ( ! empty( $args['response'] ) ) {
			$status = $args['response'];
		}
		status_header( $status );
		$data = compact( 'message', 'title', 'args' );
		$this->wp_send_json_error( $data );
	}

	/**
	 * Decode and store any initial $_POST['customized'] data.
	 *
	 * The value is used by WP_Customize_Manager::update_transaction().
	 *
	 * @since 4.2.0
	 */
	public function store_post_data() {
		if ( isset( $_POST['customized'] ) ) {
			$this->post_data = json_decode( wp_unslash( $_POST['customized'] ), true );
		}
	}

	/**
	 * Start preview and customize theme.
	 *
	 * Check if customize query variable exist. Init filters to filter the current theme.
	 *
	 * @since 3.4.0
	 */
	public function setup_theme() {
		send_origin_headers(); // @todo Is this necessary anymore?

		$doing_ajax_or_is_customized = ( $this->doing_ajax() || isset( $_POST['customized'] ) );
		// @todo Isn't this redundant?
		if ( is_admin() && ! $doing_ajax_or_is_customized ) {
			auth_redirect();
		} elseif ( $doing_ajax_or_is_customized && ! is_user_logged_in() ) {
			$this->wp_die( 0 );
		}

		/**
		 * Allow anonymous access to Customizer preview to be configurable.
		 *
		 * Note that if no extant transaction has been specified in the request
		 * then a cheatin' message will be shown regardless. Note that this filter
		 * must be added in plugin because it gets applied in the setup_theme
		 * action which is done before a theme is loaded.
		 *
		 * @since 4.2.0
		 *
		 * @param bool $anonymous_access_allowed
		 */
		$anonymous_access_allowed = apply_filters( 'customize_preview_anonymous_access_allowed', true );

		/*
		 * Only unauthenticated users to access the Customize preview if there is
		 * a valid transaction loaded, i.e. if customize_transaction_uuid was supplied
		 * and it corresponds to a customize_transact post in the database.
		 */
		if ( ! is_admin() && ! is_user_logged_in() && ( ! $anonymous_access_allowed || ! $this->transaction->post() ) ) {
			$this->wp_die( -1 );
		}

		// Hide the admin bar if we're embedded in the Customizer iframe
		if ( $this->messenger_channel ) {
			show_admin_bar( false );
		}

		if ( ! current_user_can( 'customize' ) ) { // @todo Only do this if ! $anonymous_access_allowed
			$this->wp_die( -1 );
		}

		$this->original_stylesheet = get_stylesheet();

		$this->theme = wp_get_theme( isset( $_REQUEST['theme'] ) ? $_REQUEST['theme'] : null );

		if ( $this->is_theme_active() ) {
			// Once the theme is loaded, we'll validate it.
			add_action( 'after_setup_theme', array( $this, 'after_setup_theme' ) );
		} else {
			// If the requested theme is not the active theme and the user doesn't have the
			// switch_themes cap, bail.
			if ( ! current_user_can( 'switch_themes' ) ) {
				$this->wp_die( -1 );
			}

			// If the theme has errors while loading, bail.
			if ( $this->theme()->errors() ) {
				$this->wp_die( -1 );
			}

			// If the theme isn't allowed per multisite settings, bail.
			if ( ! $this->theme()->is_allowed() ) {
				$this->wp_die( -1 );
			}
		}

		/*
		 * Now that Customizer previews are loaded into iframes via GET requests
		 * and natural URLs with transaction UUIDs added, we need to ensure that
		 * the responses are never cached by proxies. In practice, this will not
		 * be needed if the user is logged-in anyway. But if $anonymous_access_allowed
		 * then the auth cookies would not be sent and WordPress would not send
		 * no-cache headers by default.
		 */
		nocache_headers();
		$this->start_previewing_theme();
	}

	/**
	 * Callback to validate a theme once it is loaded
	 *
	 * @since 3.4.0
	 */
	public function after_setup_theme() {
		$doing_ajax_or_is_customized = ( $this->doing_ajax() || isset( $_SERVER['customized'] ) );
		if ( ! $doing_ajax_or_is_customized && ! validate_current_theme() ) {
			wp_redirect( 'themes.php?broken=true' );
			exit;
		}
	}

	/**
	 * If the theme to be previewed isn't the active theme, add filter callbacks
	 * to swap it out at runtime.
	 *
	 * @since 3.4.0
	 */
	public function start_previewing_theme() {
		// Bail if we're already previewing.
		if ( $this->is_preview() ) {
			return;
		}

		$this->previewing = true;

		if ( ! $this->is_theme_active() ) {
			add_filter( 'template', array( $this, 'get_template' ) );
			add_filter( 'stylesheet', array( $this, 'get_stylesheet' ) );
			add_filter( 'pre_option_current_theme', array( $this, 'current_theme' ) );

			// @link: https://core.trac.wordpress.org/ticket/20027
			add_filter( 'pre_option_stylesheet', array( $this, 'get_stylesheet' ) );
			add_filter( 'pre_option_template', array( $this, 'get_template' ) );

			// Handle custom theme roots.
			add_filter( 'pre_option_stylesheet_root', array( $this, 'get_stylesheet_root' ) );
			add_filter( 'pre_option_template_root', array( $this, 'get_template_root' ) );
		}

		/**
		 * Fires once the Customizer theme preview has started.
		 *
		 * @since 3.4.0
		 *
		 * @param WP_Customize_Manager $this WP_Customize_Manager instance.
		 */
		do_action( 'start_previewing_theme', $this );
	}

	/**
	 * Stop previewing the selected theme.
	 *
	 * Removes filters to change the current theme.
	 *
	 * @since 3.4.0
	 */
	public function stop_previewing_theme() {
		if ( ! $this->is_preview() ) {
			return;
		}

		$this->previewing = false;

		if ( ! $this->is_theme_active() ) {
			remove_filter( 'template', array( $this, 'get_template' ) );
			remove_filter( 'stylesheet', array( $this, 'get_stylesheet' ) );
			remove_filter( 'pre_option_current_theme', array( $this, 'current_theme' ) );

			// @link: https://core.trac.wordpress.org/ticket/20027
			remove_filter( 'pre_option_stylesheet', array( $this, 'get_stylesheet' ) );
			remove_filter( 'pre_option_template', array( $this, 'get_template' ) );

			// Handle custom theme roots.
			remove_filter( 'pre_option_stylesheet_root', array( $this, 'get_stylesheet_root' ) );
			remove_filter( 'pre_option_template_root', array( $this, 'get_template_root' ) );
		}

		/**
		 * Fires once the Customizer theme preview has stopped.
		 *
		 * @since 3.4.0
		 *
		 * @param WP_Customize_Manager $this WP_Customize_Manager instance.
		 */
		do_action( 'stop_previewing_theme', $this );
	}

	/**
	 * Get the theme being customized.
	 *
	 * @since 3.4.0
	 *
	 * @return WP_Theme
	 */
	public function theme() {
		return $this->theme;
	}

	/**
	 * Get the registered settings.
	 *
	 * @since 3.4.0
	 *
	 * @return WP_Customize_Setting[]
	 */
	public function settings() {
		return $this->settings;
	}

	/**
	 * Get the registered controls.
	 *
	 * @since 3.4.0
	 *
	 * @return WP_Customize_Control[]
	 */
	public function controls() {
		return $this->controls;
	}

	/**
	 * Get the registered containers.
	 *
	 * @since 4.0.0
	 *
	 * @return array
	 */
	public function containers() {
		return $this->containers;
	}

	/**
	 * Get the registered sections.
	 *
	 * @since 3.4.0
	 *
	 * @return WP_Customize_Section[]
	 */
	public function sections() {
		return $this->sections;
	}

	/**
	 * Get the registered panels.
	 *
	 * @since 4.0.0
	 * @access public
	 *
	 * @return WP_Customize_Panel[]
	 */
	public function panels() {
		return $this->panels;
	}

	/**
	 * Checks if the current theme is active.
	 *
	 * @since 3.4.0
	 *
	 * @return bool
	 */
	public function is_theme_active() {
		return $this->get_stylesheet() == $this->original_stylesheet;
	}

	/**
	 * Register styles/scripts and initialize the preview of each setting
	 *
	 * @since 3.4.0
	 */
	public function wp_loaded() {

		/**
		 * Fires once WordPress has loaded, allowing scripts and styles to be initialized.
		 *
		 * @since 3.4.0
		 *
		 * @param WP_Customize_Manager $this WP_Customize_Manager instance.
		 */
		do_action( 'customize_register', $this );

		/*
		 * Note that we need to preview the settings outside the Customizer preview
		 * and in the Customizer pane itself so that loading a previous transaction
		 * into the Customizer. We have to prevent the previews from being added
		 * in the case of a customize_save action because then update_option()
		 * may short-circuit because it will detect that there are no changes to
		 * make.
		 */
		if ( ! $this->doing_ajax( 'customize_save' ) ) {
			foreach ( $this->settings as $setting ) {
				$setting->preview();
			}
		}

		if ( $this->is_preview() && ! is_admin() ) {
			$this->customize_preview_init();
		}
	}

	/**
	 * Parse the incoming $_POST['customized'] JSON data and store the unsanitized
	 * settings for subsequent post_value() lookups.
	 *
	 * @since 4.1.1
	 *
	 * @deprecated
	 *
	 * @return array
	 */
	public function unsanitized_post_values() {
		_deprecated_function( __METHOD__, '0.4.2', 'WP_Customize_Manager::transaction::data()' );
		return $this->transaction->data();
	}

	/**
	 * Return the sanitized value for a given setting from the request's POST data.
	 *
	 * @since 3.4.0
	 * @since 4.1.1 Introduced 'default' parameter.
	 *
	 * @deprecated
	 *
	 * @param WP_Customize_Setting $setting A WP_Customize_Setting derived object
	 * @param mixed $default value returned $setting has no post value (added in 4.2.0).
	 * @return string|mixed $post_value Sanitized value or the $default provided
	 */
	public function post_value( $setting, $default = null ) {
		_deprecated_function( __METHOD__, '0.4.2', 'WP_Customxize_Manager::transaction::get()' );
		return $this->transaction->get( $setting, $default );
	}

	/**
	 * Override a setting's (unsanitized) value as found in the current transaction.
	 *
	 * @since 4.2.0
	 *
	 * @deprecated
	 *
	 * @param string $setting_id  The ID for the WP_Customize_Setting instance.
	 * @param mixed $value
	 */
	public function set_post_value( $setting_id, $value ) {
		_deprecated_function( __METHOD__, '0.4.2', 'WP_Customize_Manager::transaction::set()' );
		$this->transaction->set( $this->get_setting( $setting_id ), $value );
	}

	/**
	 * Print JavaScript settings.
	 *
	 * @since 3.4.0
	 */
	public function customize_preview_init() {
		$this->prepare_controls();

		ob_start(); // need to ensure the Customizer sends it all at once so scroll position is maintained (hopefully)
		wp_enqueue_script( 'customize-preview' );
		add_action( 'wp_head', array( $this, 'customize_preview_html5' ) );
		add_action( 'wp_print_styles', array( $this, 'print_preview_css' ), 1 );
		add_action( 'wp_footer', array( $this, 'customize_preview_settings' ), 20 );
		add_filter( 'wp_die_handler', array( $this, 'filter_preview_wp_die_handler' ), 11 );
		remove_action( 'shutdown', 'wp_ob_end_flush_all', 1 );
		add_action( 'shutdown', array( $this, 'preview_shutdown' ), 1 ); // instead of wp_ob_end_flush_all

		// Make sure that all URLs generated by WordPress retain the Customizer context
		$url_filters = array(
			'attachment_link',
			'author_link',
			'category_link',
			'day_link',
			'get_comments_pagenum_link',
			'get_pagenum_link',
			'home_url',
			'month_link',
			'page_link',
			'post_link',
			'post_link_category',
			'post_type_archive_link',
			'post_type_link',
			'search_link',
			'tag_link',
			'term_link',
			'the_permalink',
			'year_link',
		);
		foreach ( $url_filters as $filter ) {
			add_filter( $filter, array( $this, 'persist_preview_query_vars' ) );
		}
		// @todo add filter for the_content and the_excerpt

		/**
		 * Fires once the Customizer preview has initialized and JavaScript
		 * settings have been printed.
		 *
		 * @since 3.4.0
		 *
		 * @param WP_Customize_Manager $this WP_Customize_Manager instance.
		 */
		do_action( 'customize_preview_init', $this );
	}

	/**
	 * Prevent sending a 404 status when returning the response for the customize
	 * preview, since it causes the jQuery AJAX to fail. Send 200 instead.
	 *
	 * @since 4.0.0
	 * @access public
	 */
	public function customize_preview_override_404_status() {
		_deprecated_function( __METHOD__, '4.2.0' );
	}

	/**
	 * Print base element for preview frame.
	 *
	 * @deprecated
	 *
	 * @since 3.4.0
	 */
	public function customize_preview_base() {
		_deprecated_function( __METHOD__, '4.2.0' );
	}

	/**
	 * Print a workaround to handle HTML5 tags in IE < 9
	 *
	 * @since 3.4.0
	 */
	public function customize_preview_html5() { ?>
		<!--[if lt IE 9]>
		<script type="text/javascript">
			var e = [ 'abbr', 'article', 'aside', 'audio', 'canvas', 'datalist', 'details',
				'figure', 'footer', 'header', 'hgroup', 'mark', 'menu', 'meter', 'nav',
				'output', 'progress', 'section', 'time', 'video' ];
			for ( var i = 0; i < e.length; i++ ) {
				document.createElement( e[i] );
			}
		</script>
		<![endif]--><?php
	}

	/**
	 * Insert default style for highlighted widget at early point so theme
	 * stylesheet can override.
	 *
	 * @since 4.2.0
	 * @access public
	 *
	 * @action wp_print_styles
	 */
	public function print_preview_css() {
		?>
		<style>
		.customize-preview-not-allowed,
		.customize-preview-not-allowed * {
			cursor: not-allowed !important;
		}
		</style>
		<?php
	}

	/**
	 * Get the query vars that need to be persisted with each request in the preview.
	 *
	 * @since 4.2.0
	 *
	 * @return array
	 */
	public function get_preview_persisted_query_vars() {
		$persisted_query_vars = array(
			'customize_messenger_channel' => $this->messenger_channel,
			'wp_customize' => 'on',
			'customize_transaction_uuid' => $this->transaction->uuid,
			'theme' => $this->theme()->get_stylesheet(), // @todo Eliminate this. Opt for including in customizerd.
		);
		return $persisted_query_vars;
	}

	/**
	 * @return array
	 */
	public function get_nonces() {
		$nonces = array();
		if ( current_user_can( 'customize' ) ) {
			$nonces['update'] = wp_create_nonce( 'update-customize_' . $this->get_stylesheet() );
			// @todo only provide save nonce if current_user_can( get_post_type_object( self::TRANSACTION_POST_TYPE )->cap->publish_posts )?
			$nonces['save'] = wp_create_nonce( 'save-customize_' . $this->get_stylesheet() );
		}
		return $nonces;
	}

	/**
	 * Given a URL, add all query vars from $_GET which are requested.
	 *
	 * @since 4.2.0
	 *
	 * @param string $url
	 * @return string
	 */
	public function persist_preview_query_vars( $url ) {
		$persisted_query_vars = $this->get_preview_persisted_query_vars();
		if ( ! empty( $persisted_query_vars ) ) {
			$url = add_query_arg( $persisted_query_vars, $url );
		}
		$url = esc_url_raw( $url );
		// @todo If $url is not among the allowed URLs, then make it return a no-op URL or one which will trigger a warning if invoked?
		return $url;
	}

	/**
	 * Print JavaScript settings for preview frame.
	 *
	 * @param array $extra_settings Allow additional settings to be passed at time of invocation.
	 *
	 * @since 3.4.0
	 */
	public function customize_preview_settings( $extra_settings = array() ) {
		$settings = array(
			'values'  => array(),
			'channel' => $this->messenger_channel,
			'transaction' => array(
				'uuid' => $this->transaction->uuid,
			),
			'theme' => $this->theme()->get_stylesheet(),
			'nonce' => $this->get_nonces(),
			'url' => array(
				'allowed' => array_map( 'esc_url_raw', $this->get_allowed_urls() ),
			),
			'activePanels' => array(),
			'activeSections' => array(),
			'activeControls' => array(),
			'l10n' => array(
				'previewNotAllowed' => __( 'Not allowed in Customizer preview.' ),
			),
		);
		if ( is_array( $extra_settings ) ) {
			$settings = array_merge( $settings, $extra_settings );
		}

		foreach ( $this->settings as $id => $setting ) {
			$settings['values'][ $id ] = $setting->js_value();
		}
		foreach ( $this->panels as $id => $panel ) {
			/**
			 * @var WP_Customize_Panel $panel
			 */
			$settings['activePanels'][ $id ] = $panel->active();
			foreach ( $panel->sections as $id2 => $section ) {
				$settings['activeSections'][ $id2 ] = $section->active();
			}
		}
		foreach ( $this->sections as $id => $section ) {
			$settings['activeSections'][ $id ] = $section->active();
		}
		foreach ( $this->controls as $id => $control ) {
			$settings['activeControls'][ $id ] = $control->active();
		}

		?>
		<script type="text/javascript">
			var _wpCustomizeSettings = <?php echo wp_json_encode( $settings ); ?>;
		</script>
		<?php
	}

	/**
	 * Prints a signature so we can ensure the Customizer was properly executed.
	 *
	 * @deprecated
	 *
	 * @since 3.4.0
	 */
	public function customize_preview_signature() {
		_doing_it_wrong( __FUNCTION__, 'Function no longer used.', '4.2.0' );
	}

	/**
	 * Removes the signature in case we experience a case where the Customizer was not properly executed.
	 *
	 * @deprecated
	 *
	 * @since 3.4.0
	 */
	public function remove_preview_signature() {
		_doing_it_wrong( __FUNCTION__, 'Function no longer used.', '4.2.0' );
	}

	/**
	 * Detect if a fatal error or exception was thrown, and then pass this on
	 * to the Customizer if we are inside of the preview.
	 *
	 * @since 4.2.0
	 */
	public function preview_shutdown() {
		$last_error = error_get_last();
		if ( ! empty( $last_error ) && in_array( $last_error['type'], array( E_ERROR, E_USER_ERROR, E_RECOVERABLE_ERROR ) ) ) {
			while ( ob_get_level() > 0 ) {
				ob_end_clean();
			}
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				$data = $last_error;
			} else {
				$data = array();
			}
			switch ( $last_error['type'] ) {
				case E_ERROR:
					$data['message'] = __( 'E_ERROR' );
					break;
				case E_USER_ERROR:
					$data['message'] = __( 'E_USER_ERROR' );
					break;
				case E_RECOVERABLE_ERROR:
					$data['message'] = __( 'E_RECOVERABLE_ERROR' );
					break;
			}
			status_header( 500 );
			$this->wp_send_json_error( $data );
		} else {
			wp_ob_end_flush_all();
		}
	}

	/**
	 * Is it a theme preview?
	 *
	 * @since 3.4.0
	 *
	 * @return bool True if it's a preview, false if not.
	 */
	public function is_preview() {
		return (bool) $this->previewing;
	}

	/**
	 * Retrieve the template name of the previewed theme.
	 *
	 * @since 3.4.0
	 *
	 * @return string Template name.
	 */
	public function get_template() {
		return $this->theme()->get_template();
	}

	/**
	 * Retrieve the stylesheet name of the previewed theme.
	 *
	 * @since 3.4.0
	 *
	 * @return string Stylesheet name.
	 */
	public function get_stylesheet() {
		return $this->theme()->get_stylesheet();
	}

	/**
	 * Retrieve the template root of the previewed theme.
	 *
	 * @since 3.4.0
	 *
	 * @return string Theme root.
	 */
	public function get_template_root() {
		return get_raw_theme_root( $this->get_template(), true );
	}

	/**
	 * Retrieve the stylesheet root of the previewed theme.
	 *
	 * @since 3.4.0
	 *
	 * @return string Theme root.
	 */
	public function get_stylesheet_root() {
		return get_raw_theme_root( $this->get_stylesheet(), true );
	}

	/**
	 * Filter the current theme and return the name of the previewed theme.
	 *
	 * @since 3.4.0
	 *
	 * @param $current_theme {@internal Parameter is not used}
	 * @return string Theme name.
	 */
	public function current_theme( $current_theme ) {
		unset( $current_theme );
		return $this->theme()->display( 'Name' );
	}

	/**
	 * Update the customize transaction.
	 *
	 * @todo In addition to the customized array, we should be passed an array of setting configs so that they can be re-created.
	 * @todo What about settings that get deleted dynamically? They should be passed as well so they can be removed from the transaction.
	 *
	 * @since 4.2.0
	 */
	public function update_transaction() {
		if ( ! check_ajax_referer( 'update-customize_' . $this->get_stylesheet(), 'nonce', false ) ) {
			status_header( 400 );
			wp_send_json_error( 'bad_nonce' );
		}
		if ( 'POST' !== $_SERVER['REQUEST_METHOD'] ) {
			status_header( 405 );
			wp_send_json_error( 'bad_method' );
		}
		if ( ! current_user_can( 'customize' ) ) {
			status_header( 403 );
			wp_send_json_error( 'customize_not_allowed' );
		}
		if ( empty( $_REQUEST['customize_transaction_uuid'] ) ) {
			status_header( 400 );
			wp_send_json_error( 'invalid_customize_transaction_uuid' );
		}
		if ( empty( $this->post_data ) ) {
			status_header( 400 );
			wp_send_json_error( 'missing_customized_json' );
		}

		$transaction_post = $this->transaction->post();
		$transaction_post_type = get_post_type_object( WP_Customize_Transaction::POST_TYPE );
		$authorized = ( $transaction_post ?
			current_user_can( $transaction_post_type->cap->edit_post, $transaction_post->ID )
			:
			current_user_can( $transaction_post_type->cap->create_posts )
		);
		if ( ! $authorized ) {
			status_header( 403 );
			wp_send_json_error( 'unauthorized' );
		}

		$new_setting_ids = array_diff( array_keys( $this->post_data ), array_keys( $this->settings ) );
		$this->add_dynamic_settings( wp_array_slice_assoc( $this->post_data, $new_setting_ids ) );

		foreach ( $this->settings as $setting ) {
			// @todo delete settings that were deleted dynamically on the client (not just those which the user hasn't the cap to change)
			if ( $setting->check_capabilities() && array_key_exists( $setting->id, $this->post_data ) ) {
				$value = $this->post_data[ $setting->id ];
				$this->transaction->set( $setting, $value );
			}
		}

		$r = $this->transaction->save();
		if ( is_wp_error( $r ) ) {
			status_header( 500 );
			wp_send_json_error( $r->get_error_message() );
		}

		$response = array(
			'transaction_uuid' => $this->transaction->uuid,
			'transaction_settings' => $this->transaction->data(), // send back sanitized settings so that the UI can be updated to reflect the PHP-sanitized values
		);

		wp_send_json_success( $response );
	}

	/**
	 * Switch the theme and trigger the save() method on each setting.
	 *
	 * @since 3.4.0
	 */
	public function save() {
		if ( ! $this->is_preview() ) {
			wp_send_json_error( 'not_preview' );
		}

		$action = 'save-customize_' . $this->get_stylesheet();
		if ( ! check_ajax_referer( $action, 'nonce', false ) ) {
			wp_send_json_error( 'invalid_nonce' );
		}

		// Do we have to switch themes?
		if ( ! $this->is_theme_active() ) {
			// Temporarily stop previewing the theme to allow switch_themes()
			// to operate properly.
			$this->stop_previewing_theme();
			switch_theme( $this->get_stylesheet() );
			update_option( 'theme_switched_via_customizer', true );
			$this->start_previewing_theme();
		}

		$transaction_post = $this->transaction->post();
		$transaction_post_type = get_post_type_object( WP_Customize_Transaction::POST_TYPE );
		$authorized = ( $transaction_post ?
			current_user_can( $transaction_post_type->cap->edit_post, $transaction_post->ID )
			:
			current_user_can( $transaction_post_type->cap->create_posts )
		);
		if ( ! $authorized ) {
			status_header( 403 );
			wp_send_json_error( 'unauthorized' );
		}

		/**
		 * Fires once the theme has switched in the Customizer, but before settings
		 * have been saved.
		 *
		 * @since 3.4.0
		 *
		 * @param WP_Customize_Manager $this WP_Customize_Manager instance.
		 */
		do_action( 'customize_save', $this );

		if ( current_user_can( $transaction_post_type->cap->publish_posts ) ) {
			$status = 'publish';
		} else {
			$status = 'pending';
		}
		$this->transaction->save( $status );

		/**
		 * Fires after Customize settings have been saved.
		 *
		 * @since 3.6.0
		 *
		 * @param WP_Customize_Manager $this WP_Customize_Manager instance.
		 */
		do_action( 'customize_save_after', $this );

		/**
		 * Filter response data for a successful customize_save Ajax request.
		 *
		 * This filter does not apply if there was a nonce or authentication failure.
		 *
		 * @since 4.2.0
		 *
		 * @param array                $data Additional information passed back to the 'saved'
		 *                                   event on `wp.customize`.
		 * @param WP_Customize_Manager $this WP_Customize_Manager instance.
		 */
		$response = apply_filters( 'customize_save_response', array(), $this );
		wp_send_json_success( $response );
	}

	/**
	 * Add a customize setting.
	 *
	 * @since 3.4.0
	 *
	 * @param WP_Customize_Setting|string $id Customize Setting object, or ID.
	 * @param array $args                     Setting arguments; passed to WP_Customize_Setting
	 *                                        constructor.
	 */
	public function add_setting( $id, $args = array() ) {
		if ( $id instanceof WP_Customize_Setting ) {
			$setting = $id;
		} else {
			$setting = new WP_Customize_Setting( $this, $id, $args );
		}
		$this->settings[ $setting->id ] = $setting;
	}

	/**
	 * Register any dynamically-created settings, such as those in a transaction that have no corresponding setting created.
	 *
	 * This is a mechanism to "wake up" settings that have been dynamically created
	 * on the frontend and have been added to a transaction. When the transaction is
	 * loaded, the dynamically-created settings then will get created and previewed
	 * even though they are not directly created statically with code.
	 *
	 * @since 4.2.0
	 *
	 * @param string[] $setting_ids The setting IDs to add.
	 * @return WP_Customize_Setting[] The settings added.
	 */
	public function add_dynamic_settings( $setting_ids ) {
		$new_settings = array();
		foreach ( $setting_ids as $setting_id ) {
			// Skip settings already created
			if ( $this->get_setting( $setting_id ) ) {
				continue;
			}

			$setting_class = 'WP_Customize_Setting';
			$setting_args = false;

			/**
			 * Allow non-statically created settings to be constructed with custom WP_Customize_Setting subclass.
			 *
			 * @since 4.2.0
			 *
			 * @param string $class
			 * @param string $setting_id
			 */
			$setting_class = apply_filters( 'customize_dynamic_setting_class', $setting_class, $setting_id );

			/**
			 * Filter a dynamic setting's constructor args.
			 *
			 * For a dynamic setting to be registered, this filter must be employed
			 * to override the default false value with an array of args to pass to
			 * the WP_Customize_Setting constructor.
			 *
			 * @since 4.2.0
			 *
			 * @param false|array $setting_args  The arguments to the WP_Customize_Setting constructor.
			 * @param string      $setting_id    ID for dynamic setting, usually coming from $_POST['customized'].
			 */
			$setting_args = apply_filters( 'customize_dynamic_setting_args', $setting_args, $setting_id );
			if ( false === $setting_args ) {
				continue;
			}

			/**
			 * Allow non-statically created settings to be constructed with custom WP_Customize_Setting subclass.
			 *
			 * @since 4.2.0
			 *
			 * @param string $setting_class  WP_Customize_Setting or a subclass.
			 * @param string $setting_id     ID for dynamic setting, usually coming from $_POST['customized'].
			 * @param string $setting_args   WP_Customize_Setting or a subclass.
			 */
			$setting_class = apply_filters( 'customize_dynamic_setting_class', $setting_class, $setting_id, $setting_args );

			$setting = new $setting_class( $this, $setting_id, $setting_args );
			$this->add_setting( $setting );
			$new_settings[] = $setting;
		}
		return $new_settings;
	}

	/**
	 * Retrieve a customize setting.
	 *
	 * @since 3.4.0
	 *
	 * @param string $id Customize Setting ID.
	 * @return WP_Customize_Setting|null
	 */
	public function get_setting( $id ) {
		if ( isset( $this->settings[ $id ] ) ) {
			return $this->settings[ $id ];
		}
		return null;
	}

	/**
	 * Remove a customize setting.
	 *
	 * @since 3.4.0
	 *
	 * @param string $id Customize Setting ID.
	 */
	public function remove_setting( $id ) {
		unset( $this->settings[ $id ] );
	}

	/**
	 * Add a customize panel.
	 *
	 * @since 4.0.0
	 * @access public
	 *
	 * @param WP_Customize_Panel|string $id   Customize Panel object, or Panel ID.
	 * @param array                     $args Optional. Panel arguments. Default empty array.
	 */
	public function add_panel( $id, $args = array() ) {
		if ( $id instanceof WP_Customize_Panel ) {
			$panel = $id;
		} else {
			$panel = new WP_Customize_Panel( $this, $id, $args );
		}

		$this->panels[ $panel->id ] = $panel;
	}

	/**
	 * Retrieve a customize panel.
	 *
	 * @since 4.0.0
	 * @access public
	 *
	 * @param string $id Panel ID to get.
	 * @return WP_Customize_Panel|null Requested panel instance.
	 */
	public function get_panel( $id ) {
		if ( isset( $this->panels[ $id ] ) ) {
			return $this->panels[ $id ];
		}
		return null;
	}

	/**
	 * Remove a customize panel.
	 *
	 * @since 4.0.0
	 * @access public
	 *
	 * @param string $id Panel ID to remove.
	 */
	public function remove_panel( $id ) {
		unset( $this->panels[ $id ] );
	}

	/**
	 * Add a customize section.
	 *
	 * @since 3.4.0
	 *
	 * @param WP_Customize_Section|string $id   Customize Section object, or Section ID.
	 * @param array                       $args Section arguments.
	 */
	public function add_section( $id, $args = array() ) {
		if ( $id instanceof WP_Customize_Section ) {
			$section = $id;
		} else {
			$section = new WP_Customize_Section( $this, $id, $args );
		}
		$this->sections[ $section->id ] = $section;
	}

	/**
	 * Retrieve a customize section.
	 *
	 * @since 3.4.0
	 *
	 * @param string $id Section ID.
	 * @return WP_Customize_Section|null
	 */
	public function get_section( $id ) {
		if ( isset( $this->sections[ $id ] ) ) {
			return $this->sections[ $id ];
		}
		return null;
	}

	/**
	 * Remove a customize section.
	 *
	 * @since 3.4.0
	 *
	 * @param string $id Section ID.
	 */
	public function remove_section( $id ) {
		unset( $this->sections[ $id ] );
	}

	/**
	 * Add a customize control.
	 *
	 * @since 3.4.0
	 *
	 * @param WP_Customize_Control|string $id   Customize Control object, or ID.
	 * @param array                       $args Control arguments; passed to WP_Customize_Control
	 *                                          constructor.
	 */
	public function add_control( $id, $args = array() ) {
		if ( $id instanceof WP_Customize_Control ) {
			$control = $id;
		} else {
			$control = new WP_Customize_Control( $this, $id, $args );
		}
		$this->controls[ $control->id ] = $control;
	}

	/**
	 * Retrieve a customize control.
	 *
	 * @since 3.4.0
	 *
	 * @param string $id ID of the control.
	 * @return WP_Customize_Control|null $control The control object.
	 */
	public function get_control( $id ) {
		if ( isset( $this->controls[ $id ] ) ) {
			return $this->controls[ $id ];
		}
		return null;
	}

	/**
	 * Remove a customize control.
	 *
	 * @since 3.4.0
	 *
	 * @param string $id ID of the control.
	 */
	public function remove_control( $id ) {
		unset( $this->controls[ $id ] );
	}

	/**
	 * Register a customize control type.
	 *
	 * Registered types are eligible to be rendered via JS and created dynamically.
	 *
	 * @since 4.1.0
	 * @access public
	 *
	 * @param string $control Name of a custom control which is a subclass of
	 *                        {@see WP_Customize_Control}.
	 */
	public function register_control_type( $control ) {
		$this->registered_control_types[] = $control;
	}

	/**
	 * Render JS templates for all registered control types.
	 *
	 * @since 4.1.0
	 * @access public
	 */
	public function render_control_templates() {
		foreach ( $this->registered_control_types as $control_type ) {
			/**
			 * @var WP_Customize_Control $control
			 */
			$control = new $control_type( $this, 'temp', array() );
			$control->print_template();
		}
	}

	/**
	 * Helper function to compare two objects by priority, ensuring sort stability via instance_number.
	 *
	 * @since 3.4.0
	 *
	 * @param WP_Customize_Panel|WP_Customize_Section|WP_Customize_Control $a Object A.
	 * @param WP_Customize_Panel|WP_Customize_Section|WP_Customize_Control $b Object B.
	 * @return int
	 */
	protected function _cmp_priority( $a, $b ) {
		if ( $a->priority === $b->priority ) {
			return $a->instance_number - $a->instance_number;
		} else {
			return $a->priority - $b->priority;
		}
	}

	/**
	 * Prepare panels, sections, and controls.
	 *
	 * For each, check if required related components exist,
	 * whether the user has the necessary capabilities,
	 * and sort by priority.
	 *
	 * @since 3.4.0
	 */
	public function prepare_controls() {

		$controls = array();
		uasort( $this->controls, array( $this, '_cmp_priority' ) );

		foreach ( $this->controls as $id => $control ) {
			if ( ! isset( $this->sections[ $control->section ] ) || ! $control->check_capabilities() ) {
				continue;
			}

			$this->sections[ $control->section ]->controls[] = $control;
			$controls[ $id ] = $control;
		}
		$this->controls = $controls;

		// Prepare sections.
		uasort( $this->sections, array( $this, '_cmp_priority' ) );
		$sections = array();

		foreach ( $this->sections as $section ) {
			if ( ! $section->check_capabilities() || ! $section->controls ) {
				continue;
			}

			usort( $section->controls, array( $this, '_cmp_priority' ) );

			if ( ! $section->panel ) {
				// Top-level section.
				$sections[ $section->id ] = $section;
			} else {
				// This section belongs to a panel.
				if ( isset( $this->panels [ $section->panel ] ) ) {
					$this->panels[ $section->panel ]->sections[ $section->id ] = $section;
				}
			}
		}
		$this->sections = $sections;

		// Prepare panels.
		uasort( $this->panels, array( $this, '_cmp_priority' ) );
		$panels = array();

		foreach ( $this->panels as $panel ) {
			if ( ! $panel->check_capabilities() || ! $panel->sections ) {
				continue;
			}

			uasort( $panel->sections, array( $this, '_cmp_priority' ) );
			$panels[ $panel->id ] = $panel;
		}
		$this->panels = $panels;

		// Sort panels and top-level sections together.
		$this->containers = array_merge( $this->panels, $this->sections );
		uasort( $this->containers, array( $this, '_cmp_priority' ) );
	}

	/**
	 * Enqueue scripts for customize controls.
	 *
	 * @since 3.4.0
	 */
	public function enqueue_control_scripts() {
		foreach ( $this->controls as $control ) {
			$control->enqueue();
		}
	}

	/**
	 * Register some default controls.
	 *
	 * @since 3.4.0
	 */
	public function register_controls() {

		// @todo?
		// $this->add_setting( 'theme', array(
		// 	'default' => get_option( 'stylesheet' ),
		// 	'type' => 'option',
		// 	'capability' => 'switch_themes',
		// ) );

		/* Control Types (custom control classes) */
		$this->register_control_type( 'WP_Customize_Color_Control' );
		$this->register_control_type( 'WP_Customize_Upload_Control' );
		$this->register_control_type( 'WP_Customize_Image_Control' );
		$this->register_control_type( 'WP_Customize_Background_Image_Control' );

		/* Site Title & Tagline */

		$this->add_section( 'title_tagline', array(
			'title'    => __( 'Site Title & Tagline' ),
			'priority' => 20,
		) );

		$this->add_setting( 'blogname', array(
			'default'    => get_option( 'blogname' ),
			'type'       => 'option',
			'capability' => 'manage_options',
		) );

		$this->add_control( 'blogname', array(
			'label'      => __( 'Site Title' ),
			'section'    => 'title_tagline',
		) );

		$this->add_setting( 'blogdescription', array(
			'default'    => get_option( 'blogdescription' ),
			'type'       => 'option',
			'capability' => 'manage_options',
		) );

		$this->add_control( 'blogdescription', array(
			'label'      => __( 'Tagline' ),
			'section'    => 'title_tagline',
		) );

		/* Colors */

		$this->add_section( 'colors', array(
			'title'          => __( 'Colors' ),
			'priority'       => 40,
		) );

		$this->add_setting( 'header_textcolor', array(
			'theme_supports' => array( 'custom-header', 'header-text' ),
			'default'        => get_theme_support( 'custom-header', 'default-text-color' ),

			'sanitize_callback'    => array( $this, '_sanitize_header_textcolor' ),
			'sanitize_js_callback' => 'maybe_hash_hex_color',
		) );

		// Input type: checkbox
		// With custom value
		$this->add_control( 'display_header_text', array(
			'settings' => 'header_textcolor',
			'label'    => __( 'Display Header Text' ),
			'section'  => 'title_tagline',
			'type'     => 'checkbox',
		) );

		$this->add_control( new WP_Customize_Color_Control( $this, 'header_textcolor', array(
			'label'   => __( 'Header Text Color' ),
			'section' => 'colors',
		) ) );

		// Input type: Color
		// With sanitize_callback
		$this->add_setting( 'background_color', array(
			'default'        => get_theme_support( 'custom-background', 'default-color' ),
			'theme_supports' => 'custom-background',

			'sanitize_callback'    => 'sanitize_hex_color_no_hash',
			'sanitize_js_callback' => 'maybe_hash_hex_color',
		) );

		$this->add_control( new WP_Customize_Color_Control( $this, 'background_color', array(
			'label'   => __( 'Background Color' ),
			'section' => 'colors',
		) ) );

		/* Custom Header */

		$this->add_section( 'header_image', array(
			'title'          => __( 'Header Image' ),
			'theme_supports' => 'custom-header',
			'priority'       => 60,
		) );

		$this->add_setting( new WP_Customize_Filter_Setting( $this, 'header_image', array(
			'default'        => get_theme_support( 'custom-header', 'default-image' ),
			'theme_supports' => 'custom-header',
		) ) );

		$this->add_setting( new WP_Customize_Header_Image_Setting( $this, 'header_image_data', array(
			// 'default'        => get_theme_support( 'custom-header', 'default-image' ),
			'theme_supports' => 'custom-header',
		) ) );

		$this->add_control( new WP_Customize_Header_Image_Control( $this ) );

		/* Custom Background */

		$this->add_section( 'background_image', array(
			'title'          => __( 'Background Image' ),
			'theme_supports' => 'custom-background',
			'priority'       => 80,
		) );

		$this->add_setting( 'background_image', array(
			'default'        => get_theme_support( 'custom-background', 'default-image' ),
			'theme_supports' => 'custom-background',
		) );

		$this->add_setting( new WP_Customize_Background_Image_Setting( $this, 'background_image_thumb', array(
			'theme_supports' => 'custom-background',
		) ) );

		$this->add_control( new WP_Customize_Background_Image_Control( $this ) );

		$this->add_setting( 'background_repeat', array(
			'default'        => get_theme_support( 'custom-background', 'default-repeat' ),
			'theme_supports' => 'custom-background',
		) );

		$this->add_control( 'background_repeat', array(
			'label'      => __( 'Background Repeat' ),
			'section'    => 'background_image',
			'type'       => 'radio',
			'choices'    => array(
				'no-repeat'  => __( 'No Repeat' ),
				'repeat'     => __( 'Tile' ),
				'repeat-x'   => __( 'Tile Horizontally' ),
				'repeat-y'   => __( 'Tile Vertically' ),
			),
		) );

		$this->add_setting( 'background_position_x', array(
			'default'        => get_theme_support( 'custom-background', 'default-position-x' ),
			'theme_supports' => 'custom-background',
		) );

		$this->add_control( 'background_position_x', array(
			'label'      => __( 'Background Position' ),
			'section'    => 'background_image',
			'type'       => 'radio',
			'choices'    => array(
				'left'       => __( 'Left' ),
				'center'     => __( 'Center' ),
				'right'      => __( 'Right' ),
			),
		) );

		$this->add_setting( 'background_attachment', array(
			'default'        => get_theme_support( 'custom-background', 'default-attachment' ),
			'theme_supports' => 'custom-background',
		) );

		$this->add_control( 'background_attachment', array(
			'label'      => __( 'Background Attachment' ),
			'section'    => 'background_image',
			'type'       => 'radio',
			'choices'    => array(
				'scroll'     => __( 'Scroll' ),
				'fixed'      => __( 'Fixed' ),
			),
		) );

		// If the theme is using the default background callback, we can update
		// the background CSS using postMessage.
		if ( '_custom_background_cb' === get_theme_support( 'custom-background', 'wp-head-callback' ) ) {
			foreach ( array( 'color', 'image', 'position_x', 'repeat', 'attachment' ) as $prop ) {
				$this->get_setting( 'background_' . $prop )->transport = 'postMessage';
			}
		}

		/* Nav Menus */

		$locations      = get_registered_nav_menus();
		$menus          = wp_get_nav_menus();
		$num_locations  = count( array_keys( $locations ) );

		$this->add_section( 'nav', array(
			'title'          => __( 'Navigation' ),
			'theme_supports' => 'menus',
			'priority'       => 100,
			'description'    => sprintf( _n( 'Your theme supports %s menu. Select which menu you would like to use.', 'Your theme supports %s menus. Select which menu appears in each location.', $num_locations ), number_format_i18n( $num_locations ) ) . "\n\n" . __( 'You can edit your menu content on the Menus screen in the Appearance section.' ),
		) );

		if ( $menus ) {
			$choices = array( '' => __( '&mdash; Select &mdash;' ) );
			foreach ( $menus as $menu ) {
				$choices[ $menu->term_id ] = wp_html_excerpt( $menu->name, 40, '&hellip;' );
			}

			foreach ( $locations as $location => $description ) {
				$menu_setting_id = "nav_menu_locations[{$location}]";

				$this->add_setting( $menu_setting_id, array(
					'sanitize_callback' => 'absint',
					'theme_supports'    => 'menus',
				) );

				$this->add_control( $menu_setting_id, array(
					'label'   => $description,
					'section' => 'nav',
					'type'    => 'select',
					'choices' => $choices,
				) );
			}
		}

		/* Static Front Page */
		// #WP19627

		// Replicate behavior from options-reading.php and hide front page options if there are no pages
		if ( get_pages() ) {
			$this->add_section( 'static_front_page', array(
				'title'          => __( 'Static Front Page' ),
				//'theme_supports' => 'static-front-page',
				'priority'       => 120,
				'description'    => __( 'Your theme supports a static front page.' ),
			) );

			$this->add_setting( 'show_on_front', array(
				'default'        => get_option( 'show_on_front' ),
				'capability'     => 'manage_options',
				'type'           => 'option',
				//'theme_supports' => 'static-front-page',
			) );

			$this->add_control( 'show_on_front', array(
				'label'   => __( 'Front page displays' ),
				'section' => 'static_front_page',
				'type'    => 'radio',
				'choices' => array(
					'posts' => __( 'Your latest posts' ),
					'page'  => __( 'A static page' ),
				),
			) );

			$this->add_setting( 'page_on_front', array(
				'type'       => 'option',
				'capability' => 'manage_options',
				//'theme_supports' => 'static-front-page',
			) );

			$this->add_control( 'page_on_front', array(
				'label'      => __( 'Front page' ),
				'section'    => 'static_front_page',
				'type'       => 'dropdown-pages',
			) );

			$this->add_setting( 'page_for_posts', array(
				'type'           => 'option',
				'capability'     => 'manage_options',
				//'theme_supports' => 'static-front-page',
			) );

			$this->add_control( 'page_for_posts', array(
				'label'      => __( 'Posts page' ),
				'section'    => 'static_front_page',
				'type'       => 'dropdown-pages',
			) );
		}
	}

	/**
	 * Add settings in the transaction that were not added with code, e.g. dynamically-created settings for Widgets
	 *
	 * @since 4.2.0
	 */
	public function register_dynamic_settings() {
		$this->add_dynamic_settings( array_keys( $this->transaction->data() ) );
	}

	/**
	 * Callback for validating the header_textcolor value.
	 *
	 * Accepts 'blank', and otherwise uses sanitize_hex_color_no_hash().
	 * Returns default text color if hex color is empty.
	 *
	 * @since 3.4.0
	 *
	 * @param string $color
	 * @return string
	 */
	public function _sanitize_header_textcolor( $color ) {
		if ( 'blank' === $color ) {
			return 'blank';
		}

		$color = sanitize_hex_color_no_hash( $color );
		if ( empty( $color ) ) {
			$color = get_theme_support( 'custom-header', 'default-text-color' );
		}

		return $color;
	}
}

/**
 * Sanitizes a hex color.
 *
 * Returns either '', a 3 or 6 digit hex color (with #), or null.
 * For sanitizing values without a #, see sanitize_hex_color_no_hash().
 *
 * @since 3.4.0
 *
 * @param string $color
 * @return string|null
 */
function sanitize_hex_color( $color ) {
	if ( '' === $color ) {
		return '';
	}

	// 3 or 6 hex digits, or the empty string.
	if ( preg_match( '|^#([A-Fa-f0-9]{3}){1,2}$|', $color ) ) {
		return $color;
	}

	return null;
}

/**
 * Sanitizes a hex color without a hash. Use sanitize_hex_color() when possible.
 *
 * Saving hex colors without a hash puts the burden of adding the hash on the
 * UI, which makes it difficult to use or upgrade to other color types such as
 * rgba, hsl, rgb, and html color names.
 *
 * Returns either '', a 3 or 6 digit hex color (without a #), or null.
 *
 * @since 3.4.0
 *
 * @param string $color
 * @return string|null
 */
function sanitize_hex_color_no_hash( $color ) {
	$color = ltrim( $color, '#' );

	if ( '' === $color ) {
		return '';
	}

	return sanitize_hex_color( '#' . $color ) ? $color : null;
}

/**
 * Ensures that any hex color is properly hashed.
 * Otherwise, returns value untouched.
 *
 * This method should only be necessary if using sanitize_hex_color_no_hash().
 *
 * @since 3.4.0
 *
 * @param string $color
 * @return string
 */
function maybe_hash_hex_color( $color ) {
	if ( $unhashed = sanitize_hex_color_no_hash( $color ) ) {
		return '#' . $unhashed;
	}

	return $color;
}
