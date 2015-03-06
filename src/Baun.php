<?php namespace Baun;

class Baun {

	protected $config;
	protected $events;
	protected $router;
	protected $theme;
	protected $contentParser;
	protected $blogPath;

	public function __construct()
	{
		if (!file_exists(BASE_PATH . 'config/app.php')) {
			die('Missing config/app.php');
		}
		$this->config = require BASE_PATH . 'config/app.php';

		// Events
		if (!isset($this->config['providers']['events']) || !class_exists($this->config['providers']['events'])) {
			die('Missing events provider');
		}
		$this->events = new $this->config['providers']['events'];

		// Router
		if (!isset($this->config['providers']['router']) || !class_exists($this->config['providers']['router'])) {
			die('Missing router provider');
		}
		$this->router = new $this->config['providers']['router'];

		// Theme
		if (!isset($this->config['providers']['theme'])){
			die('Missing theme provider');
		}
		if (!isset($this->config['themes_path'])) {
			die('Missing themes path');
		}
		if (!isset($this->config['theme']) || !is_dir($this->config['themes_path'] . $this->config['theme'])) {
			die('Missing theme');
		}
		$this->theme = new $this->config['providers']['theme']($this->config['themes_path'] . $this->config['theme']);

		// Content Parser
		if (!isset($this->config['providers']['contentParser'])){
			die('Missing content parser');
		}
		$this->contentParser = new $this->config['providers']['contentParser'];

		// Debug
		if (!isset($this->config['debug'])) {
			$this->config['debug'] = false;
		}

		$this->blogPath = null;

		$this->events->emit('baun.loaded', $this->config, $this->blogPath);
	}

	public function run()
	{
		$this->events->emit('baun.beforeSetupRoutes');
		$this->setupRoutes();
		$this->events->emit('baun.afterSetupRoutes');

		try {
			$this->events->emit('baun.beforeDispatch', $this->router);
			$this->router->dispatch();
			$this->events->emit('baun.afterDispatch');
		} catch(\Exception $e) {
			if ($this->config['debug']) {
				echo $e->getMessage();
			}

			$this->events->emit('baun.before404');
			$this->theme->render('404');
			$this->events->emit('baun.after404');
		}
	}

