<?php

namespace Gyokuto;

use Exception;
use RuntimeException;
use Symfony\Component\Yaml\Yaml;

class ContentFile {
	public const TYPE_PARSE = 0;
	public const TYPE_COPY = 1;
	public const KEY_META_DRAFT = 'draft';
	public const KEY_META_HIDDEN = 'hidden';
	public const KEY_META = 'meta';
	public const KEY_META_DATE = 'date';
	private const KEY_META_MODIFIED = 'modified';
	private const KEY_META_CREATED = 'created';
	private const KEY_META_TITLE = 'title';
	private const KEY_CONTENT = 'content';
	private const KEY_CURRENT_PAGE = 'current_page';
	private const KEY_CONFIG = 'config';
	private const KEY_PATH = 'path';
	private const REGEX_MARKDOWN_EXTENSION = '/\.(md|markdown)$/';

	private $filename;
	/**
	 * Holds the meta array for this content file, if any.
	 *
	 * @var false|array
	 */
	private $meta = false;
	/**
	 * Holds the raw markdown text for this content file, if any.
	 *
	 * @var string|false
	 */
	private $markdown = false;

	/**
	 * @throws Exception
	 */
	public function __construct(string $filename){
		if (!is_file($filename)){
			throw new RuntimeException('Bad filename '.$filename);
		}
		$this->filename = $filename;
		$this->readAndSplit();
	}

	/**
	 * Processes the content file, pulling metadata and raw markdown.
	 *
	 * @return ContentFile
	 * @throws Exception
	 */
	private function readAndSplit(): ContentFile{
		if (!$this->isParsable()){
			return $this;
		}
		$raw = file_get_contents($this->filename);
		if (preg_match('/^---\n(.+?)\n---\n\s*(.*)\s*$/s', $raw, $matches)){
			$this->meta = Yaml::parse($matches[1]);
			$this->markdown = $matches[2];
		}
		else {
			$this->meta = [];
			$this->markdown = $raw;
		}
		// Try to parse the date if it's left it as a string
		// If it has been turned into an int, we assume Yaml has parsed it already.
		if (isset($this->meta[self::KEY_META_DATE]) && is_string($this->meta[self::KEY_META_DATE])){
			$parsed_date = strtotime($this->meta[self::KEY_META_DATE]);
			if ($parsed_date===false){
				$f = print_r($this->meta, 1);
				throw new Exception("Tried to parse date field as a date but it didn't work - full meta is: $f");
			}
			if ($parsed_date!==$this->meta[self::KEY_META_DATE]){
				$this->meta[self::KEY_META_DATE] = $parsed_date;
			}
		}
		// If the title is missing or empty, generate it. (It may be an empty string, which we can't use.)
		if (empty($this->meta[self::KEY_META_TITLE])){
			$this->meta[self::KEY_META_TITLE] = $this->getTitleFromFilename();
		}
		// Otherwise, set defaults.
		$this->meta += [
			self::KEY_META_DATE => filemtime($this->filename),
			self::KEY_META_CREATED => filectime($this->filename),
			self::KEY_META_MODIFIED => filemtime($this->filename),
		];

		return $this;
	}

	private function isParsable(): bool{
		return self::filenameIsParsable($this->filename);
	}

	public static function filenameIsParsable($filename): bool{
		return preg_match(self::REGEX_MARKDOWN_EXTENSION, $filename);
	}

	/**
	 * Generates a title from the filename, in cases where there is no title metadata
	 *
	 * @return string
	 */
	private function getTitleFromFilename(): string{
		$title = basename($this->filename);
		$title = preg_replace('/\.[^.]+$/', '', $title);
		$title = preg_replace('/[-_]+/', ' ', $title);
		$title = trim($title);

		return $title;
	}

	/**
	 * @param Build $build
	 */
	public function process(Build $build): void{
		$target_filename = $this->getBuildFilename($build);
		if (!is_dir(dirname($target_filename)) && !mkdir($concurrentDirectory = dirname($target_filename), 0755, true) && !is_dir($concurrentDirectory)){
			throw new RuntimeException('Could not create target dir '.dirname($target_filename));
		}
		if (!$this->isParsable()){
			copy($this->filename, $target_filename);
			Utils::getLogger()
				->debug('Copied file', [$this->filename, $target_filename]);

			return;
		}
		if ($this->getMeta()[self::KEY_META_DRAFT]){
			Utils::getLogger()
				->debug('Skipping draft file', $this->getMeta());

			return;
		}
		$html = $this->render($build);
		file_put_contents($target_filename, $html);
		Utils::getLogger()
			->debug('Wrote parsed file', [$this->filename, $target_filename]);
	}

	private function getBuildFilename(Build $build): string{
		return $build->getTempDir().$this->getPath($build, false);
	}

	/**
	 * @param Build $build
	 * @param bool  $strip_index
	 *
	 * @return string
	 */
	public function getPath(Build $build, bool $strip_index = true): string{
		// If path metadata value is set, use that, otherwise calculate output path based on the content filename.
		if (empty($this->meta[self::KEY_PATH])){
			$path = preg_replace(self::REGEX_MARKDOWN_EXTENSION, '.html', $this->filename);
			if ($strip_index){
				$path = preg_replace('|index\.html$|', '', $path);
			}
			$path = str_replace($build->getContentDir(), '', $path);
		}
		else {
			$path = $this->meta[self::KEY_PATH];
		}

		return '/'.ltrim($path, '/');
	}

	/**
	 * @return array
	 */
	public function getMeta(): array{

		return $this->meta;
	}

	/**
	 * @throws \Twig\Error\SyntaxError
	 * @throws \Twig\Error\RuntimeError
	 * @throws \Twig\Error\LoaderError
	 * @throws Exception
	 */
	private function render(Build $build): string{
		$page_params = [
			self::KEY_CURRENT_PAGE => $this->getBasePageData($build),
			self::KEY_CONFIG => $build->getConfig(),
		];
		$page_params[self::KEY_CURRENT_PAGE][self::KEY_CONTENT] = $this->getMarkdown();
		$page_params = array_merge($build->getBuildMetadata(), $page_params);

		// Render markdown content, using Twig content filter first
		Utils::getLogger()
			->debug('Rendering', $this->getMeta());

		$page_params[self::KEY_CURRENT_PAGE][self::KEY_CONTENT] = $build->getTwig()
			->render('_convert_twig_in_content.twig', $page_params);

		// Apply page template
		return $build->getTwig()
			->render($this->getTemplate(), $page_params);
	}

	/**
	 * Basic page data for use in indexes as well as when building the HTML
	 *
	 * @param Build $build
	 *
	 * @return array
	 */
	public function getBasePageData(Build $build): array{
		return [
			self::KEY_META => $this->getMeta(),
			self::KEY_PATH => $this->getPath($build),
		];
	}

	/**
	 * @throws Exception
	 */
	private function getMarkdown(): string{
		if ($this->markdown===false){
			$this->readAndSplit();
		}

		return $this->markdown;
	}

	/**
	 * @return string
	 */
	private function getTemplate(): string{
		return $this->getMeta()['template'] ?? 'default.twig';
	}

}