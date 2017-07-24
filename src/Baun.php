<?php namespace Baun;

use Dflydev\DotAccessData\Data;

class Baun {

	protected $config;
	protected $events;
	protected $router;
	protected $theme;
	protected $contentParser;
	protected $blogPath;

	public function __construct()
	{
		// Config
		$this->config = $this->loadConfigs();

		// Events
		if (!$this->config->get('providers.events') || !class_exists($this->config->get('providers.events'))) {
			die('Missing events provider');
		}
		$eventsProvider = $this->config->get('providers.events');
		$this->events = new $eventsProvider;

		// Router
		if (!$this->config->get('providers.router') || !class_exists($this->config->get('providers.router'))) {
			die('Missing router provider');
		}
		$routerProvider = $this->config->get('providers.router');
		$this->router = new $routerProvider;

		// Theme
		if (!$this->config->get('providers.theme')) {
			die('Missing theme provider');
		}
		if (!$this->config->get('app.themes_path')) {
			die('Missing themes path');
		}
		if (!$this->config->get('app.theme') || !is_dir($this->config->get('app.themes_path') . $this->config->get('app.theme'))) {
			die('Missing theme');
		}
		$themeProvider = $this->config->get('providers.theme');
		$this->theme = new $themeProvider($this->config->get('app.themes_path') . $this->config->get('app.theme'));

		// Content Parser
		if (!$this->config->get('providers.contentParser')) {
			die('Missing content parser');
		}
		$contentParserProvider = $this->config->get('providers.contentParser');
		$this->contentParser = new $contentParserProvider;

		// Plugins
		$plugins = $this->config->get('plugins');
		if (!empty($plugins)) {
			foreach ($plugins as $pluginClass) {
				if (class_exists($pluginClass)) {
					new $pluginClass($this->config, $this->events, $this->router, $this->theme, $this->contentParser);
				}
			}
		}

		// Debug
		if (!$this->config->get('app.debug')) {
			$this->config->set('app.debug', false);
		}

		// Date
		$this->config->set('app.date', new \DateTime());

		// Base URL
		if (!$this->config->get('app.base_url')) {
			$this->config->set('app.base_url', $this->router->baseUrl());
		}

		$this->blogPath = null;
		$this->config->set('baun.blog_path', null);
		$this->config->set('baun.theme_url', $this->config->get('app.base_url') . str_replace(BASE_PATH . 'public/', '/', $this->config->get('app.themes_path')) . $this->config->get('app.theme'));

		$this->events->emit('baun.loaded', $this->config);
	}

	public function run()
	{
		$this->events->emit('baun.beforeGlobals', $this->theme);
		$this->theme->addGlobal('base_url', $this->config->get('app.base_url'));
		$this->theme->addGlobal('theme_url', $this->config->get('baun.theme_url'));
		$this->events->emit('baun.afterGlobals', $this->theme);

		$this->events->emit('baun.beforeSetupRoutes');
		$this->setupRoutes();
		$this->events->emit('baun.afterSetupRoutes');

		try {
			$this->events->emit('baun.beforeDispatch', $this->router);
			$this->router->dispatch();
			$this->events->emit('baun.afterDispatch');
		} catch(\Exception $e) {
			header('HTTP/1.0 404 Not Found');

			if ($this->config->get('app.debug')) {
				echo $e->getMessage();
			}

			$this->events->emit('baun.before404');
			$this->theme->render('404');
			$this->events->emit('baun.after404');
		}
	}

	protected function loadConfigs()
	{
		$configData = [];

		$rdi = new \RecursiveDirectoryIterator(BASE_PATH . 'config/');
		$rii = new \RecursiveIteratorIterator($rdi);
		$ri = new \RegexIterator($rii, '/(.*)\.php$/', \RegexIterator::GET_MATCH);
		$configFiles = array_keys(iterator_to_array($ri));

		foreach ($configFiles as $configFile) {
			$configKey = str_replace(BASE_PATH . 'config' . DIRECTORY_SEPARATOR, '', $configFile);
			$configKey = str_replace(DIRECTORY_SEPARATOR, '-', strtolower($configKey));
			$configKey = substr($configKey, 0, -4);
			$configData[$configKey] = require $configFile;
		}

		return new Data($configData);
	}

