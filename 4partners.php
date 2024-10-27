<?php

/**
 * Plugin Name: 4partners
 * Description: -
 * Plugin URI:  -
 * Author URI:  4partners.io
 * Author:      4partners
 * Version:     1.0.0
 * Text Domain: 4partners
 * Domain Path: /languages
 *
 * License:     GPL2
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 */

use ForPartners\Plugin;

require_once __DIR__ . '/vendor/autoload.php';

$plugin = new Plugin();

register_activation_hook(__FILE__, array($plugin, 'install'));
register_deactivation_hook(__FILE__, array($plugin, 'deactivate'));

$run_plugin = function () use ($plugin) {
	$is_loaded = $plugin->make_services();
	if ($is_loaded) {
		add_action('init', function() use($plugin){
			$plugin->run();
		}, 20);
	}

	do_action('4partners_crm_init', $plugin);
};

add_action('plugins_loaded', $run_plugin, 20);
