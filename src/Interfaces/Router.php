<?php namespace Baun\Interfaces;

interface Router {

	/**
	 * Add a route to the router
	 *
	 * @param string $method GET/POST etc.
	 * @param string $route the route URI
	 * @param function $function route callback function
	 */
	public function add($method, $route, $function);

	/**
	 * Processes requests and dispatches the router
	 */
	public function dispatch();

	/**
	 * Add a route filter
	 *
	 * @param string $name
	 * @param function $callback
	 */
	public function filter($name, $callback);

	/**
	 * Create a route group
	 *
	 * @param array $filters
	 * @param function $callback
	 */
	public function group($filters, $callback);

	/**
	 * Get the URI of the current request
	 *
	 * @return string
	 */
	public function currentUri();

	/**
	 * Get the base URL of the current request
	 *
	 * @return string
	 */
	public function baseUrl();

}