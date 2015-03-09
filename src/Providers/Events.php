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

	public function emit($event, $args = [])
	{
		$this->emitter->emit($event, $args);
	}

}