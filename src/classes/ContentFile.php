<?php

namespace Gyokuto;

use RuntimeException;
use Symfony\Component\Yaml\Yaml;

class ContentFile {
	public const TYPE_PARSE = 0;
	public const TYPE_COPY = 1;

	private $filename;
	/**
	 * @var false|array
	 */
	private $meta = false;
	/**
	 * @var false|string
	 */
	private $markdown = false;

	public function __construct(string $filename){
		if (!is_file($filename)){
			throw new RuntimeException('Bad filename '.$filename);
		}
		$this->filename = $filename;
	}

	public function process(Build $build): void{
		$target_filename = $this->getBuildFilename($build);
		if (!is_dir(dirname($target_filename))){
			if (false===mkdir(dirname($target_filename), 0755, true)){
				throw new RuntimeException('Could not create target dir '.dirname($target_filename));
			}
		}
		if (!$this->isParsable()){
			copy($this->filename, $target_filename);
			Utils::getLogger()
				->debug('Copied file', [$this->filename, $target_filename]);

			return;
		}
		$html = $this->render($build);
		file_put_contents($target_filename, $html);
		Utils::getLogger()
			->debug('Wrote parsed file', [$this->filename, $target_filename]);
	}

	private function getBuildFilename(Build $build){
		return $build->getTempDir().$this->getPath($build);
	}

	public function getPath(Build $build){
		return '/'.ltrim(str_replace($build->getContentDir(), '', preg_replace('/\.(md|markdown)$/', '.html', $this->filename)), '/');
	}

	private function isParsable(){
		return self::filenameIsParsable($this->filename);
	}

	public static function filenameIsParsable($filename){
		return preg_match('/\.(md|markdown)$/', $filename);
	}

	private function render(Build $build): string{
		$page_params = [
			'current_page' => [
				'content' => $this->getMarkdown(),
				'meta' => $this->getMeta(),
				'path' => $this->getPath($build),
			],
		];
		$page_params += $build->getBuildMetadata();

		// Render markdown content, using Twig content filter first
		Utils::getLogger()
			->debug('Rendering markdown');
		$page_params['current_page']['content'] = $build->getTwig()
			->render('_convert_twig_in_content.twig', $page_params);

		// Apply page template
		return $build->getTwig()
			->render($this->getTemplate(), $page_params);
	}

	/**
	 * @return string
	 */
	private function getMarkdown(): string{
		if ($this->markdown===false){
			$this->readAndSplit();
		}

		return $this->markdown;
	}

	private function readAndSplit(): ContentFile{
		if (!$this->isParsable()){
			throw new RuntimeException('Tried to parse a content file that is not parsable '.$this->filename);
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
		$this->meta += [
			'date' => filemtime($this->filename),
			'title' => $this->createTitle(),
		];

		return $this;
	}

	private function createTitle(){
		$title = basename($this->filename);
		$title = preg_replace('/\.[^\.]+$/', '', $title);
		$title = preg_replace('/[-_]+/', ' ', $title);
		$title = trim($title);

		return $title;
	}

	/**
	 * @return array
	 */
	public function getMeta(): array{
		if ($this->meta===false){
			$this->readAndSplit();
		}

		return $this->meta;
	}

	private function getTemplate(): string{
		return $this->getMeta()['template'] ?? 'default.twig';
	}

}