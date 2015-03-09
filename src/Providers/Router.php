<?php namespace Baun\Providers;

use Baun\Interfaces\Router as RouterInterface;
use Symfony\Component\HttpFoundation\Request;
use Phroute\Phroute\RouteCollector;
use Phroute\Phroute\Dispatcher;

class Router implements RouterInterface {

	protected $router;
	protected $request;

	public function __construct()
	{
		$this->router = new RouteCollector();
		$this->request = Request::createFromGlobals();
	}

	public function add($method, $route, $function)
	{
		$this->router->addRoute($method, $route, $function);
	}

	public function dispatch()
	{
		$dispatcher = new Dispatcher($this->router->getData());
		echo $dispatcher->dispatch($this->request->getMethod(), $this->request->getPathInfo());
	}

	public function currentUri()
	{
		return ltrim($this->request->getPathInfo(), '/');
	}

	public function baseUrl()
	{
		return $this->request->getSchemeAndHttpHost();
	}

}