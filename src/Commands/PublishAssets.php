<?php namespace Baun\Commands;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class PublishAssets extends Command {

	protected function configure()
	{
		$this
			->setName('publish:assets')
			->setDescription('Publish a plugin\'s asset files')
			->addArgument(
				'plugin',
				InputArgument::REQUIRED,
				'What is the plugin name (found in composer.json)?'
			);
	}

	protected function execute(InputInterface $input, OutputInterface $output)
	{
		$plugin = $input->getArgument('plugin');

		if (!is_dir(BASE_PATH . 'vendor/' . $plugin)) {
			return $output->writeln('<error>Error: Plugin ' . $plugin . ' not found</error>');
		}
		if (!is_dir(BASE_PATH . 'vendor/' . $plugin . '/assets')) {
			return $output->writeln('<comment>No assets found</comment>');
		}

		$rdi = new \RecursiveDirectoryIterator(BASE_PATH . 'vendor/' . $plugin . '/assets/');
		$rii = new \RecursiveIteratorIterator($rdi);
		$files = array_keys(iterator_to_array($rii));
		if (!empty($files)) {
			$error = false;
			foreach ($files as $file) {
				$fileName = basename($file);
				if ($fileName == '.' || $fileName == '..') continue;

				$destFolder = str_replace(BASE_PATH . 'vendor/' . $plugin . '/assets', BASE_PATH . 'public/assets/plugins/' . $plugin, dirname($file));
				if (!is_dir($destFolder)) {
					if (is_writable(BASE_PATH . 'public/assets')) {
						mkdir($destFolder, 0777, true);
					} else {
						return $output->writeln('<error>Error: /public/assets folder is not writeable</error>');
					}
				}

				if (!copy($file, $destFolder . '/' . $fileName)) {
					$error = true;
					$output->writeln('<error>Error: Failed to copy ' . $fileName . '</error>');
				}
			}

			if (!$error) {
				$output->writeln('<info>Successfully published ' . count($files) . ' assets</info>');
			}
		} else {
			$output->writeln('<comment>No assets found</comment>');
		}
	}

}