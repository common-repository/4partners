<?php

namespace ForPartners\Import;

use ForPartners\Services\CategoryService;
use ForPartners\Services\SettingsService;

class Manager {
	const CRON_HOOK_NAME = '4partners_cron_jobs';
	public $setting_service;
	public $category_import;

	public function __construct( SettingsService $setting_service, CategoryImport $category_import ) {
		$this->setting_service = $setting_service;
		$this->category_import = $category_import;
	}

	public function add_cron_job() {
		add_filter( 'cron_schedules', function ( $schedules ) {
			$schedules['4partners_5_min'] = array(
				'interval' => 5 * 60,
				'display'  => __( 'Every 5 minutes' )
			);

			return $schedules;
		} );

		if ( ! wp_next_scheduled( static::CRON_HOOK_NAME ) ) {
			wp_schedule_event( time(), '4partners_5_min', static::CRON_HOOK_NAME );
		}

		add_action( '4partners_cron_jobs', [ $this, 'run_cron_job' ] );
	}

	public function run_cron_job() {
		set_time_limit( 6000 );

		global $wpdb;

		$table                = Process::get_sync_table_name();
		$datetime_10_mins_ago = new \DateTime( '10 mins ago' );

		$current_running_process = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM $table WHERE updated_at > '%s' AND status = 'running' ORDER BY id DESC LIMIT 1", $datetime_10_mins_ago->format( 'Y-m-d H:i:s' ) ),
			ARRAY_A
		);
		if ( ! empty( $current_running_process ) ) {
			return;
		}

		$categories_for_sync = $this->setting_service->get_option_value( 'start_sync_categories' );

		if ( ! empty( $categories_for_sync ) ) {
			$category_id = array_shift( $categories_for_sync );
			$this->parse_category( $category_id );
			$this->setting_service->update_option_value( 'start_sync_categories', $categories_for_sync );
		} else {

			$cron = $this->setting_service->get_option_value( 'cron' );
			if ( $cron == SettingsService::CRON_DAILY ) {
				$datetime = new \DateTime( '1 day ago' );
			} elseif ( $cron == SettingsService::CRON_WEEKLY ) {
				$datetime = new \DateTime( '7 days ago' );
			} else {
				$datetime = new \DateTime( '12 hours ago' );
			}

			$versions = $wpdb->get_results(
				$wpdb->prepare( "SELECT * FROM $table WHERE created_at > '%s' ORDER BY id DESC", $datetime->format( 'Y-m-d H:i:s' ) ),
				ARRAY_A
			);

			$categories_by_version = [];
			foreach ( $versions as $version ) {
				if ( empty( $categories_by_version[ $version['category_id'] ] ) ) {
					$categories_by_version[ $version['category_id'] ] = $version;
				}
			}

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

			$terms = get_terms( $args );

			$categories_for_parsing   = [];
			$categories_with_children = [];
			foreach ( $terms as $term ) {
				$categories_with_children[ $term->parent ] = true;
				// check if already parsed
				if ( isset( $categories_by_version[ $term->term_id ] ) ) {
					continue;
				}
				$categories_for_parsing[ $term->term_id ] = [ $term->name, $term->parent ];
			}

			foreach ( $categories_for_parsing as $category_id => $category_data ) {
				// parse only category without children (last level)
				if ( isset( $categories_with_children[ $category_id ] ) ) {
					continue;
				}

				$this->parse_category( $category_id );

				break; // just one category at time
			}
		}

		as_unschedule_all_actions( 'adjust_download_permissions' );
		as_unschedule_all_actions( 'woocommerce_run_product_attribute_lookup_update_callback' );
	}

	public function parse_category( $category_id ) {
		$token  = $this->category_import->get_import_token();
		$offset = 0;

		$http_args = array(
			'headers' => array( "Accept" => "application/json" ),
			'timeout' => 600
		);

		while ( true ) {
			$http_args['body'] = [
				'category_id' => $category_id,
				'offset'      => $offset,
				'token'       => $token,
				'limit'       => 10,
			];

			$response = wp_remote_get( get_rest_url( null, '/4partners/v1/import' ), $http_args );

			if ( is_wp_error( $response ) ) {
				break;
			}

			$json_data = json_decode( $response['body'], true );
			if ( $json_data === null ) {
				break;
			}

			if ( $json_data['status'] != Process::STATUS_RUNNING ) {
				break;
			}

			$offset += 10;
		}
	}
}