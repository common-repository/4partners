<?php

namespace ForPartners;

use ForPartners\Import\CategoryImport;
use ForPartners\Import\Manager;
use ForPartners\Import\Process;
use ForPartners\Services\APIClient;
use ForPartners\Services\AttributeService;
use ForPartners\Services\CategoryService;
use ForPartners\Services\ProductService;
use ForPartners\Services\SettingsService;
use WP_REST_Response;

/**
 * Class Plugin
 * @package ForPartners
 */
class Plugin {
	private $admin_error_message;

	public function __construct() {
	}

	public static function get_service_locator() {
		static $service_locator;
		if ( ! $service_locator ) {
			$service_locator = new Services\ServiceLocator();
		}

		return $service_locator;
	}

	public function make_services() {
		$service_locator = static::get_service_locator();

		load_plugin_textdomain( '4partners', false, dirname( plugin_basename( __FILE__ ) ) . '/../languages' );

		$setting_service = new SettingsService();
		$service_locator->addInstance( SettingsService::class, $setting_service );

		if ( ! $this->is_wp_dependencies_active() ) {
			return false;
		}

		$api_client = new APIClient( $setting_service->get_option_value( 'api_key' ) );
		$service_locator->addInstance( APIClient::class, $api_client );

		$service_locator->addInstance( AttributeService::class, new AttributeService() );

		$category_service = new CategoryService( $api_client );
		$category_service->init();
		$service_locator->addInstance( CategoryService::class, $category_service );

		$product_service = new ProductService(
			$service_locator->get( AttributeService::class ),
			$category_service
		);
		$product_service->init();
		$service_locator->addInstance( ProductService::class, $product_service );

		$category_import = new CategoryImport(
			$api_client,
			$product_service,
			$setting_service
		);

		$service_locator->addInstance( Manager::class,
			new Manager(
				$setting_service,
				$category_import
			)
		);

		$service_locator->addInstance( CategoryImport::class, $category_import );

		$service_locator->addInstance( Process::class, new Process(
			$service_locator->get( CategoryImport::class )
		) );

		return true;
	}

	public function admin_error_notice() {

		?>
        <div class="error notice">
            <p>
				<?php echo $this->admin_error_message ?>
            </p>
        </div>
		<?php
	}

	public function run() {
		add_action( 'rest_api_init', [ $this, 'rest_api_init' ] );

		// hide 4partners route on /wp-json/
		add_filter( 'rest_index', [ $this, 'disable_api_index' ] );

		add_filter( 'wp_get_attachment_image_src', [ $this, 'wp_get_attachment_image_src' ], 10, 4 );

		$import_manager = static::get_service_locator()->get( Manager::class );
		$import_manager->add_cron_job();

		if ( is_admin() ) {
			$this->run_admin();
		}
	}


	public function wp_get_attachment_image_src( $image, $attachment_id, $size, $icon ) {
		if ( $image !== false ) {
			return $image;
		}

		global $post;

		if ( ! is_a( $post, \WP_Post::class ) || $post->post_type !== 'product' ) {
			return $image;
		}

		$images = get_post_meta( $post->ID, ProductService::META_4PARTNERS_IMAGES, true );

		if ( ! $images ) {
			return $image;
		}

		$index = empty( $images[ $attachment_id - 1 ] ) ? 0 : $attachment_id - 1;

		$image[0] = $images[ $index ][0];

		if ( empty( $image[1] ) ) {
			$image[1] = $images[ $index ][1][0];
			$image[2] = $images[ $index ][1][1];
			$image[3] = false;
		}

		return $image;
	}

	public function is_wp_dependencies_active() {

		if ( ! in_array( 'woocommerce/woocommerce.php', apply_filters( 'active_plugins', get_option( 'active_plugins' ) ) ) ) {
			$this->admin_error_message = __( '4partners plugin error: please install and activate WooCommerce wordpress plugin', '4partners' );
			add_action( 'admin_notices', [ $this, 'admin_error_notice' ] );

			return false;
		}

		if ( ! in_array( 'cyr2lat/cyr-to-lat.php', apply_filters( 'active_plugins', get_option( 'active_plugins' ) ) ) ) {
			$this->admin_error_message = __( '4partners plugin error: please install and activate Cyr-To-Lat wordpress plugin', '4partners' );
			add_action( 'admin_notices', [ $this, 'admin_error_notice' ] );

			return false;
		}

		return true;
	}

