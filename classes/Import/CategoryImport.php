<?php

namespace ForPartners\Import;

use ForPartners\Services\APIClient;
use ForPartners\Services\CategoryService;
use ForPartners\Services\ProductBuilder;
use ForPartners\Services\ProductService;
use ForPartners\Services\ProductVariationBuilder;
use ForPartners\Services\SettingsService;

class CategoryImport {
	const IMPORT_TOKEN_OPTION_NAME = 'import_token';
	const CATEGORY_SCROLL_PREFIX = '_4partners_category_scroll_';

	private $api_client;
	private $product_service;
	private $setting_service;

	public function __construct( APIClient $api_client, ProductService $product_service, SettingsService $setting_service ) {
		$this->api_client      = $api_client;
		$this->product_service = $product_service;
		$this->setting_service = $setting_service;
	}

	public function load_data_scroll( $category_id, $scroll, $limit ) {
		$external_category_id = get_term_meta( $category_id, CategoryService::META_4PARTNERS_ID, true );

		return $this->api_client->send_get( '/rubric/product-list-full/' . $external_category_id, [
			'scroll' => $scroll,
			'limit'  => $limit
		] );
	}

	public function process_data( $data, $data_version_id, $term_category_id ) {
		$external_category_id = get_term_meta( $term_category_id, CategoryService::META_4PARTNERS_ID, true );

		foreach ( $data['result']['items'] as $item ) {
			if ( empty( $item['variations'] ) ) {
				continue;
			}

			$item['current_parse_category'] = $external_category_id;
			$id_and_hash                    = $this->product_service->get_id_and_hash_by_external_id( $item['id'] );

			if ( empty( $id_and_hash ) ) {
				$new_product = $this->make_product_from_import_data( $item );
				$post_id     = $this->product_service->add_product( $new_product );
			} else {
				$post_id = $id_and_hash['post_id'];

				$new_hash = $this->compute_item_hash( $item );

				if ( $this->is_product_changed( $new_hash, $id_and_hash['hash'] ) ) {
					$post = get_post( $post_id );

					if ( empty( $post ) ) {
						throw new ProcessException( "Post id $post_id not found for product {$item['id']}" );
					}

					$current_product = $this->product_service->make_from_post( $post );
					$new_product     = $this->make_product_from_import_data( $item );

					$this->product_service->update_product( $current_product, $new_product );
					$this->product_service->update_meta( $post_id, ProductService::META_4PARTNERS_HASH, $new_hash );
				}
			}

			if ( ! empty( $post_id ) ) {
				//$this->update_product_version( $post_id, $data_version_id );
			}
		}

		return count( $data['result']['items'] );
	}

	public function cleanup( $category_id, $version_created_at ) {
		set_transient( CategoryImport::CATEGORY_SCROLL_PREFIX . $category_id, '', 0 );
	}

	public function get_import_token() {
		return $this->setting_service->get_option_value( static::IMPORT_TOKEN_OPTION_NAME );
	}

	private function make_product_from_import_data( $item ) {
		$product_builder = new ProductBuilder();
		$product_builder->create_product( sanitize_text_field( $item['name'] ), $item['brand'], $item['description'] )
		                ->add_external_id( $item['id'] )
		                ->add_hash( $this->compute_item_hash( $item ) );

		$categories = [];
		foreach ( $item['rubric_ids'] as $rubric_id ) {
			$categories[ $rubric_id ] = [
				'id'     => null,
				'isMain' => $rubric_id == $item['primary_rubric_id']
			];
		}

		$product_builder->add_categories( $categories );
		$product_builder->extract_product_category( $item['current_parse_category'] );

		$aspects    = array_column( $item['aspects'], 'name', 'id' );
		$params_arr = [];
		foreach ( $item['params'] as $param ) {
			if ( strlen( $param['name'] ) > 100 || empty( $param['name'] ) ) {
				continue;
			}

			$params_arr[ $param['id'] ] = [
				'value'     => $param['name'],
				'aspect_id' => $param['aspect_id'],
			];
		}

		$product_attributes   = [];
		$variation_attributes = [];
		foreach ( $item['variations'] as $variation ) {
			$variation_builder = new ProductVariationBuilder();
			$variation_builder->create_variation( $variation['price'], $variation['quantity'] )->add_external_id( $variation['id'] );

			if ( ! empty( $variation['images'] ) ) {
				foreach ( $variation['images'] as $image ) {
					$variation_builder->add_image( $image );

					if ( ! in_array( $image, $product_builder->get_product()->images ) ) {
						$product_builder->add_image( $image );
					}
				}
			}

			$attributes = [];
			foreach ( $variation['param_ids'] as $property_id ) {
				if ( empty( $params_arr[ $property_id ] ) ) {
					continue;
				}
				$property_data                                       = $params_arr[ $property_id ];
				$param_aspect                                        = $aspects[ $property_data['aspect_id'] ];
				$attributes[ $param_aspect ]                         = $property_data['value'];
				$product_attributes[ $param_aspect ][ $property_id ] = $property_data['value'];
				$variation_attributes[ $param_aspect ]               = true;
			}

			$variation_builder->add_attributes( $attributes );
			$product_builder->add_variation( $variation_builder->get_variation() );

			foreach ( $variation['property_param_ids'] as $property_id ) {
				if ( empty( $params_arr[ $property_id ] ) ) {
					continue;
				}
				$property_data                                       = $params_arr[ $property_id ];
				$param_aspect                                        = $aspects[ $property_data['aspect_id'] ];
				$product_attributes[ $param_aspect ][ $property_id ] = $property_data['value'];
			}
		}

		$product_builder->add_variation_attributes( $variation_attributes );
		$product_builder->add_attributes( $product_attributes );

		return $product_builder->get_product();
	}

	private function update_product_version( $post_id, $data_version_id ) {
		$this->product_service->update_meta( $post_id, ProductService::META_4PARTNERS_VERSION, $data_version_id );

		$variations = get_posts( [
			'post_type'   => 'product_variation',
			'post_status' => [ 'private', 'publish' ],
			'numberposts' => - 1,
			'orderby'     => 'menu_order',
			'order'       => 'ASC',
			'post_parent' => $post_id
		] );

		foreach ( $variations as $variation ) {
			$this->product_service->update_meta( $variation->ID, ProductService::META_4PARTNERS_VERSION, $data_version_id );
		}
	}

	private function is_product_changed( $new_hash, $old_hash ) {
		return $old_hash !== $new_hash;
	}

	private function compute_item_hash( $item ) {
		$fields_for_signing = [
			'name',
			'brand',
			'rubric_ids',
			'primary_rubric_id',
			'description',
			'variations',
			'aspects',
			'params'
		];

		$data_for_signing = [];
		foreach ( $fields_for_signing as $content_field ) {
			$data_for_signing[ $content_field ] = $item[ $content_field ];
		}
		$json_sign = json_encode( $data_for_signing );
		$new_hash  = md5( $json_sign );

		return $new_hash;
	}
}