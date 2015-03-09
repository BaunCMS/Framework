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
	 * Emit the given event
	 *
	 * @param string $event event name
	 * @param array $args optional args to pass to event listener
	 */
	public function emit($event, $args = []);

}