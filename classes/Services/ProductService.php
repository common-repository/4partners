<?php

namespace ForPartners\Services;

use ForPartners\Models\Product;
use ForPartners\Models\ProductVariation;

class ProductService {
	const PRODUCT_TYPE = 'product';

	const META_4PARTNERS_ID = '_4partners_id';
	const META_4PARTNERS_HASH = '_4partners_hash';
	const META_4PARTNERS_IMAGES = '_4partners_images';
	const META_4PARTNERS_VARIATION_IMAGES = '_4partners_variant_images';
	const META_GALLERY = '_product_image_gallery';
	const META_THUMBNAIL = '_thumbnail_id';
	const META_4PARTNERS_BRAND = '_4partners_brand';
	const META_4PARTNERS_PRIMARY_CATEGORY = '_4partners_primary_category';
	const META_4PARTNERS_VERSION = '_4partners_version';
	const META_4PARTNERS_FIXED_FIELDS = '_4partners_fixed_fields';

	public $attribute_service;
	public $category_service;

	public function __construct( AttributeService $attribute_service, CategoryService $category_service ) {
		$this->attribute_service = $attribute_service;
		$this->category_service  = $category_service;

		$this->add_extra_price_filter();
	}

	public function init() {
		if ( is_admin() ) {
			add_action( 'add_meta_boxes', [ $this, 'add_meta_boxes' ] );
			add_action( 'save_post_' . static::PRODUCT_TYPE, [ $this, 'save_metabox' ] );
		}
	}

	public function add_meta_boxes() {
		add_meta_box( '4partners_category_box', '4Partners', [
			$this,
			'render_metabox'
		], static::PRODUCT_TYPE, 'advanced', 'high' );
	}

	public function render_metabox( $post ) {
		$fixed_fields = get_post_meta( $post->ID, static::META_4PARTNERS_FIXED_FIELDS, true );
		?>
        <table class="form-table company-info">
            <tr>
                <th>
					<?php _e( 'Fix product field values', '4partners' ) ?>
                </th>
                <td>
                    <input type="hidden" name="<?php echo static::META_4PARTNERS_FIXED_FIELDS ?>"/>
                    <select multiple="multiple" name="<?php echo static::META_4PARTNERS_FIXED_FIELDS ?>[]"
                            id="<?php echo static::META_4PARTNERS_FIXED_FIELDS ?>" style="width: 350px;">
                        <option value="name" <?php echo in_array( 'name', (array) $fixed_fields ) ? 'selected' : '' ?>>
							<?php _e( 'Title', '4partners' ) ?>
                        </option>
                        <option value="content" <?php echo in_array( 'content', (array) $fixed_fields ) ? 'selected' : '' ?>>
							<?php _e( 'Description', '4partners' ) ?>
                        </option>
                        <option value="category" <?php echo in_array( 'category', (array) $fixed_fields ) ? 'selected' : '' ?>>
							<?php _e( 'Category', '4partners' ) ?>
                        </option>
                    </select>

                    <script type="text/javascript">
                        jQuery(function () {
                            jQuery('#<?php echo static::META_4PARTNERS_FIXED_FIELDS ?>').select2();
                        });
                    </script>
                </td>
            </tr>

        </table>
		<?php
	}

	public function save_metabox( $post_id ) {

		// Check if it's not an autosave.
		if ( wp_is_post_autosave( $post_id ) ) {
			return;
		}

		if ( isset( $_POST[ static::META_4PARTNERS_FIXED_FIELDS ] ) ) {
			if ( is_array( $_POST[ static::META_4PARTNERS_FIXED_FIELDS ] ) ) {
				$new_fields = array_map( 'sanitize_text_field', $_POST[ static::META_4PARTNERS_FIXED_FIELDS ] );
			} else {
				$new_fields = [];
			}

			$old_fields = get_post_meta( $post_id, static::META_4PARTNERS_FIXED_FIELDS, true );

			if ( $old_fields != $new_fields ) {
				if ( empty( $new_fields ) ) {
					delete_post_meta( $post_id, static::META_4PARTNERS_FIXED_FIELDS );
				} else {
					$new_fields = array_map( 'sanitize_text_field', $new_fields );
					update_post_meta( $post_id, static::META_4PARTNERS_FIXED_FIELDS, $new_fields );
				}

				// check if some field was deleted, so we should reset product hash for forcibly updating product next time
				if ( ! empty( array_diff( $old_fields, $new_fields ) ) ) {
					$this->update_meta( $post_id, static::META_4PARTNERS_HASH, '' );
				}

				clean_post_cache( $post_id );
			}
		}
	}

