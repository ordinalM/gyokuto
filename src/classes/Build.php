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
	private const ENV_CONTENT_DIR = 'GYOKUTO_CONTENT_DIR';
	private const ENV_OUTPUT_DIR = 'GYOKUTO_OUTPUT_DIR';
	private const ENV_TEMP_DIR = 'GYOKUTO_TEMP_DIR';
	private const ENV_TEMPLATE_DIR = 'GYOKUTO_TEMPLATE_DIR';
	private const TEMPLATE_DIR_BUILTIN = '../templates';
	private const TEMPLATE_DIR_USER_DEFAULT = 'templates';
	private $content_dir = 'content';
	private $output_dir = 'www';
	private $temp_dir = '.gyokuto/tmp';
	private $options_file = 'gyokuto.yml';
	private $options;
	/**
	 * @var Environment
	 */
	private $twig;
	/**
	 * @var array
	 */
	private $build_metadata;

	public function __construct(){
		if (getenv(self::ENV_CONTENT_DIR)){
			$this->content_dir = getenv(self::ENV_CONTENT_DIR);
		}
		if (getenv(self::ENV_OUTPUT_DIR)){
			$this->output_dir = getenv(self::ENV_OUTPUT_DIR);
		}
		$temp_dir = getenv(self::ENV_TEMP_DIR);
		if ($temp_dir){
			$this->temp_dir = $temp_dir;
		}
	}

	/**
	 * Begins a build run
	 */
	public function run(): bool{
		Utils::getLogger()
			->info('Build starting');
		try {
			$this->twig = $this->getTwigEnvironment();
			Utils::getLogger()
				->info('Using content dir', [$this->getContentDir()]);
			$content_files = ContentFileList::createFromDirectory($this->content_dir);
			if ($content_files->count()===0){
				Utils::getLogger()
					->warning('No content files found');

				return true;
			}
			Utils::getLogger()
				->info($content_files->count().' content files found');

			$this->build_metadata = $content_files->compileMetadata($this);
			$this->processContentFiles($content_files);

			Utils::deleteDir($this->output_dir);
			rename($this->getTempDir(), $this->output_dir);

			$status = true;
		}
		catch (Exception $exception){
			Utils::getLogger()
				->error('Error in build', [$exception->getFile(), $exception->getLine(), $exception->getMessage()]);

			$status = false;
		}
		$this->cleanup();

		return $status;
	}

	private function getTwigEnvironment(): Environment{
		// This is the application template folder.
		$loaders = [
			new FilesystemLoader(__DIR__.'/'.self::TEMPLATE_DIR_BUILTIN),
		];

		// This is the user template folder.
		// We don't actually need to have one.
		$template_dir_user = getenv(self::ENV_TEMPLATE_DIR);
		if (!$template_dir_user){
			$template_dir_user = self::TEMPLATE_DIR_USER_DEFAULT;
		}
		if (is_dir($template_dir_user)){
			array_unshift($loaders, new FilesystemLoader($template_dir_user));
		}

		Utils::getLogger()
			->debug('Using twig loaders', $loaders);

		// Chain them together
		$loader = new ChainLoader($loaders);

		// Create the Twig environment
		$twig = new Environment($loader, [
			'autoescape' => false,
			'strict_variables' => true,
			'auto_reload' => true,
//			'cache' => $this->config['cache_dir'].'/twig',
		]);

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
//		// Add a function for making galleries.
//		$twig->addFunction(new TwigFunction('gyokuto_gallery', [
//			self::class,
//			'makeGallery',
//		], ['needs_context' => true]));

		return $twig;
	}

	/**
	 * @return array|false|string
	 */
	public function getContentDir(){
		return realpath($this->content_dir);
	}

	private function processContentFiles(ContentFileList $content_files){
		while (false!==($file = $content_files->popType(ContentFile::TYPE_COPY))){
			$file->process($this);
		}
		while (false!==($file = $content_files->popType(ContentFile::TYPE_PARSE))){
			$file->process($this);
		}
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
	 * @return Environment
	 */
	public function getTwig(): Environment{
		return $this->twig;
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
		if (!isset($this->options)){
			if (is_file($this->options_file)){
				$this->options = Yaml::parse(file_get_contents($this->options_file));
				Utils::getLogger()->debug('Read options file', $this->options);
			}
			else {
				$this->options = [];
			}
		}

		return $this->options;
	}

}
