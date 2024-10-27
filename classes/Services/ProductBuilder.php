<?php

namespace ForPartners\Services;

use ForPartners\Models\Product;
use ForPartners\Models\ProductVariation;
use ForPartners\Plugin;

class ProductBuilder {

	/**
	 * @var Product
	 */
	private $product;

	public function __construct() {
	}

	public function create_product( $name, $brand, $description ) {
		$this->product = new Product();

		$this->product->name        = $name;
		$this->product->brand       = $brand;
		$this->product->description = $description;

		return $this;
	}

	public function add_hash( $hash ) {
		$this->product->hash = $hash;
	}

	public function add_post_id( $id ) {
		$this->product->id = $id;

		return $this;
	}

	public function add_external_id( $external_id ) {
		$this->product->external_id = $external_id;

		return $this;
	}

	public function add_variation( ProductVariation $variation ) {
		$this->product->variations[ $variation->external_id ] = $variation;

		return $this;
	}

	public function add_attributes( $attributes ) {
		$this->product->attributes = $attributes;

		return $this;
	}

	public function add_variation_attributes( $attributes ) {
		$this->product->variation_attributes = $attributes;

		return $this;
	}

	public function add_categories( $categories ) {
		$this->product->categories = $categories;

		return $this;
	}

	public function extract_product_category( $current_category_external_id ) {
		$category_service = Plugin::get_service_locator()->get( CategoryService::class );
		foreach ( $this->product->categories as $category_external_id => &$category ) {
			$category_data = $category_service->get_by_external_id( $category_external_id );
			if ( empty( $category_data ) ) {
				if ( $category['isMain'] ) {
					$this->product->categories[ $current_category_external_id ]['isMain'] = true;
				}
				unset( $this->product->categories[ $category_external_id ] );
			} else {
				$category['id'] = $category_data['id'];
			}
		}

		unset( $category );
	}

	public function add_wc_product( \WC_Product_Variable $product ) {
		$this->product->wc_product = $product;

		return $this;
	}

	public function add_image( $image ) {
		if ( is_array( $image ) ) {
			$this->product->images_data[ $image[0] ] = [ $image[1] ];

			$image = $image[0];
		}

		$this->product->images[] = $image;

		return $this;
	}

	public function get_product() {
		if ( empty( $this->product->name ) ) {
			throw new \Exception( 'Product should have name' );
		}

		return $this->product;
	}
}