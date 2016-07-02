<?php namespace Baun\Providers;

use Baun\Interfaces\Events as EventsInterface;
use League\Event\Emitter;

class Events implements EventsInterface {

	protected $emitter;

	public function __construct()
	{
		$this->emitter = new Emitter();
	}

	public function addListener($name, $listener, $priority = 0)
	{
		$this->emitter->addListener($name, $listener, $priority);
	}

	public function emit($event)
	{
		call_user_func_array(array($this->emitter, 'emit'), func_get_args());
	}

}