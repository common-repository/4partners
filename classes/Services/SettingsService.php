<?php

namespace ForPartners\Services;

use ForPartners\Import\CategoryImport;
use ForPartners\Import\Process;
use ForPartners\Plugin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
} // Exit if accessed directly


/**
 * Class Settings
 * @package ForPartners\Services
 */
class SettingsService {
	const OPTION_NAME = 'forpartners';
	const CRON_DAILY = 'daily';
	const CRON_TWICEDAILY = 'twicedaily';
	const CRON_WEEKLY = 'weekly';

	/**
	 * Holds the values to be used in the fields callbacks
	 */
	private $options;

	public function __construct() {
	}

	public function add_admin_actions() {
		add_action( 'admin_menu', array( $this, 'add_plugin_page' ) );
		add_action( 'admin_init', array( $this, 'page_init' ) );
	}

	/**
	 * Add options page
	 */
	public function add_plugin_page() {
		add_options_page(
			'4partners settings',
			'4partners',
			'manage_options',
			'4partners-admin',
			array( $this, 'create_admin_page' )
		);
	}

	/**
	 * Options page callback
	 */
	public function create_admin_page() {
		// Set class property
		$this->options = get_option( static::OPTION_NAME ); ?>
        <div class="wrap">
            <div class="forpartners-tabs-panel">
                <div data-section="forpartners-section_0"><?php _e( 'Synchronization', '4partners' ) ?></div>
                <div data-section="forpartners-section_1"><?php _e( 'Categories', '4partners' ) ?></div>
                <div data-section="forpartners-section_2">API</div>
            </div>
            <form method="post" action="options.php" class="forpartners-form">
				<?php
				// This prints out all hidden setting fields
				settings_fields( '4partners-group' );
				do_settings_sections( '4partners-admin' );
				submit_button();
				?>
            </form>
        </div>
		<?php
	}

	/**
	 * Register and add settings
	 */
	public function page_init() {
		register_setting(
			'4partners-group', // Option group.
			static::OPTION_NAME, // Option name.
			array(
				'sanitize_callback' => array( $this, 'sanitize' ), // Sanitize.
			)
		);

		add_settings_section(
			'4partners-sync-section-id', // ID.
			'', // Title.
			array( $this, 'print_section_info' ), // Callback.
			'4partners-admin' // Page.
		);

		add_settings_section(
			'4partners-categories-section-id', // ID.
			'', // Title.
			array( $this, 'print_section_info' ), // Callback.
			'4partners-admin' // Page.
		);

		add_settings_section(
			'4partners-general-section-id', // ID.
			'', // Title.
			array( $this, 'print_section_info' ), // Callback.
			'4partners-admin' // Page.
		);

		add_settings_field(
			'api_key',
			'',
			array( $this, 'api_key_callback' ),
			'4partners-admin',
			'4partners-general-section-id'
		);

		add_settings_field(
			'cron',
			'',
			array( $this, 'cron_callback' ),
			'4partners-admin',
			'4partners-general-section-id'
		);

		add_settings_field(
			'sync',
			__( 'Synchronization', '4partners' ),
			array( $this, 'sync_callback' ),
			'4partners-admin',
			'4partners-sync-section-id'
		);

		add_settings_field(
			'categories',
			__( 'Categories', '4partners' ),
			array( $this, 'categories_callback' ),
			'4partners-admin',
			'4partners-categories-section-id'
		);

		add_settings_field(
			'hidden_fields',
			'',
			array( $this, 'hidden_fields_callback' ),
			'4partners-admin',
			'4partners-general-section-id'
		);
	}

	/**
	 * Sanitize each setting field as needed
	 *
	 * @param array $input Contains all settings fields as array keys.
	 *
	 * @return array
	 */
	public function sanitize( $input ) {
		$new_input = array();

		if ( isset( $input['api_key'] ) ) {
			$new_input['api_key'] = sanitize_text_field( $input['api_key'] );
		}

		if ( isset( $input['cron'] ) ) {
			$new_input['cron'] = sanitize_text_field( $input['cron'] );
		}

		if ( isset( $input['categories'] ) ) {
			$new_input['categories'] = $input['categories'];
		}

		if ( isset( $input['start_sync_categories'] ) ) {
			$new_input['start_sync_categories'] = array_map( 'sanitize_text_field', $input['start_sync_categories'] );
		} else {
			$new_input['start_sync_categories'] = [];
		}

		if ( isset( $input['import_token'] ) ) {
			$new_input['import_token'] = sanitize_text_field( $input['import_token'] );
		}

		return $new_input;
	}