	protected function setupRoutes()
	{
		if (!$this->config->get('app.content_path') || !is_dir($this->config->get('app.content_path'))) {
			die('Missing content path');
		}
		if (!$this->config->get('app.content_extension')) {
			die('Missing content extension');
		}
		if (!$this->config->get('blog.blog_folder') || !$this->config->get('blog.blog_folder')) {
			$this->config->set('blog.blog_folder', 'blog');
		}
		if (!$this->config->get('blog.posts_per_page') || !is_int($this->config->get('blog.posts_per_page'))) {
			$this->config->set('blog.posts_per_page', 10);
		}
		if (!$this->config->get('blog.excerpt_words') || !is_int($this->config->get('blog.excerpt_words'))) {
			$this->config->set('blog.excerpt_words', 30);
		}
		if (!$this->config->get('blog.date_format') || !$this->config->get('blog.date_format')) {
			$this->config->set('blog.date_format', 'jS F Y');
		}

		$files = $this->getFiles($this->config->get('app.content_path'), $this->config->get('app.content_extension'));
		$this->events->emit('baun.getFiles', $files);

		$posts = null;
		$allPosts = null;
		$postsPagination = [];
		if ($this->blogPath) {
			$allPosts = $this->filesToPosts($files);
			$this->events->emit('baun.filesToPosts', $allPosts);

			$page = isset($_GET['page']) && $_GET['page'] ? abs(intval($_GET['page'])) : 1;
			$offset = 0;
			if ($page > 1) {
				$offset = $page - 1;
			}

			$posts = array_chunk($allPosts, $this->config->get('blog.posts_per_page'));
			$total_pages = count($posts);
			if (isset($posts[$offset])) {
				$posts = $posts[$offset];
			} else {
				$posts = [];
			}
			$postsPagination = [
				'total_pages' => $total_pages,
				'current_page' => $page,
				'base_url' => $this->config->get('app.base_url') . '/' . $this->config->get('blog.blog_folder')
			];
		}

		$navigation = $this->filesToNav($files, $this->router->currentUri());

		$navigationObject = new \stdClass();
		$navigationObject->nodes = $navigation;

		// $navigation is passed by value, $navigationObject is passed by reference
		$this->events->emit('baun.filesToNav', $navigation, $navigationObject);

		// If navigation nodes have been updated by the listener
		if ($navigation !== $navigationObject->nodes) {
			$navigation = $navigationObject->nodes;
		}

		$this->theme->custom('baun_nav', $navigation);

		$routes = $this->filesToRoutes($files);
		$this->events->emit('baun.filesToRoutes', $routes);
		foreach ($routes as $route) {
			$this->router->add('GET', $route['route'], function() use ($route, $posts, $allPosts, $postsPagination) {
				$data = $this->getFileData($route['path']);
				$data['all_posts'] = $allPosts;
				$data['posts'] = $posts;
				$data['pagination'] = $postsPagination;
				$template = 'page';
				if (isset($data['info']['template']) && $data['info']['template']) {
					$template = $data['info']['template'];
				}

				$this->events->emit('baun.beforePageRender', $template, $data);
				return $this->theme->render($template, $data);
			});
		}

		if ($allPosts) {
			if (!empty($allPosts)) {
				foreach ($allPosts as $post) {
					$this->router->add('GET', $post['route'], function() use ($post, $posts, $allPosts, $postsPagination) {
						$data = $this->getFileData($post['path']);
						$data['all_posts'] = $allPosts;
						$data['posts'] = $posts;
						$data['pagination'] = $postsPagination;
						$template = 'post';
						if (isset($data['info']['template']) && $data['info']['template']) {
							$template = $data['info']['template'];
						}
						$published = date($this->config->get('blog.date_format'));
						if (preg_match('/^\d+\-/', basename($post['path']))) {
							list($time, $path) = explode('-', basename($post['path']), 2);
							$published = date($this->config->get('blog.date_format'), strtotime($time));
						}
						if (isset($data['info']['published'])) {
							$published = date($this->config->get('blog.date_format'), strtotime($data['info']['published']));
						}
						$data['published'] = $published;

						$dataObject = json_decode(json_encode($data));
						$this->events->emit('baun.beforePostRender', $template, $dataObject);
						return $this->theme->render($template, (array)$dataObject);
					});
				}
			}

			$this->router->add('GET', $this->config->get('blog.blog_folder'), function() use ($posts, $allPosts, $postsPagination) {
				$this->events->emit('baun.beforeBlogRender', $posts, $postsPagination);
				return $this->theme->render('blog', [
					'all_posts' => $allPosts,
					'posts' => $posts,
					'pagination' => $postsPagination,
				]);
			});
		}
	}

