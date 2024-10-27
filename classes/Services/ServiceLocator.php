<?php

namespace ForPartners\Services;

class ServiceLocator {
	/** @var string[][] */
	private $services = [];

	public function __construct() {
	}

	/**
	 * Adds service to locator.
	 *
	 * @param string $code
	 * @param mixed $service
	 */
	public function addInstance( $code, $service ) {
		$this->services[ $code ] = $service;
	}

	/**
	 * Checks whether the service with code exists.
	 *
	 * @param string $code
	 *
	 * @return bool
	 */
	public function has( $code ) {
		return isset( $this->services[ $code ] );
	}

	/**
	 * Returns services by code.
	 *
	 * @param string $code
	 *
	 * @return mixed
	 * @throws \Exception
	 */
	public function get( $code ) {
		if ( ! isset( $this->services[ $code ] ) ) {
			throw new \Exception( 'Service not found ' . $code );
		}

		return $this->services[ $code ];
	}
}