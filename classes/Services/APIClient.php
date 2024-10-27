<?php

namespace ForPartners\Services;

class APIClient {
	private $base_url = 'https://api.shopotam.com/partner/v1';
	private $api_key;

	public function __construct( $api_key ) {
		$this->api_key = $api_key;
	}

	public function send_get( $path, $url_params = [], $decode = true ) {
		$response = $this->send_request( $this->base_url . $path . '?' . http_build_query( $url_params ) );

		if ( $decode ) {
			return $this->decode_data( $response['body'] );
		}

		return $response['body'];
	}

	public function send_post( $path, $post_data = [], $url_params = [], $decode = true ) {
		$response = $this->send_request( $this->base_url . $path . '?' . http_build_query( $url_params ), true, $post_data );

		if ( $decode ) {
			return $this->decode_data( $response['body'] );
		}

		return $response['body'];
	}

	private function send_request( $url, $is_post_request = false, $post_data = [] ) {
		$request_arguments = [
			'timeout'    => 60,
			'user-agent' => 'wp-shopotam (' . get_bloginfo( 'url' ) . ')',
			'headers'    => array(
				'X-Auth-Token' => $this->api_key,
				'Accept'       => 'application/json'
			),
		];

		if ( $is_post_request ) {
			$request_arguments['body'] = $post_data;
			$response                  = wp_remote_post( $url, $request_arguments );
		} else {
			$response = wp_remote_get( $url, $request_arguments );
		}

		if ( is_wp_error( $response ) ) {
			throw new \Exception( $response->get_error_message() );
		}

		return $response;
	}

	private function decode_data( $response ) {
		$json_data = json_decode( $response, true );
		if ( $json_data === null ) {
			throw new \Exception( 'Bad json data received' );
		}

		return $json_data;
	}
}