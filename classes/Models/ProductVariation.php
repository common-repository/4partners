<?php

namespace ForPartners\Models;

class ProductVariation {
	public $id;
	public $external_id;
	public $images;
	public $attributes;
	public $price;
	public $quantity;

	/**
	 * @var \WC_Product_Variation
	 */
	public $wc_product;
}