	public function add_extra_price( $price, $product ) {
		if ( ! is_numeric( $price ) ) {
			return $price;
		}

		$product_id          = is_a( $product, \WC_Product_Variable::class ) ? $product->get_parent_id() : $product->get_id();
		$primary_category_id = get_post_meta( $product_id, static::META_4PARTNERS_PRIMARY_CATEGORY, true );

		if ( empty( $primary_category_id ) ) {
			return $price;
		}

		$extra_price_data = get_term_meta( $primary_category_id, CategoryService::META_EXTRA_PRICE, true );
		if ( empty( $extra_price_data ) ) {
			return $price;
		}

		$extra_price      = explode( '|', $extra_price_data );
		$extra_price_num  = $extra_price[0];
		$extra_price_type = $extra_price[1];
		$extra_price_min  = $extra_price[2];

		if ( empty( $extra_price_num ) ) {
			return $price;
		}

		if ( $extra_price_type === 'percent' ) {
			$price_percent = $price * $extra_price_num / 100;
			$price         += ( ! empty( $extra_price_min ) && $price_percent < $extra_price_min ) ? $extra_price_min : $price_percent;
		} elseif ( $extra_price_type === 'sum' ) {
			$price += $extra_price_num;
		}

		return round( $price, 0 );
	}

	public function get_id_and_hash_by_external_id( $external_id ) {
		global $wpdb;

		$external_id = intval( $external_id );

		return $wpdb->get_row( "SELECT t1.post_id AS 'post_id', t2.meta_value AS 'hash' FROM {$wpdb->postmeta} AS t1
												JOIN {$wpdb->postmeta} AS t2 ON t1.post_id = t2.post_id AND t2.meta_key = '" . static::META_4PARTNERS_HASH . "'
												WHERE t1.`meta_key` = '" . static::META_4PARTNERS_ID . "'  AND t1.`meta_value` = '$external_id'", ARRAY_A );
	}


	public function make_from_post( \WP_Post $post ) {
		$product_builder = new ProductBuilder();
		$brand           = get_post_meta( $post->ID, static::META_4PARTNERS_BRAND, true );
		$external_id     = get_post_meta( $post->ID, static::META_4PARTNERS_ID, true );
		$images          = get_post_meta( $post->ID, static::META_4PARTNERS_IMAGES, true );

		$product_builder->create_product( $post->post_title, $brand, $post->post_content )
		                ->add_post_id( $post->ID )
		                ->add_external_id( $external_id );

		foreach ( $images as $image ) {
			$product_builder->add_image( $image );
		}

		$wc_product          = new \WC_Product_Variable( $post->ID );
		$primary_category_id = get_post_meta( $post->ID, static::META_4PARTNERS_PRIMARY_CATEGORY, true );
		$categories_id       = $wc_product->get_category_ids();
		$categories          = [];
		foreach ( $categories_id as $category_id ) {
			$external_id                = get_term_meta( $category_id, CategoryService::META_4PARTNERS_ID, true );
			$categories[ $external_id ] = [
				'id'     => $category_id,
				'isMain' => $category_id == $primary_category_id
			];
		}
		$product_builder->add_categories( $categories );
		$product_builder->add_wc_product( $wc_product );

		$variations = $wc_product->get_children();
		foreach ( $variations as $variation_post_id ) {
			$wc_variation = new \WC_Product_Variation( $variation_post_id );
			$price        = $wc_variation->get_price();
			$quantity     = $wc_variation->get_stock_quantity();

			$wc_attributes = $wc_variation->get_attributes();
			$attributes    = [];
			foreach ( $wc_attributes as $taxonomy_slug => $term_slug ) {
				$taxonomy = get_taxonomy( $taxonomy_slug );
				$term     = get_term_by( 'slug', $term_slug, $taxonomy_slug );

				$attributes[ $taxonomy->labels->singular_name ] = $term->name;
			}

			$variation_builder = new ProductVariationBuilder();
			$variation_builder->create_variation( $price, $quantity )
			                  ->add_post_id( $variation_post_id )
			                  ->add_external_id( $wc_variation->get_meta( static::META_4PARTNERS_ID ) )
			                  ->add_wc_product( $wc_variation )
			                  ->add_attributes( $attributes );
			$product_builder->add_variation( $variation_builder->get_variation() );
		}

		return $product_builder->get_product();
	}

	public function get_variation_meta_property_names() {
		return [
			'_price'         => 'price',
			'_regular_price' => 'price',
			'_stock'         => 'quantity',
		];
	}

	public function get_meta_property_names() {
		return [
			static::META_4PARTNERS_BRAND  => 'brand',
			static::META_4PARTNERS_IMAGES => 'images',
		];
	}

	public function add_product( Product $product ) {
		if ( count( $product->variations ) > 1 ) {
			$new_product = new \WC_Product_Variable();
			$new_product->set_manage_stock( false );
		} else {
			$new_product = new \WC_Product_Simple();
			$variations  = $product->variations;
			$variation   = array_shift( $variations );
			$new_product->set_price( $variation->price );
			$new_product->set_regular_price( $variation->price );
			$new_product->set_stock_quantity( $variation->quantity );
			$new_product->set_manage_stock( true );
			$new_product->set_stock_status( '' );
			$new_product->set_weight( '' );
		}

		$product_attributes = $this->attribute_service->create_product_attributes( $product );
		$new_product->set_attributes( $product_attributes );

		$new_product->set_name( $product->name );
		$new_product->set_sku( $product->external_id );
		$new_product->set_description( $product->description );

		/*
		 * see update_meta method
		if ( ! empty( $product->images ) ) {
			if ( count( $product->images ) > 1 ) {
				$new_product->set_gallery_image_ids( range( 2, count( $product->images ) ) );
			}
			//$new_product->set_gallery_image_ids( range( 1, count( $product->images ) ) );
			$new_product->set_image_id( 1 );
		}
		*/

		$categories = [];
		foreach ( $product->categories as $category_external_id => $category ) {
			if ( $category['isMain'] ) {
				$primary_category_id = $category['id'];
			}
			$categories[] = $category['id'];
		}
		$new_product->set_category_ids( $categories );

		$product_id = $new_product->save();

		if ( $product_id > 0 ) {
			$this->update_meta( $product_id, static::META_4PARTNERS_ID, $product->external_id );
			$this->update_meta( $product_id, static::META_4PARTNERS_BRAND, $product->brand );
			$this->update_meta( $product_id, static::META_4PARTNERS_HASH, $product->hash );
			$this->update_meta( $product_id, static::META_4PARTNERS_IMAGES, $product->images );

			if ( ! empty( $primary_category_id ) ) {
				$this->update_meta( $product_id, static::META_4PARTNERS_PRIMARY_CATEGORY, $primary_category_id );
			}

			$product->id = $product_id;

			if ( count( $product->variations ) > 1 ) {
				foreach ( $product->variations as $variation ) {
					$this->add_variation( $product, $variation );
				}
			}
		}

		return $product_id;

		//wp_cache_flush();
		//wp_cache_delete( $product_id, 'posts' );
		//wp_cache_delete( $product_id, 'post_meta' );
	}

	public function add_variation( Product $product, ProductVariation $variation ) {
		$new_variation = new \WC_Product_Variation();
		$new_variation->set_parent_id( $product->id );
		$new_variation->set_sku( $variation->external_id );
		$new_variation->set_price( $variation->price );
		$new_variation->set_regular_price( $variation->price );
		$new_variation->set_stock_quantity( $variation->quantity );
		$new_variation->set_manage_stock( true );
		$new_variation->set_weight( '' );
		$new_variation->add_meta_data( static::META_4PARTNERS_ID, $variation->external_id );

		$attributes = $this->extract_variation_attributes( $variation->attributes );

		if ( ! empty( $attributes ) ) {
			$new_variation->set_attributes( $attributes );
		}

		$post_id = $new_variation->save();


		if ( ! empty( $post_id ) && $variation->images != $product->images ) {
			$variant_images_indexes = [];
			foreach ( $variation->images as $image ) {
				$key = array_search( $image, $product->images );
				if ( $key !== false ) {
					$variant_images_indexes[] = $key + 1;
				}
			}
			if ( ! empty( $variant_images_indexes ) ) {
				$this->update_meta( $post_id, static::META_4PARTNERS_VARIATION_IMAGES, $variant_images_indexes );
			}
		}

		return $post_id;
	}

	public function update_product( Product $current_product, Product $new_product ) {
		$product_meta_properties   = $this->get_meta_property_names();
		$variation_meta_properties = $this->get_variation_meta_property_names();
		$fixed_fields              = get_post_meta( $current_product->id, static::META_4PARTNERS_FIXED_FIELDS, true );
		$fixed_fields              = empty( $fixed_fields ) ? [] : $fixed_fields;
		$diff_new_to_old           = $this->get_diff_prop_between_products( $new_product, $current_product );
		$diff_old_to_new           = $this->get_diff_prop_between_products( $current_product, $new_product );

		$product_type     = $current_product->wc_product->get_type();
		$variable_product = new \WC_Product_Variable();
		$simple_product   = new \WC_Product_Simple();

		if ( $product_type == $simple_product->get_type() && count( $new_product->variations ) > 1 ) {
			$new_product_type = $variable_product->get_type();
		}

		if ( empty( $new_product_type ) ) {
			$wc_product = $current_product->wc_product;
		} else {
			$product_classname = \WC_Product_Factory::get_product_classname( $current_product->id, $new_product_type );
			$wc_product        = new $product_classname( $current_product->id );
		}

		$product_attributes_was_updated = false;

		if ( ! empty( $diff_old_to_new['variations'] ) ) {
			foreach ( $diff_old_to_new['variations'] as $variation ) {
				if ( $variation instanceof ProductVariation ) {
					// delete
					$variation->wc_product->delete( true );

					if ( ! $product_attributes_was_updated ) {
						$new_attributes = $this->attribute_service->create_product_attributes( $new_product );
						$current_product->wc_product->set_attributes( $new_attributes );
						$product_attributes_was_updated = true;
					}
				}
			}
		}

		if ( $new_product_type == $variable_product->get_type() ) {
			$wc_product->set_manage_stock( false );
		}

		if ( ! empty( $diff_old_to_new['categories'] ) ) {
			$new_categories_id = [];
			foreach ( $new_product->categories as $category_external_id => $category ) {
				$new_categories_id[] = $category['id'];
				if ( $category['isMain'] ) {
					$this->update_meta( $current_product->id, static::META_4PARTNERS_PRIMARY_CATEGORY, $category['id'] );
				}
			}

			$wc_product->set_category_ids( $new_categories_id );
		}

		foreach ( $diff_new_to_old as $diff_prop_name => $new_value ) {
			$meta_key = array_search( $diff_prop_name, $product_meta_properties );
			if ( $meta_key !== false ) {
				if ( $meta_key == ProductService::META_4PARTNERS_IMAGES ) {
					$new_value = $new_product->images;
				}

				$this->update_meta( $current_product->id, $meta_key, $new_value );
			}

			if ( $diff_prop_name == 'description' ) {
				if ( ! in_array( 'content', $fixed_fields ) ) {
					$wc_product->set_description( $new_product->description );
				}
			}

			if ( $diff_prop_name == 'name' ) {
				if ( ! in_array( 'name', $fixed_fields ) ) {
					$wc_product->set_name( $new_product->name );
				}
			}
		}

		if ( ! empty( $diff_new_to_old['variations'] ) ) {
			foreach ( $diff_new_to_old['variations'] as $variation_external_id => $variation ) {
				if ( $variation instanceof ProductVariation ) {
					if ( ! $product_attributes_was_updated ) {
						$new_attributes = $this->attribute_service->create_product_attributes( $new_product );
						$current_product->wc_product->set_attributes( $new_attributes );
						$product_attributes_was_updated = true;
					}
					$this->add_variation( $current_product, $variation );
				} else {
					$wc_variation         = $current_product->variations[ $variation_external_id ]->wc_product;
					$wc_variation_changed = false;

					foreach ( $variation as $field => $value ) {
						$meta_keys = array_keys( $variation_meta_properties, $field );
						foreach ( $meta_keys as $meta_key ) {
							$this->update_meta( $variation['id'], $meta_key, $value );
						}
					}

					if ( ! empty( $variation['quantity'] ) ) {
						if ( $wc_product->get_type() == $simple_product->get_type() ) {
							$wc_product->set_stock_quantity( $variation['quantity'] );
						} else {
							$wc_variation->set_stock_quantity( $variation['quantity'] );
							$wc_variation_changed = true;
						}
					}

					if ( ! empty( $variation['price'] ) ) {
						if ( $wc_product->get_type() == $simple_product->get_type() ) {
							$wc_product->set_price( $variation['price'] );
							$wc_product->set_regular_price( $variation['price'] );
						} else {
							$wc_variation->set_price( $variation['price'] );
							$wc_variation->set_regular_price( $variation['price'] );
							$wc_variation_changed = true;
						}
					}

					if ( ! empty( $variation['attributes'] ) ) {

						if ( ! $product_attributes_was_updated ) { //todo: fix this
							$new_attributes = $this->attribute_service->create_product_attributes( $new_product );
							$current_product->wc_product->set_attributes( $new_attributes );
							$product_attributes_was_updated = true;
						}
					}

					if ( $wc_variation_changed ) {
						$wc_variation->save();
					}

				}
			}
		}

		$wc_product->save();
	}

	public function extract_variation_attributes( $attributes ) {
		$result = [];
		foreach ( $attributes as $attribute_label => $attribute_value ) {
			$attribute_data = $this->attribute_service->get_attribute_by_label( $attribute_label );

			if ( ! isset( $attribute_data['terms'][ $attribute_value ] ) ) {
				throw new \Exception( 'Variation attribute term not found by name ' . $attribute_value );
			}

			$result[ $attribute_data['taxonomy'] ] = $attribute_data['terms'][ $attribute_value ];
		}

		return $result;
	}


	public function update_meta( $post_id, $meta_key, $meta_value ) {
		if ( $meta_key == static::META_4PARTNERS_IMAGES ) {
			$gallery_str     = '';
			$gallery_counter = 1;
			foreach ( $meta_value as $k => $image_url ) {
				$size       = getimagesize( $image_url );
				$image_size = $size ? [ $size[0], $size[1] ] : [ 100, 100 ];

				$meta_value[ $k ] = [ $image_url, $image_size ];
				if ( $gallery_counter > 1 ) {
					$gallery_str .= $gallery_counter . ',';
				}

				$gallery_counter ++;
			}

			if ( empty( $meta_value ) ) {
				delete_post_meta( $post_id, static::META_THUMBNAIL );
				delete_post_meta( $post_id, static::META_GALLERY );
			} else {
				$this->update_meta( $post_id, static::META_THUMBNAIL, 1 );
				$this->update_meta( $post_id, static::META_GALLERY, trim( $gallery_str, ',' ) );
			}
		}

		update_post_meta( $post_id, $meta_key, $meta_value );
	}

	public function get_diff_prop_between_products( $product_1, $product_2 ) {
		$a1 = (array) $product_1;
		$a2 = (array) $product_2;

		$skip_fields = [ 'wc_product' ];

		$r = array();
		foreach ( $a1 as $k => $v ) {
			if ( array_key_exists( $k, $a2 ) ) {
				if ( $v instanceof ProductVariation ) {
					$v = (array) $v;
				}
				if ( is_array( $v ) ) {
					$rad = $this->get_diff_prop_between_products( $v, $a2[ $k ] );
					if ( count( $rad ) ) {
						$r[ $k ] = $rad;
					}
				} else {
					if ( $v != $a2[ $k ] ) {
						$r[ $k ] = $k === 'id' ? $a2[ $k ] : $v;
					}
				}
			} elseif ( ! in_array( $k, $skip_fields ) ) {
				$r[ $k ] = $v;
			}
		}

		return $r;
	}

	private function add_extra_price_filter() {
		add_filter( 'woocommerce_product_get_price', [ $this, 'add_extra_price' ], 10, 2 );
		add_filter( 'woocommerce_product_get_regular_price', [ $this, 'add_extra_price' ], 10, 2 );
		add_filter( 'woocommerce_product_get_sale_price', [ $this, 'add_extra_price' ], 10, 2 );
		add_filter( 'woocommerce_product_variation_get_price', [ $this, 'add_extra_price' ], 10, 2 );
		add_filter( 'woocommerce_variation_prices_regular_price', [ $this, 'add_extra_price' ], 10, 2 );
		add_filter( 'woocommerce_variation_prices_price', [ $this, 'add_extra_price' ], 10, 2 );
		add_filter( 'woocommerce_variation_prices_sale_price', [ $this, 'add_extra_price' ], 10, 2 );
	}
}