	public function api_key_callback() {
		printf(
			'<div class="forpartners-form-label">4Parnters API Key</div><input type="text" size="60" id="api_key" name="forpartners[api_key]" value="%s" /><p class="description"></p>',
			isset( $this->options['api_key'] ) ? esc_attr( $this->options['api_key'] ) : ''
		);
	}

	public function hidden_fields_callback() {
		printf(
			'<input type="hidden" id="import_token" name="forpartners[import_token]" value="%s" />',
			isset( $this->options['import_token'] ) ? esc_attr( $this->options['import_token'] ) : ''
		);
	}

	public function cron_callback() {
		$options           = '';
		$current_value     = isset( $this->options['cron'] ) ? $this->options['cron'] : 'daily';
		$available_options = [
			static::CRON_DAILY      => __( 'Daily', '4partners' ),
			static::CRON_TWICEDAILY => __( 'Twice daily', '4partners' ),
			static::CRON_WEEKLY     => __( 'Weekly', '4partners' )
		];
		foreach ( $available_options as $key => $value ) {
			$selected = $current_value == $key ? 'selected="selected"' : '';
			$options  .= '<option value="' . esc_attr( $key ) . '" ' . $selected . '>' . esc_attr( $value ) . '</option>';
		}
		printf(
			'<div class="forpartners-form-label">%s</div><select name="forpartners[cron]" id="">%s</select>',
			__( 'How often to sync products?', '4partners' ), $options
		);
	}

	public function sync_callback() {
		$args = [
			'taxonomy'   => 'product_cat',
			'hide_empty' => false,
			'meta_query' => array(
				array(
					'key'     => CategoryService::META_4PARTNERS_ID,
					'compare' => 'EXISTS',
				)
			)
		];

		global $wpdb;

		$table = Process::get_sync_table_name();

		$datetime = new \DateTime( '7 days ago' );

		$versions = $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM $table WHERE created_at > '%s' ORDER BY id DESC", $datetime->format( 'Y-m-d H:i:s' ) ),
			ARRAY_A
		);

		$categories_by_version = [];
		foreach ( $versions as $version ) {
			if ( empty( $categories_by_version[ $version['category_id'] ] ) ) {
				$version['created_at']                            = new \DateTime( $version['created_at'] );
				$version['updated_at']                            = new \DateTime( $version['updated_at'] );
				$categories_by_version[ $version['category_id'] ] = $version;
			}
		}

		$terms = get_terms( $args );

		$categories               = [];
		$categories_with_children = [];
		foreach ( $terms as $term ) {
			$external_id                  = get_term_meta( $term->term_id, CategoryService::META_4PARTNERS_ID, true );
			$categories_with_children[]   = $term->parent;
			$version                      = isset( $categories_by_version[ $term->term_id ] ) ? $categories_by_version[ $term->term_id ] : [];
			$categories[ $term->term_id ] = [ $term->name, $term->parent, $version, $external_id ];
		}

