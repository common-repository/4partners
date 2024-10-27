<?php

namespace ForPartners\Services;

use ForPartners\Import\ProcessException;
use ForPartners\Plugin;

class CategoryService {
	const CACHE_KEY = '4partners_categories';
	const API_CACHE_KEY = '4partners_api_categories';
	const META_4PARTNERS_ID = '_4partners_id';
	const META_EXTRA_PRICE = '_extra_price';

	private $api_client;
	private $current_categories;

	public function __construct( APIClient $api_client ) {
		$this->api_client = $api_client;
	}

	public function init() {
		if ( is_admin() ) {
			add_action( 'product_cat_edit_form_fields', [ $this, 'product_cat_edit_form_fields' ] );
			add_action( 'edited_product_cat', [ $this, 'edited_product_cat' ] );
			add_action( 'delete_product_cat', [ $this, 'delete_category' ], 10, 4 );

			add_filter( 'pre_update_option_' . SettingsService::OPTION_NAME, [ $this, 'update_categories' ], 10, 2 );
		}
	}

	public function delete_category( $term, $tt_id, \WP_Term $deleted_term, $object_ids ) {
		$settings_service = Plugin::get_service_locator()->get( SettingsService::class );
		$categories       = $settings_service->get_option_value( 'categories' );
		$external_id      = array_search( $deleted_term->term_id, $categories );

		if ( $external_id !== false ) {
			unset( $categories[ $external_id ] );
			$settings_service->update_option_value( 'categories', $categories );
		}

		set_transient( static::CACHE_KEY, false, 1 * HOUR_IN_SECONDS );
	}

	public function update_categories( $new_value, $old_value ) {

		if ( $old_value["categories"] !== $new_value["categories"] ) {
			ini_set( 'memory_limit', '1024M' );
			set_time_limit( 3600 );

			$current_categories  = $this->get_all_terms();
			$external_categories = $this->get_all_from_api();

			$count = 0;
			foreach ( $new_value["categories"] as $external_id => &$category_data ) {
				$count ++;
				if ( isset( $current_categories[ $external_id ] ) ) {
					$category_data = $current_categories[ $external_id ]['id'];
				} else {
					$new_category  = $this->create_category( $external_id, $external_categories );
					$category_data = $new_category['id'];
				}

				if ( $count % 100 == 0 ) {
					wp_cache_flush();
				}
			}
		}

		/*
		if ( $old_value["categories"] !== $new_value["categories"] ) {
			foreach ( $new_value["categories"] as $external_id => &$category_data ) {
				if ( $category_data == 1 ) {
					$current_category = $this->get_by_external_id( $external_id );
					if ( empty( $current_category['id'] ) ) {
						$result        = $this->add( $external_id );
						$category_data = $result['id'];
					}
				}
			}
		}*/

		return $new_value;
	}

	public function create_category( $external_id, $external_categories ) {
		if ( ! isset( $external_categories[ $external_id ] ) ) {
			return null;
		}

		$parent_term_id = null;
		if ( ! empty( $external_categories[ $external_id ]['parent_id'] ) ) {
			$parent_external_id = $external_categories[ $external_id ]['parent_id'];

			while ( true ) {
				if ( isset( $this->current_categories[ $parent_external_id ] ) ) {
					$parent_term_id = $this->current_categories[ $parent_external_id ]['id'];
					break;
				} else {
					$parent_external_id = $external_categories[ $parent_external_id ]['parent_id'];
					if ( empty( $parent_external_id ) ) {
						break;
					}
				}
			}
		}

		$args = [];

		if ( ! empty( $parent_term_id ) ) {
			$args['parent'] = $parent_term_id;
		}

		$name = $external_categories[ $external_id ]['name'];

		$parent_id = $external_categories[ $external_id ]['parent_id'];
		$suffix    = '';
		while ( true ) {
			$term_data = wp_insert_term( $name . $suffix, 'product_cat', $args );
			if ( ! is_wp_error( $term_data ) ) {
				break;
			}

			if ( isset( $term_data->errors['term_exists'] ) ) {
				if ( empty( $parent_id ) ) {
					$suffix = ' ' . rand( 0, 100 );
				} else {
					$suffix    = ' ' . $external_categories[ $parent_id ]['name'];
					$parent_id = $external_categories[ $parent_id ]['parent_id'];
				}
			} else {
				throw new ProcessException( $term_data->get_error_message() . ' ' . $name );
			}
		}

		update_term_meta( $term_data['term_id'], static::META_4PARTNERS_ID, $external_id );

		$result                                   = [
			'id'        => $term_data['term_id'],
			'name'      => $name,
			'parent_id' => $parent_term_id
		];
		$this->current_categories[ $external_id ] = $result;

		return $result;
	}

