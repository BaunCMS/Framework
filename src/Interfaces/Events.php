<?php namespace Baun\Interfaces;

interface Events {

	/**
	 * Add an event listener
	 *
	 * @param string $name
	 * @param callable $listener
	 * @param integer $priority 100 to -100
	 */
	public function addListener($name, $listener, $priority = 0);

	/**
	 * Emit the given event with optional additional parameters
	 *
	 * @param string $event event name
	 */
	public function emit($event);

}