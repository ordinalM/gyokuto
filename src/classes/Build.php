<?php

namespace Gyokuto;

use Exception;
use Symfony\Component\Yaml\Yaml;
use Twig\Environment;
use Twig\Extension\StringLoaderExtension;
use Twig\Extra\Markdown\DefaultMarkdown;
use Twig\Extra\Markdown\MarkdownExtension;
use Twig\Extra\Markdown\MarkdownRuntime;
use Twig\Loader\ChainLoader;
use Twig\Loader\FilesystemLoader;
use Twig\RuntimeLoader\RuntimeLoaderInterface;

class Build {
	private const OPTION_CONTENT_DIR = 'content_dir';
	private const OPTION_OUTPUT_DIR = 'output_dir';
	private const OPTION_TEMP_DIR = 'temp_dir';
	private const OPTION_TEMPLATE_DIR = 'template_dir';
	private const TEMPLATE_DIR_USER_DEFAULT = './templates';
	private const TEMPLATE_DIR_BUILTIN = '../templates'; // relative to this file
	private const OPTIONS_FILE_DEFAULT = './gyokuto.yml';
	private $content_dir = './content';
	private $output_dir = './www';
	private $temp_dir = './.gyokuto-tmp';
	private $options;
	/**
	 * @var Environment
	 */
	private $twig;
	/**
	 * @var array
	 */
	private $build_metadata;

	public function __construct(string $options_file = null){
		$options_file = $options_file ?? self::OPTIONS_FILE_DEFAULT;
		if (is_file($options_file)){
			$this->options = Yaml::parse(file_get_contents($options_file));
			Utils::getLogger()
				->debug('Read options from file '.realpath($options_file), $this->options);
		}
		else {
			$this->options = [];
		}
		$this->content_dir = $this->options[self::OPTION_CONTENT_DIR] ?? $this->content_dir;
		$this->output_dir = $this->options[self::OPTION_OUTPUT_DIR] ?? $this->output_dir;
		$this->temp_dir = $this->options[self::OPTION_TEMP_DIR] ?? $this->temp_dir;
		$this->twig = $this->getTwigEnvironment();
	}

	private function getTwigEnvironment(): Environment{
		Utils::getLogger()
			->info('Loading Twig environment');

		// Template loading
		$loaders = [];

		// This is the user template folder.
		// We don't actually need to have one.
		$template_dir_user = $this->options[self::OPTION_TEMPLATE_DIR] ?? self::TEMPLATE_DIR_USER_DEFAULT;
		if (is_dir($template_dir_user)){
			// Put the user loader first
			$loaders[] = new FilesystemLoader($template_dir_user);
			Utils::getLogger()
				->debug('Loading user templates from '.realpath($template_dir_user), Utils::getDirectoryContents($template_dir_user));
		}

		// This is the application template folder.
		$template_dir_app = __DIR__.'/'.self::TEMPLATE_DIR_BUILTIN;
		$loaders[] = new FilesystemLoader($template_dir_app);
		Utils::getLogger()
			->debug('Loading application templates from '.realpath($template_dir_app), Utils::getDirectoryContents($template_dir_app));

		// Create the Twig environment
		$twig = new Environment(new ChainLoader($loaders), [
			'autoescape' => false,
			'strict_variables' => true,
			'auto_reload' => true,
//			'cache' => $this->config['cache_dir'].'/twig',
		]);

		// Add a loader for the markdown runtime
		$twig->addRuntimeLoader(new class implements RuntimeLoaderInterface {
			public function load($class){
				if (MarkdownRuntime::class===$class){
					return new MarkdownRuntime(new DefaultMarkdown());
				}

				return null;
			}
		});

		// We use the string loader extension to parse Twig in markdown body.
		$twig->addExtension(new StringLoaderExtension());
		$twig->addExtension(new MarkdownExtension());

		return $twig;
	}

	/**
	 * Begins a build run
	 */
	public function run(): bool{
		Utils::getLogger()
			->info('Starting build');
		try {
			$content_files = ContentFileList::createFromDirectory($this->content_dir);

			$this->build_metadata = $content_files->compileContentMetadata($this);
			$content_files->process($this);

			$this->moveTempToOutput();

			$status = true;
		}
		catch (Exception $exception){
			Utils::getLogger()
				->error('Error in build', [$exception->getFile(), $exception->getLine(), $exception->getMessage()]);

			$status = false;
		}
		$this->cleanup();
		Utils::getLogger()
			->info('Finished build');

		return $status;
	}

	private function moveTempToOutput(){
		Utils::getLogger()
			->info('Moving temporary build directory to '.realpath($this->output_dir));
		Utils::deleteDir($this->output_dir);

		rename($this->getTempDir(), $this->output_dir);
	}

	/**
	 * @return array|string
	 */
	public function getTempDir(){
		if (!is_dir($this->temp_dir)){
			mkdir($this->temp_dir, 0755, true);
		}

		return realpath($this->temp_dir);
	}

	/**
	 * Cleans up a run, removing all temp files
	 */
	private function cleanup(){
		if (is_dir($this->getTempDir())){
			Utils::deleteDir($this->getTempDir());
			Utils::getLogger()
				->debug('Deleted temp dir');
		}
	}

	/**
	 * @return array|false|string
	 */
	public function getContentDir(){
		return realpath($this->content_dir);
	}

	/**
	 * @return Environment
	 */
	public function getTwig(): Environment{
		return $this->twig;
	}

	/**
	 * @param Environment $twig
	 *
	 * @return Build
	 */
	public function setTwig(Environment $twig): Build{
		$this->twig = $twig;

		return $this;
	}

	/**
	 * @return array
	 */
	public function getBuildMetadata(): array{
		return $this->build_metadata;
	}

	/**
	 * @return mixed
	 */
	public function getOptions(){
		return $this->options;
	}

}