		if ( empty( $categories ) ) {
			echo '<p>' . __( 'Please check categories for import from API', '4partners' ) . '</p>';
		} else {
			?>

            <table class="forpartners-sync_table">
                <thead>
                <tr>
                    <th style="width:20px;"><input title="<?php esc_attr_e( 'Check all', '4partners' ) ?>"
                                                   type="checkbox" name="start_sync_all" value=""/>
                    </th>
                    <th><?php _e( 'Category', '4partners' ); ?></th>
                    <th><?php _e( 'Amount', '4partners' ); ?></th>
                    <th><?php _e( 'Started at', '4partners' ); ?></th>
                    <th><?php _e( 'Ended at', '4partners' ); ?></th>
                    <th><?php _e( 'Status', '4partners' ); ?></th>
                    <th><?php _e( 'Items loaded', '4partners' ); ?></th>
                </tr>
                </thead>
                <tbody>
				<?php
				$total_categories      = 0;
				$total_products_loaded = 0;
				$total_products        = 0;
				foreach ( $categories as $category_id => $category_data ) {
					$checked = in_array( $category_id, $this->options['start_sync_categories'] ) ? 'checked' : '';

					if ( in_array( $category_id, $categories_with_children ) ) {
						continue;
					}
					$total_categories ++;
					?>
                    <tr>
                        <td><input type="checkbox" name="forpartners[start_sync_categories][]"
                                   value="<?php echo intval( $category_id ) ?>" <?php echo esc_attr( isset( $category_data[2]['status'] ) && $category_data[2]['status'] == Process::STATUS_RUNNING ? 'disabled' : '' ) ?>
                                   id="sync_category_<?php echo intval( $category_id ) ?>" <?php echo esc_attr( $checked ) ?> />
                        </td>
                        <td>
                            <label for="sync_category_<?php echo intval( $category_id ) ?>"
                                   data-external_id="<?php echo intval( $category_data[3] ) ?>"><?php echo esc_html( $category_data[0] ) ?></label>

                            <div class="forpartners-category_parents">
								<?php echo trim( get_term_parents_list( $category_id, 'product_cat', [
									'separator' => ' / ',
								] ), ' / ' );
								?>
                            </div>

                        </td>
                        <td>
							<?php if ( ! empty( $category_data[2] ) ): ?>
								<?php echo esc_html( $category_data[2]['total'] ) ?>
							<?php endif ?>

                        </td>
                        <td>
							<?php if ( ! empty( $category_data[2] ) ): ?>
								<?php echo $category_data[2]['created_at']->format( 'd-m-Y' ) ?>
                                <div class="forpartners-time"><?php echo $category_data[2]['created_at']->format( 'H:i:s' ) ?></div>
							<?php endif ?>
                        </td>
                        <td>
							<?php if ( ! empty( $category_data[2] ) && $category_data[2]['status'] != Process::STATUS_RUNNING ): ?>
								<?php echo $category_data[2]['updated_at']->format( 'd-m-Y' ) ?>
                                <div class="forpartners-time"><?php echo $category_data[2]['updated_at']->format( 'H:i:s' ) ?></div>
							<?php endif ?>
                        </td>
                        <td>
							<?php if ( ! empty( $category_data[2] ) ): ?>
								<?php if ( $category_data[2]['status'] == Process::STATUS_RUNNING ): ?>
                                    <span class="dashicons dashicons-clock"></span> <?php _e( 'Processing...', '4partners' ); ?>
								<?php elseif ( $category_data[2]['status'] == Process::STATUS_ERROR ): ?>
                                    <span title="<?php echo esc_attr( $category_data[2]['message'] ) ?>"><span
                                                class="dashicons dashicons-no-alt"></span> <?php _e( 'Error', '4partners' ); ?></span>
								<?php else: ?>
                                    <span class="dashicons dashicons-saved"></span> <?php _e( 'Completed', '4partners' ); ?>
								<?php endif ?>
							<?php endif ?>
                        </td>
                        <td>
							<?php
							if ( ! empty( $category_data[2] ) ):
								$total_products += $category_data[2]['total'];
								$total_products_loaded += $category_data[2]['total_loaded'];
								?>
								<?php echo intval( $category_data[2]['total_loaded'] ) ?> / <?php echo intval( $category_data[2]['total'] ) ?>
							<?php endif ?>
                        </td>
                    </tr>

					<?php
				}
				?>
                </tbody>

            </table>
            <div class="forpartners-summary">
                <div>
                    <span><?php _e( 'Categories', '4partners' ); ?>: <b><?php echo intval( $total_categories ) ?></b></span>
                    <span><?php _e( 'Products', '4partners' ); ?>: <b><?php echo intval( $total_products_loaded ) ?>/<?php echo intval( $total_products ) ?></b></span>
					<?php if ( ! empty( $total_products ) ): ?>
                        <span><?php _e( 'Progress', '4partners' ); ?>: <b><?php echo intval( $total_products_loaded / $total_products * 100 ) ?>%</b></span>
					<?php endif ?>
                </div>

                <div> <?php submit_button(); ?> </div>
            </div>

			<?php

		}
	}

	public function display_categories( array $array, $level = 0, $parent = 0 ) {
		foreach ( $array as $element_id => $element ) {
			$name      = $element[0];
			$parent_id = intval( $element[1] );
			$i ++;
			echo '<li class="' . ( isset( $element['children'] ) ? 'forpartners-categories__has-children ' : '' ) . '">';

			$checked = isset( $this->options['categories'][ $element_id ] ) ? 'checked' : '';

			?>
            <label for="<?php echo intval( $element_id ) ?>">
                <input type="checkbox" name="forpartners[categories][<?php echo intval( $element_id ) ?>]"
                       id="<?php echo intval( $element_id ) ?>" value="1" <?php echo esc_attr( $checked ) ?> />
				<?php echo esc_html( $name ) ?> (<?php echo intval( $element_id ) ?>)
            </label>
			<?php
			if ( isset( $element['children'] ) ) {
				$this->display_tree_list( $element['children'], $level + 1, $parent_id );
			}

			echo '</li>';
		}
	}

	public function categories_callback() {
		$service_locator  = Plugin::get_service_locator();
		$category_service = $service_locator->get( CategoryService::class );
		$categories       = $category_service->get_all_from_api();
		$external_tree    = $category_service->make_category_tree( $categories );

		?>

        <div id="categories_tree">
			<?php
			$this->display_tree_list( $external_tree );
			?>
        </div>

        <div class="forpartners-summary">
            <div>
                <span><?php _e( 'Checked categories', '4partners' ); ?>: <b data-checked-categories-count></b></span>
            </div>

            <div> <?php submit_button(); ?> </div>
        </div>

		<?php
	}

	public function display_tree_list( array $array, $level = 0, $parent = 0 ) {
		$classes = 0 === $level ? 'forpartners-categories ' : '';

		echo '<ul class="' . esc_attr( $classes ) . '">';
		$i = 0;
		foreach ( $array as $element_id => $element ) {
			$name      = $element['name'];
			$parent_id = intval( $element['parent_id'] );
			$i ++;
			echo '<li class="' . ( isset( $element['children'] ) ? 'forpartners-categories__has-children ' : '' ) . '">';

			$checked = isset( $this->options['categories'][ $element_id ] ) ? 'checked' : '';
			?>

            <span class="forpartners-spoiler-icon-wrapper">
                <?php if ( isset( $element['children'] ) ) : ?>
                    <span class="forpartners-spoiler-icon"></span>
                <?php endif ?>
            </span>


            <label for="<?php echo intval( $element_id ) ?>">
                <input type="checkbox" name="forpartners[categories][<?php echo intval( $element_id ) ?>]"
                       id="<?php echo intval( $element_id ) ?>" value="1" <?php echo esc_attr( $checked ) ?> />
				<?php if ( $level == 0 ): ?>
                <b>
					<?php endif ?>

					<?php echo esc_html( $name ) ?> (<?php echo intval( $element_id ) ?>)

					<?php if ( $level == 0 ): ?>
                </b>
			<?php endif ?>
            </label>


			<?php
			if ( isset( $element['children'] ) ) {
				$this->display_tree_list( $element['children'], $level + 1, $parent_id );
			}

			echo '</li>';
		}
		echo '</ul>';
	}

	public function display_tree_select( array $array, $level = 0, $parent = 0 ) {
		$i = 0;
		foreach ( $array as $element_id => $element ) {
			$name      = $element['name'];
			$parent_id = intval( $element['parent_id'] );
			$i ++;
			?>

            <option data-value="<?php echo intval( $element_id ) ?>"><?php echo str_repeat( '&nbsp;', $level * 4 ) . esc_html( $name ) ?></option>
			<?php
			if ( isset( $element['children'] ) ) {
				$this->display_tree_select( $element['children'], $level + 1, $parent_id );
			}
		}
	}

	/**
	 * @param $option
	 * @param false $default
	 *
	 * @return false|mixed
	 */
	public function get_option_value( $option, $default = false ) {
		$all_options = get_option( static::OPTION_NAME, $default );

		return isset( $all_options[ $option ] ) ? $all_options[ $option ] : $default;
	}

	public function update_option_value( $name, $value ) {
		$all_options          = get_option( static::OPTION_NAME, [] );
		$all_options[ $name ] = $value;

		update_option( static::OPTION_NAME, $all_options );
	}

	/**
	 * @return string[]
	 */
	public function get_default_options() {
		return array(
			'api_key'                                => '',
			'cron'                                   => static::CRON_DAILY,
			'categories'                             => [],
			'start_sync_categories'                  => [],
			CategoryImport::IMPORT_TOKEN_OPTION_NAME => wp_generate_password( 40, false ),
		);
	}

	public function add_default_options() {
		$current_options = get_option( static::OPTION_NAME, [] );
		$default_options = $this->get_default_options();

		if ( empty( $current_options ) ) {
			add_option( static::OPTION_NAME, $default_options );
		} else {
			foreach ( $default_options as $default_option_key => $default_option_value ) {
				if ( ! isset( $current_options[ $default_option_key ] ) ) {
					$current_options[ $default_option_key ] = $default_option_value;
				}
			}

			update_option( static::OPTION_NAME, $current_options );
		}
	}

	/**
	 * Print the Section text
	 */
	public function print_section_info() {
		//print 'Enter your settings below:';
	}
}