	private function run_admin() {
		$service_locator = static::get_service_locator();

		$settings_service = $service_locator->get( SettingsService::class );
		$settings_service->add_admin_actions();

		if ( get_option( 'Activated_Plugin' ) == '4partners' ) {
			$this->add_default_options();
			delete_option( 'Activated_Plugin' );
		}

		add_action( 'admin_enqueue_scripts', [ $this, 'add_admin_scripts' ] );
	}

	public function add_admin_scripts() {
		$current_screen = get_current_screen();

		if ( $current_screen->base == 'settings_page_4partners-admin' ) {
			wp_enqueue_style( '4parterns_css', plugins_url( '../assets/4partners.css', __FILE__ ) );
			wp_enqueue_script( '4parterns_js', plugins_url( '../assets/4partners.js', __FILE__ ) );
		}
	}

	public function install() {
		$this->add_versions_table( Process::get_sync_table_name() );
		add_option( 'Activated_Plugin', '4partners' );
	}

	public function uninstall() {
		if ( current_user_can( 'administrator' ) ) {
			wp_clear_scheduled_hook( Manager::CRON_HOOK_NAME );
			$this->delete_table( Process::get_sync_table_name() );
		}
	}

	public function add_versions_table( $table_name ) {
		global $wpdb;
		$charset_collate = $wpdb->get_charset_collate();
		$sql             = "CREATE TABLE $table_name (
			`id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
			`category_id` INT UNSIGNED NOT NULL,
			`last_offset` INT UNSIGNED NOT NULL,
			`last_limit` INT UNSIGNED NOT NULL,
			`total` INT UNSIGNED NOT NULL,
			`total_loaded` INT UNSIGNED NOT NULL,
			`created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
			`updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			`status` ENUM('running','error','finished') NOT NULL,
			`message` TEXT DEFAULT NULL,
			PRIMARY KEY  (`id`)
		) $charset_collate;";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
	}

	public function add_default_options() {
		$service_locator = static::get_service_locator();
		$setting_service = $service_locator->get( SettingsService::class );
		$setting_service->add_default_options();
	}

	public function delete_table( $table_name ) {
		global $wpdb;
		$wpdb->query( 'DROP TABLE IF EXISTS ' . Process::get_sync_table_name() );
	}

	public function deactivate() {

	}

	public function rest_api_init() {
		register_rest_route(
			'4partners/v1',
			'/import/',
			[
				'methods'             => 'GET',
				'callback'            => function ( \WP_REST_Request $request ) {

					$service_locator = static::get_service_locator();
					$import          = $service_locator->get( Process::class );

					return $import->run( $request );
				},
				'permission_callback' => function ( \WP_REST_Request $request ) {
					$service_locator = static::get_service_locator();
					$category_import = $service_locator->get( CategoryImport::class );

					return $category_import->get_import_token() === $request->get_param( 'token' );
				},
				'args'                => [
					'category_id' => [
						'validate_callback' => function ( $param ) {
							return ! empty( get_term_meta( $param, CategoryService::META_4PARTNERS_ID, true ) );
						},
					],
					'offset'      => [
						'validate_callback' => function ( $param ) {
							return filter_var( $param, FILTER_VALIDATE_INT );
						},
					],
					'limit'       => [
						'validate_callback' => function ( $param ) {
							return $param === '' || filter_var( $param, FILTER_VALIDATE_INT );
						},
					],
				],
			]
		);
	}

	/**
	 * @param WP_REST_Response $response
	 *
	 * @return WP_REST_Response
	 */
	public function disable_api_index( WP_REST_Response $response ) {
		foreach ( $response->data['namespaces'] as $i => $namespace ) {
			if ( strpos( $namespace, '4partners/v' ) !== false ) {
				unset( $response->data['namespaces'][ $i ] );
			}
		}
		foreach ( $response->data['routes'] as $i => $route ) {
			if ( strpos( $i, '4partners/v' ) !== false ) {
				unset( $response->data['routes'][ $i ] );
			}
		}

		return $response;
	}
}