	protected function getFiles($dir, $extension, $top = true)
	{
		$dir = rtrim($dir, '/');
		$result = [];
		$dirs = [];
		$files = [];
		$sdir = scandir($dir);

		foreach ($sdir as $key => $value) {
			if (!in_array($value,array('.','..'))) {
				$ext = pathinfo($value, PATHINFO_EXTENSION);
				if (is_dir($dir . DIRECTORY_SEPARATOR . $value)) {
					if (!$this->blogPath && ($value == $this->config->get('blog.blog_folder') || preg_match('/^(\d+-)' . $this->config->get('blog.blog_folder') . '/', $value))) {
						$this->blogPath = $dir . DIRECTORY_SEPARATOR . $value;
						$this->config->set('baun.blog_path', $this->blogPath);
					}
					$dirs[$value] = $this->getFiles($dir . DIRECTORY_SEPARATOR . $value, $extension, false);
				} elseif('.' . $ext == $extension) {
					if (preg_match('/^\d+\-/', $value)) {
						list($index, $path) = explode('-', $value, 2);
						$files[$index] = [
							'nice' => $path,
							'raw' => $value
						];
					} else {
						$files[] = [
							'nice' => $value,
							'raw' => $value
						];
					}
				}
			}
		}

		ksort($dirs);
		if ($dir == $this->blogPath) {
			krsort($files);
		} else {
			ksort($files);
		}

		if ($top) {
			$result = array_merge($files, $dirs);
		} else {
			$result = array_merge($dirs, $files);
		}

		return $result;
	}

	protected function filesToRoutes($files, $route_prefix = '', $path_prefix = '')
	{
		$result = [];
		$blogBase = str_replace($this->config->get('app.content_path'), '', $this->blogPath);

		foreach ($files as $key => $value) {
			if (!is_int($key)) {
				if ($key != $blogBase) {
					if (preg_match('/^\d+\-/', $key)) {
						list($index, $path) = explode('-', $key, 2);
						$result = array_merge($result, $this->filesToRoutes($value, $route_prefix . $path . '/', $path_prefix . $key . '/'));
					} else {
						$result = array_merge($result, $this->filesToRoutes($value, $route_prefix . $key . '/', $path_prefix . $key . '/'));
					}
				}
			} else {
				$route = str_replace($this->config->get('app.content_extension'), '', $value['nice']);
				if ($route == 'index') {
					$route = '/';
				}

				$result[] = [
					'route' => $route_prefix . $route,
					'path' => $path_prefix . $value['raw']
				];
			}
		}

		return $result;
	}