	protected function setupRoutes()
	{
		if (!isset($this->config['content_path']) || !is_dir($this->config['content_path'])) {
			die('Missing content path');
		}
		if (!isset($this->config['content_extension'])) {
			die('Missing content extension');
		}
		if (!isset($this->config['blog_folder']) || !$this->config['blog_folder']) {
			$this->config['blog_folder'] = 'blog';
		}
		if (!isset($this->config['posts_per_page']) || !is_int($this->config['posts_per_page'])) {
			$this->config['posts_per_page'] = 10;
		}
		if (!isset($this->config['excerpt_words']) || !is_int($this->config['excerpt_words'])) {
			$this->config['excerpt_words'] = 30;
		}
		if (!isset($this->config['date_format']) || !$this->config['date_format']) {
			$this->config['date_format'] = 'jS F Y';
		}

		$files = $this->getFiles($this->config['content_path'], $this->config['content_extension']);
		$this->events->emit('baun.getFiles', $files);

		$navigation = $this->filesToNav($files, $this->router->currentUri());
		$this->events->emit('baun.filesToNav', $navigation);
		$this->theme->custom('baun_nav', $navigation);

		$routes = $this->filesToRoutes($files);
		$this->events->emit('baun.filesToRoutes', $routes);
		foreach ($routes as $route) {
			$this->router->add('GET', $route['route'], function() use ($route) {
				$data = $this->getFileData($route['path']);
				$template = 'page';
				if (isset($data['info']['template']) && $data['info']['template']) {
					$template = $data['info']['template'];
				}

				$this->events->emit('baun.beforePageRender', $template, $data);
				return $this->theme->render($template, $data);
			});
		}

		if ($this->blogPath) {
			$posts = $this->filesToPosts($files);
			$this->events->emit('baun.filesToPosts', $posts);
			if (!empty($posts)) {
				foreach ($posts as $post) {
					$this->router->add('GET', $post['route'], function() use ($post, $posts) {
						$data = $this->getFileData($post['path']);
						$template = 'post';
						if (isset($data['info']['template']) && $data['info']['template']) {
							$template = $data['info']['template'];
						}
						$published = date($this->config['date_format']);
						if (preg_match('/^\d+\-/', basename($post['path']))) {
							list($time, $path) = explode('-', basename($post['path']), 2);
							$published = date($this->config['date_format'], strtotime($time));
						}
						if (isset($data['info']['published'])) {
							$published = date($this->config['date_format'], strtotime($data['info']['published']));
						}
						$data['published'] = $published;
						$data['posts'] = $posts;

						$this->events->emit('baun.beforePostRender', $template, $data);
						return $this->theme->render($template, $data);
					});
				}
			}

			$page = isset($_GET['page']) && $_GET['page'] ? abs(intval($_GET['page'])) : 1;
			$offset = 0;
			if ($page > 1) {
				$offset = $page - 1;
			}

			$paginatedPosts = array_chunk($posts, $this->config['posts_per_page']);
			$total_pages = count($paginatedPosts);
			if (isset($paginatedPosts[$offset])) {
				$paginatedPosts = $paginatedPosts[$offset];
			} else {
				$paginatedPosts = [];
			}
			$pagination = [
				'total_pages' => $total_pages,
				'current_page' => $page,
				'base_url' => '/' . $this->config['blog_folder']
			];

			$this->router->add('GET', $this->config['blog_folder'], function() use ($paginatedPosts, $pagination) {
				$this->events->emit('baun.beforeBlogRender', $paginatedPosts, $pagination);
				return $this->theme->render('blog', [
					'posts' => $paginatedPosts,
					'pagination' => $pagination
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
					if (!$this->blogPath && ($value == $this->config['blog_folder'] || preg_match('/^(\d+-)' . $this->config['blog_folder'] . '/', $value))) {
						$this->blogPath = $dir . DIRECTORY_SEPARATOR . $value;
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
		$blogBase = str_replace($this->config['content_path'], '', $this->blogPath);

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
				$route = str_replace($this->config['content_extension'], '', $value['nice']);
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
		$blogBase = str_replace($this->config['content_path'], '', $this->blogPath);

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
				$route = str_replace($this->config['content_extension'], '', $value['nice']);
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
		$blogBase = str_replace($this->config['content_path'], '', $this->blogPath);

		foreach ($files as $key => $value) {
			if ($key === $blogBase) {
				$posts = $value;
				break;
			}
		}

		foreach ($posts as $post) {
			$route = str_replace($this->config['content_extension'], '', $post['nice']);
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
				if (count($words) > $this->config['excerpt_words'] && $this->config['excerpt_words'] > 0) {
					$excerpt = implode(' ', array_slice($words, 0, $this->config['excerpt_words'])) . '...';
				}
			}
			$published = date($this->config['date_format']);
			if (preg_match('/^\d+\-/', $post['raw'])) {
				list($time, $path) = explode('-', $post['raw'], 2);
				$published = date($this->config['date_format'], strtotime($time));
			}
			if (isset($data['info']['published'])) {
				$published = date($this->config['date_format'], strtotime($data['info']['published']));
			}

			$result[] = [
				'route'     => $routeBase . '/' . $route,
				'path' 	    => $blogBase . '/' . $post['raw'],
				'title'     => $title,
				'info'      => isset($data['info']) ? $data['info'] : '',
				'excerpt'   => $excerpt,
				'published' => $published
			];
		}

		return $result;
	}

	protected function getFileData($route_path)
	{
		$file_path = $this->config['content_path'] . ltrim($route_path, '/');

		if (file_exists($file_path)) {
			$file_contents = file_get_contents($file_path);
			return $this->contentParser->parse($file_contents);
		}

		return null;
	}

}