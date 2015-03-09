<?php namespace Baun;

class Plugin {

	protected $config;
	protected $events;
	protected $router;
	protected $theme;
	protected $contentParser;

	public function __construct($config, $eventProvider, $routerProvider, $themeProvider, $contentParser)
	{
		$this->config = $config;
		$this->events = $eventProvider;
		$this->router = $routerProvider;
		$this->theme = $themeProvider;
		$this->contentParser = $contentParser;
		$this->init();
	}

	protected function init()
	{

	}

}