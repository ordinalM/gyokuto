<?php

namespace Gyokuto;

use RuntimeException;

class ContentFileList {
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
	 * Looks through the content files that exist in this list and compiles their metadata for use by templates
	 *
	 * @param Build $build
	 *
	 * @return array[]
	 */
	public function compileMetadata(Build $build){
		Utils::getLogger()
			->info('Indexing page metadata');

		$page_index = [];
		$pages_by_path = [];
		$keys_to_index = $build->getOptions()['index'] ?? [];
		if (count($keys_to_index)>0){
			Utils::getLogger()
				->debug('Indexing metadata keys', $keys_to_index);
		}
		foreach ($this->filenames[ContentFile::TYPE_PARSE] as $filename){
			$content_file = new ContentFile($filename);
			$page_meta = $content_file->getMeta();
			$page_path = $content_file->getPath($build);
			$pages_by_path[$page_path] = $content_file->getBasePageData($build);
			if (!empty($keys_to_index)){
				foreach ($keys_to_index as $k){
					if (isset($page_meta[$k])){
						if (!isset($page_index[$k])){
							$page_index[$k] = [];
						}
						$v = $page_meta[$k];
						if (!is_array($v)){
							$v = [$v];
						}
						foreach ($v as $v_sub){
							if (!isset($page_index[$k][$v_sub])){
								$page_index[$k][$v_sub] = [];
							}
							$page_index[$k][$v_sub][] = $page_path;
						}
					}
				}
			}
		}

		// Sort each index by value of indexed key
		foreach ($page_index as $k => &$v){
			ksort($v);
		}

		// Sort pages by descending date
		uasort($pages_by_path, function ($a, $b){
			return $b['date']<=>$a['date'];
		});

		return ['index' => $page_index, 'pages' => $pages_by_path];
	}

	/**
	 * Processes all files in this content list
	 *
	 * @param Build $build
	 */
	public function process(Build $build){
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
	 * @param $type
	 *
	 * @return false|ContentFile
	 */
	public function popType(int $type){
		self::validateType($type);
		if (count($this->filenames[$type])===0){
			return false;
		}

		return new ContentFile(array_pop($this->filenames[$type]));
	}

	/**
	 * Throws an exception if a type is not valid
	 *
	 * @param int $type
	 */
	private static function validateType(int $type){
		if ($type!=ContentFile::TYPE_COPY && $type!=ContentFile::TYPE_PARSE){
			throw new RuntimeException('Invalid content file type '.$type);
		}
	}
}