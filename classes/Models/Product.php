<?php

namespace ForPartners\Models;

class Product {
	public $id;
	public $external_id;
	public $name;
	public $brand;
	public $description;
	public $hash;
	/**
	 * @var ProductVariation[]
	 */
	public $variations = [];
	public $images;
	public $images_data;
	/**
	 * @var \WC_Product_Variable
	 */
	public $wc_product;
	public $categories;
	public $attributes;
	public $variation_attributes;
}