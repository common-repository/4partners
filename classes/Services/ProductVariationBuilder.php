<?php

namespace ForPartners\Services;

use ForPartners\Models\ProductVariation;

class ProductVariationBuilder {

	/**
	 * @var ProductVariation
	 */
	private $variation;

	public function __construct() {
	}

	public function create_variation( $price, $quantity ) {
		$this->variation        = new ProductVariation();
		$this->variation->price = $price;

		if ( $quantity == '' ) {
			$quantity = 5;
		}

		$this->variation->quantity = $quantity;

		return $this;
	}

	public function add_external_id( $external_id ) {
		$this->variation->external_id = $external_id;

		return $this;
	}

	public function add_post_id( $post_id ) {
		$this->variation->id = $post_id;

		return $this;
	}

	public function add_attributes( $attributes ) {
		$this->variation->attributes = $attributes;

		return $this;
	}

	public function add_image( $image_url ) {
		$this->variation->images[] = $image_url;

		return $this;
	}

	public function add_wc_product( $wc_product ) {
		$this->variation->wc_product = $wc_product;

		return $this;
	}

	public function get_variation() {
		if ( empty( $this->variation->price ) ) {
			throw new \Exception( 'Product variation should have price' );
		}

		return $this->variation;
	}
}