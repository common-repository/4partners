<?php

namespace ForPartners\Services;

use ForPartners\Models\Product;

class AttributeService {

	private $attributes_by_label;

	public function create_product_attributes( Product $product ) {
		$attributes = [];

		foreach ( $product->attributes as $attribute_label => $attribute_values ) {
			$attribute_data = $this->get_attribute_taxonomy_by_raw_name( $attribute_label );

			if ( empty( $attributes[ $attribute_data['attribute_id'] ] ) ) {
				$attributes[ $attribute_data['attribute_id'] ] = [
					'taxonomy' => $attribute_data['taxonomy'],
					'name'     => $attribute_label,
					'terms'    => [],
				];
			}

			foreach ( $attribute_values as $attribute_value ) {
				if ( ! isset( $this->attributes_by_label[ $attribute_label ]['terms'][ $attribute_value ] ) ) {
					$term_data = get_term_by( 'name', $attribute_value, $attribute_data['taxonomy'] );

					if ( ! $term_data ) {
						$term_insert = wp_insert_term( $attribute_value, $attribute_data['taxonomy'] );

						if ( $term_insert instanceof \WP_Error ) {
							throw new \Exception( $term_insert->get_error_message() . ' Term: ' . $attribute_value . ' Taxonomy: ' . $attribute_data['taxonomy'] . ' Product external id: ' . $product->external_id );
						}

						$term_data = get_term_by( 'id', $term_insert['term_id'], $attribute_data['taxonomy'] );

						//$term_slug = sanitize_title( $attribute_value ); // this will not work if slug duplicated and was generated "slug-2"
					}

					$this->attributes_by_label[ $attribute_label ]['terms'][ $attribute_value ] = $term_data->slug;
				}

				if ( ! in_array( $attribute_value, $attributes[ $attribute_data['attribute_id'] ]['terms'] ) ) {
					$attributes[ $attribute_data['attribute_id'] ]['terms'][] = $attribute_value;
				}
			}
		}

		$result = [];

		foreach ( $attributes as $attribute_id => $attribute ) {
			$wc_attribute = new \WC_Product_Attribute();
			$wc_attribute->set_id( $attribute_id );
			$wc_attribute->set_name( $attribute['taxonomy'] );
			$wc_attribute->set_options( $attribute['terms'] );
			$wc_attribute->set_position( 0 );
			$wc_attribute->set_visible( 1 );

			if ( isset( $product->variation_attributes[ $attribute['name'] ] ) ) {
				$wc_attribute->set_variation( 1 );
			}

			$result[] = $wc_attribute;
		}

		return $result;
	}

	public function get_attribute_taxonomy_by_raw_name( $raw_name ) {
		$attributes = $this->get_product_attributes_by_label();

		if ( ! isset( $attributes[ $raw_name ] ) ) {
			$new_attribute = $this->create_attribute( $raw_name );
		}

		return [
			'taxonomy'     => $this->attributes_by_label[ $raw_name ]['taxonomy'],
			'attribute_id' => $this->attributes_by_label[ $raw_name ]['id']
		];
	}

	public function create_attribute( $raw_name ) {
		$attribute_name = $this->generate_attribute_name( $raw_name );
		$taxonomy_name  = wc_attribute_taxonomy_name( $attribute_name );

		$args = array(
			'slug'         => $attribute_name,
			'name'         => $raw_name,
			'type'         => 'select',
			'orderby'      => 'menu_order',
			'has_archives' => false,
		);

		$attribute_id = wc_create_attribute( $args );

		if ( $attribute_id instanceof \WP_Error ) {
			throw new \Exception( $attribute_id->get_error_message() );
		}

		$result = register_taxonomy(
			$taxonomy_name,
			apply_filters( 'woocommerce_taxonomy_objects_' . $taxonomy_name, array( 'product' ) ),
			apply_filters(
				'woocommerce_taxonomy_args_' . $taxonomy_name,
				array(
					'labels'       => array(
						'name' => $raw_name,
					),
					'hierarchical' => false,
					'show_ui'      => false,
					'query_var'    => true,
					'rewrite'      => false,
				)
			)
		);

		if ( $result instanceof \WP_Error ) {
			throw new \Exception( $result->get_error_message() );
		}

		// add to cache
		$this->attributes_by_label[ $raw_name ] = [
			'id'       => $attribute_id,
			'name'     => $attribute_name,
			'taxonomy' => $taxonomy_name,
		];

		return [ 'taxonomy' => $taxonomy_name, 'attribute_id' => $attribute_id ];
	}

	public function generate_attribute_name( $raw_name ) {
		$attribute_names = array_column( $this->get_product_attributes_by_label(), 'name' );

		$name        = mb_substr( wc_sanitize_taxonomy_name( $raw_name ), 0, 25, 'utf-8' );
		$unique_name = $name;

		$i = 0;
		while ( in_array( $unique_name, $attribute_names ) ) {
			$i ++;
			$unique_name = $name . '-' . $i;
		}

		return $unique_name;
	}

	public function get_product_attributes_by_label() {
		if ( $this->attributes_by_label === null ) {
			$attributes = $this->get_attribute_taxonomies();
			foreach ( $attributes as $attribute ) {
				$this->attributes_by_label[ $attribute->attribute_label ] = [
					'id'       => $attribute->attribute_id,
					'name'     => $attribute->attribute_name,
					'taxonomy' => wc_attribute_taxonomy_name( $attribute->attribute_name ),
				];
			}
		}

		return $this->attributes_by_label;
	}

	public function get_attribute_by_label( $label ) {
		$attributes = $this->get_product_attributes_by_label();

		return $attributes[ $label ];
	}

	public function get_attribute_taxonomies() {
		return wc_get_attribute_taxonomies();
	}
}