	protected function filesToNav($files, $currentUri, $route_prefix = '', $path_prefix = '')
	{
		$result = [];
		$blogBase = str_replace($this->config->get('app.content_path'), '', $this->blogPath);

		foreach ($files as $key => $value) {
			if (!is_int($key)) {
				if ($key == $blogBase) {
					$url = basename($blogBase);
					if (preg_match('/^\d+\-/', $url)) {
						list($index, $path) = explode('-', $url, 2);
						$url = $path;
					}

					$result[] = [
						'title'  => ucwords(str_replace(['-', '_'], ' ', $url)),
						'url'    => $url,
						'active' => $url == $currentUri ? true : false
					];
				} else {
					if (preg_match('/^\d+\-/', $key)) {
						list($index, $path) = explode('-', $key, 2);
						$result[$key] = $this->filesToNav($value, $currentUri, $route_prefix . $path . '/', $path_prefix . $key . '/');
					} else {
						$result[$key] = $this->filesToNav($value, $currentUri, $route_prefix . $key . '/', $path_prefix . $key . '/');
					}
				}
			} elseif ($path_prefix != $blogBase . '/') {
				$route = str_replace($this->config->get('app.content_extension'), '', $value['nice']);
				if ($route == 'index') {
					$route = '/';
				}
				if (!$currentUri) {
					$currentUri = '/';
				}

				$data = $this->getFileData($path_prefix . $value['raw']);
				$title = isset($data['info']['title']) ? $data['info']['title'] : '';
				if (!$title) {
					$title = ucwords(str_replace(['-', '_'], ' ', basename($route)));
				}
				$active = false;
				if ($route_prefix . $route == $currentUri) {
					$active = true;
				}

				if (!isset($data['info']['exclude_from_nav']) || !$data['info']['exclude_from_nav']) {
					$result[] = [
						'title'  => $title,
						'url'    => $route_prefix . $route,
						'active' => $active
					];
				}
			}
		}

		return $result;
	}

	protected function filesToPosts($files)
	{
		$result = [];
		$posts = [];
		$blogBase = str_replace($this->config->get('app.content_path'), '', $this->blogPath);

		foreach ($files as $key => $value) {
			if ($key === $blogBase) {
				$posts = $value;
				break;
			}
		}

		foreach ($posts as $post) {
			$route = str_replace($this->config->get('app.content_extension'), '', $post['nice']);
			$routeBase = basename($blogBase);
			if (preg_match('/^\d+\-/', $blogBase)) {
				list($index, $path) = explode('-', $blogBase, 2);
				$routeBase = $path;
			}

			$data = $this->getFileData($blogBase . '/' . $post['raw']);
			$title = isset($data['info']['title']) ? $data['info']['title'] : '';
			if (!$title) {
				$title = ucwords(str_replace(['-', '_'], ' ', basename($route)));
			}
			$excerpt = '';
			if (isset($data['content'])) {
				$excerpt = strip_tags($data['content']);
				$words = explode(' ', $excerpt);
				if (count($words) > $this->config->get('blog.excerpt_words') && $this->config->get('blog.excerpt_words') > 0) {
					$excerpt = implode(' ', array_slice($words, 0, $this->config->get('blog.excerpt_words'))) . '...';
				}
			}
                        $content = '';
                        if (isset($data['content'])) {
                                $content = $data['content'];
                        }
			$published = date($this->config->get('blog.date_format'));
			if (preg_match('/^\d+\-/', $post['raw'])) {
				list($time, $path) = explode('-', $post['raw'], 2);
				$published = date($this->config->get('blog.date_format'), strtotime($time));
				if ($this->config->get('blog.date_in_url')) {
					$route = $time . '/' . $route;
				}
			}
			if (isset($data['info']['published'])) {
				$published = date($this->config->get('blog.date_format'), strtotime($data['info']['published']));
			}

			$result[] = [
				'route'     => $routeBase . '/' . $route,
				'path' 	    => $blogBase . '/' . $post['raw'],
				'title'     => $title,
				'info'      => isset($data['info']) ? $data['info'] : '',
				'excerpt'   => $excerpt,
				'content'   => $content,
				'published' => $published
			];
		}

		return $result;
	}

	protected function getFileData($route_path)
	{
		$data = null;
		$file_path = $this->config->get('app.content_path') . ltrim($route_path, '/');

		if (file_exists($file_path)) {
			$file_contents = file_get_contents($file_path);
			$data = $this->contentParser->parse($file_contents);
		}

		return $data;
	}

}