	public function product_cat_edit_form_fields( \WP_Term $term ) {
		$extra_price_num  = 0;
		$extra_price_type = 'percent';
		$extra_price_min  = 0;

		$data = get_term_meta( $term->term_id, static::META_EXTRA_PRICE, true );
		$data = explode( '|', $data );

		if ( ! empty( $data[0] ) ) {
			$extra_price_num  = $data[0];
			$extra_price_type = $data[1];
			$extra_price_min  = $data[2];
		}

		?>
        <tr class="form-field">
            <th scope="row">
                <label for="extra_price"><?php _e( '4partners price adjustment', '4partners' ) ?></label></th>
            <td>
                <div style="display: flex;align-items: center;">
                    <input type="number" name="extra_price_num" style="max-width: 100px;" min="0"
                           value="<?php echo intval( $extra_price_num ); ?>"/>&nbsp;&nbsp;
                    <select name='extra_price_type' id='extra_price_type'>
                        <option value="percent" <?php echo $extra_price_type === 'percent' ? 'selected="selected"' : ''; ?>>
                            %
                        </option>
                        <option value="sum" <?php echo $extra_price_type === 'sum' ? 'selected="selected"' : ''; ?>>₽
                        </option>
                    </select>
                    &nbsp;&nbsp;<?php _e( 'No less than', '4partners' ) ?>&nbsp;&nbsp;
                    <input type="number" name="extra_price_min" style="max-width: 100px;" min="0"
                           value="<?php echo intval( $extra_price_min ); ?>" <?php echo $extra_price_type === 'sum' ? 'disabled="disabled"' : ''; ?> />&nbsp;&nbsp;₽
                </div>
            </td>
        </tr>
		<?php
	}

	public function edited_product_cat( $term_id ) {
		if ( ! in_array( $_POST['extra_price_type'], [ 'percent', 'sum' ] ) ) {
			return;
		}

		$saved_data = intval( $_POST['extra_price_num'] ) . '|' . $_POST['extra_price_type'] . '|' . intval( $_POST['extra_price_min'] );

		if ( empty( $_POST['extra_price_num'] ) ) {
			delete_term_meta( $term_id, static::META_EXTRA_PRICE );
		} else {
			update_term_meta( $term_id, static::META_EXTRA_PRICE, $saved_data );
		}
	}

	public function get_all_terms() {
		if ( $this->current_categories == null ) {
			global $wpdb;

			$current_categories = $wpdb->get_results(
				"SELECT t1.term_id id, t1.meta_value external_id, t2.name, t3.parent parent_id
                                    FROM {$wpdb->termmeta} t1
                                    JOIN {$wpdb->terms} t2 ON t1.term_id = t2.term_id
                                    JOIN {$wpdb->term_taxonomy} t3 ON t1.term_id = t3.term_id
                                    WHERE meta_key = '_4partners_id'
                                    ORDER BY t1.term_id ASC",
				ARRAY_A
			);

			$this->current_categories = array_column( $current_categories, null, 'external_id' );
		}

		return $this->current_categories;
	}

	public function get_by_external_id( $external_id ) {
		$result = null;

		$all_terms = $this->get_all_terms();

		if ( ! empty( $all_terms[ $external_id ] ) ) {
			$result['id'] = $all_terms[ $external_id ]['id'];
		}

		return $result;
	}

	public function get_all_from_api( $with_cache = true ) {
		$cached = get_transient( static::API_CACHE_KEY );

		if ( ! empty( $cached ) && $with_cache ) {
			return $cached;
		}

		$response = $this->api_client->send_get( '/rubric/all' );

		$result = [];
		if ( ! empty( $response['result']['items'] ) ) {

			foreach ( $response['result']['items'] as $item ) {
				$result[ $item['id'] ] = [ 'name' => $item['name'], 'parent_id' => $item['parent_id'] ];
			}

			set_transient( static::API_CACHE_KEY, $result, 1 * HOUR_IN_SECONDS );
		}

		return $result;
	}

	public function make_category_tree( array &$elements, $parentId = 0 ) {
		$branch = array();

		foreach ( $elements as $element_id => $element ) {
			if ( $element['parent_id'] == $parentId ) {
				$children = $this->make_category_tree( $elements, $element_id );
				if ( $children ) {
					$element['children'] = $children;
				}
				$branch[ $element_id ] = $element;
				unset( $elements[ $element_id ] );
			}
		}

		return $branch;
	}
}