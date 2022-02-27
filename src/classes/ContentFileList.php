<?php

namespace Gyokuto;

use Exception;
use RuntimeException;

class ContentFileList {
	private const KEY_PAGES_BY_META = 'index';
	public const KEY_PAGE_INDEX = 'pages';
	private $filenames = [ContentFile::TYPE_PARSE => [], ContentFile::TYPE_COPY => []];

	public static function createFromDirectory($content_dir): ContentFileList{
		Utils::getLogger()
			->info('Indexing content files in '.realpath($content_dir));
		$all_files = Utils::findFilesRecursive($content_dir);
		$list = new self;
		$file_count = 0;
		foreach ($all_files as $filename){
			$list->push($filename);
			$file_count++;
		}
		if ($file_count===0){
			throw new RuntimeException('No files found - is the content directory correct?');
		}
		Utils::getLogger()
			->info('Files found: '.$file_count);

		return $list;
	}

	/**
	 * Pushes a file onto the appropriate file type list
	 *
	 * @param $filename
	 *
	 * @return $this
	 */
	public function push($filename): ContentFileList{
		$this->filenames[ContentFile::filenameIsParsable($filename) ? ContentFile::TYPE_PARSE : ContentFile::TYPE_COPY][] = $filename;

		return $this;
	}

	/**
	 * Looks through the content files that exist in this list and compiles their metadata for use by templates.
	 * Also provides a master list of pages.
	 *
	 * @param Build $build
	 *
	 * @return array[]
	 * @throws Exception
	 */
	public function compileContentMetadata(Build $build): array{
		Utils::getLogger()
			->info('Indexing page metadata');

		$pages_by_meta = [];
		$page_index = [];
		$keys_to_index = $build->getConfig()['index'] ?? [];
		if (count($keys_to_index)>0){
			Utils::getLogger()
				->debug('Indexing metadata keys:', $keys_to_index);
		}
		foreach ($this->filenames[ContentFile::TYPE_PARSE] as $filename){
			$content_file = new ContentFile($filename);
			$page_meta = $content_file->getMeta();
			// Don't index anything in draft pages
			if (($page_meta[ContentFile::KEY_META_DRAFT] ?? false) || ($page_meta[ContentFile::KEY_META_HIDDEN] ?? false)){
				continue;
			}
			$page_path = $content_file->getPath($build);
			$page_index[$page_path] = $content_file->getBasePageData($build);
			if (count($keys_to_index)>0){
				foreach ($keys_to_index as $k){
					if (isset($page_meta[$k])){
						if (!isset($pages_by_meta[$k])){
							$pages_by_meta[$k] = [];
						}
						$v = $page_meta[$k];
						if (!is_array($v)){
							$v = [$v];
						}
						foreach ($v as $v_sub){
							if (!isset($pages_by_meta[$k][$v_sub])){
								$pages_by_meta[$k][$v_sub] = [];
							}
							$pages_by_meta[$k][$v_sub][] = $page_path;
						}
					}
				}
			}
		}

		// Sort each index by value of indexed key
		foreach ($pages_by_meta as &$v){
			ksort($v);
		}
		unset($v);

		// Sort page index by descending date
		uasort($page_index, static function ($a, $b){
			return $b[ContentFile::KEY_META][ContentFile::KEY_META_DATE]<=>$a[ContentFile::KEY_META][ContentFile::KEY_META_DATE];
		});
		Utils::getLogger()
			->debug('Page list sorted', $page_index);

		return [self::KEY_PAGES_BY_META => $pages_by_meta, self::KEY_PAGE_INDEX => $page_index];
	}

	/**
	 * Processes all files in this content list
	 *
	 * @throws Exception
	 */
	public function process(Build $build): void{
		Utils::getLogger()
			->info('Building content');
		while (false!==($file = $this->popType(ContentFile::TYPE_COPY))){
			$file->process($build);
		}
		while (false!==($file = $this->popType(ContentFile::TYPE_PARSE))){
			$file->process($build);
		}
	}

	/**
	 * Pops a ContentFile from one of the file type lists
	 *
	 * @return false|ContentFile
	 * @throws Exception
	 */
	public function popType(int $type){
		if (!self::validateType($type)){
			throw new RuntimeException('Invalid content file type '.$type);
		}
		if (count($this->filenames[$type])===0){
			return false;
		}

		return new ContentFile(array_pop($this->filenames[$type]));
	}

	/**
	 * Checks if a type is valid
	 *
	 * @param int $type
	 *
	 * @return bool
	 */
	private static function validateType(int $type): bool{
		return ($type===ContentFile::TYPE_COPY || $type===ContentFile::TYPE_PARSE);
	}
}