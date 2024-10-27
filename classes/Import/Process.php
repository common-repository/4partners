<?php

namespace ForPartners\Import;

use ForPartners\Utils\Constants;

class Process {
	const STATUS_RUNNING = 'running';
	const STATUS_ERROR = 'error';
	const STATUS_FINISHED = 'finished';

	private $category_import;

	public function __construct( CategoryImport $category_import ) {
		$this->category_import = $category_import;
	}

	public static function get_sync_table_name() {
		global $wpdb;

		return $wpdb->prefix . Constants::DBPREFIX . 'synchronization_version';
	}

	public function run( \WP_REST_Request $request ) {
		ini_set( 'memory_limit', '1024M' );
		set_time_limit( 600 );
		//wp_raise_memory_limit(); //WP_MAX_MEMORY_LIMIT should be defined in config

		$offset      = intval( $request->get_param( 'offset' ) );
		$category_id = intval( $request->get_param( 'category_id' ) );
		$limit       = intval( $request->get_param( 'limit' ) );
		$limit       = empty( $limit ) ? 10 : $limit;

		if ( $offset === 0 ) {
			$data_version_id = $this->initialize_version( $category_id );
			if ( ! $data_version_id ) {
				return $this->create_error_response( 0, 0, "Can't create synchronization version", 1 );
			}
		}

		$last = $this->get_last_version( $category_id );
		if ( empty( $last ) || $last['status'] !== self::STATUS_RUNNING ) {
			return $this->create_error_response(
				0,
				0,
				'Last synchronization version was ended. You should start with offset=0',
				2
			);
		}

		$data_version_id    = $last['id'];
		$total_loaded       = $last['total_loaded'];
		$version_created_at = $last['created_at'];
		$total              = 0;

		try {
			$import_service = $this->category_import;

			$scroll = get_transient( CategoryImport::CATEGORY_SCROLL_PREFIX . $category_id );
			$data   = $import_service->load_data_scroll( $category_id, $scroll, $limit );

			if ( ! empty( $data['result']['scroll']['id'] ) ) {
				set_transient( CategoryImport::CATEGORY_SCROLL_PREFIX . $category_id, $data['result']['scroll']['id'], 0 );
			}

			$loaded       = $import_service->process_data( $data, $data_version_id, $category_id );
			$total_loaded += $loaded;

			$total  = $data['result']['scroll']['total'];
			$status = ( empty( $data['result']['items'] ) ) ? self::STATUS_FINISHED : self::STATUS_RUNNING;

			if ( $status === self::STATUS_FINISHED ) {
				$import_service->cleanup( $category_id, $version_created_at );
			}

			$this->update_version( $data_version_id, $offset, $limit, $total, $total_loaded, $status );

			return array(
				'status'       => $status,
				'items_loaded' => $loaded,
				'items_total'  => $total_loaded,
				'last_offset'  => $offset,
			);

		} catch ( \Exception $e ) {
			$this->update_version( $data_version_id, $offset, $limit, $total, $total_loaded, static::STATUS_ERROR, $e->getMessage() );

			return $this->create_error_response( 0, $total_loaded, $e->getMessage(), 3 );
		}
	}

	private function initialize_version( $category_id ) {
		global $wpdb;

		$result = $wpdb->insert(
			static::get_sync_table_name(),
			array(
				'category_id' => $category_id,
				'status'      => self::STATUS_RUNNING,
				'last_offset' => 0,
				'last_limit'  => 0,
			),
			array( '%d', '%s', '%d', '%d', '%d' )
		);

		if ( $result ) {
			set_transient( CategoryImport::CATEGORY_SCROLL_PREFIX . $category_id, '', 0 );

			return $wpdb->insert_id;
		}

		return null;
	}

	private function create_error_response( $items_loaded = 0, $items_total = 0, $message = '', $code = 1 ) {
		return array(
			'status'       => self::STATUS_ERROR,
			'items_loaded' => $items_loaded,
			'items_total'  => $items_total,
			'message'      => $message,
			'code'         => $code,
		);
	}

	private function update_version( $id, $last_offset, $last_limit, $total, $total_loaded, $status, $message = '' ) {
		global $wpdb;
		$result = $wpdb->update(
			static::get_sync_table_name(),
			array(
				'last_offset'  => $last_offset,
				'last_limit'   => $last_limit,
				'total'        => $total,
				'total_loaded' => $total_loaded,
				'status'       => $status,
				'message'      => $message,
			),
			array( 'id' => $id )
		);

		if ( $result === false ) {
			throw new \Exception( "Can't update synchronization version" );
		}

		return $result;
	}

	private function get_last_version( $category_id ) {
		global $wpdb;

		$table = static::get_sync_table_name();

		return $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM $table WHERE category_id = %s ORDER BY id DESC", $category_id ),
			ARRAY_A
		);
	}
}