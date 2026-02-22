<?php

namespace Gyokuto;

use Exception;
use RuntimeException;

class ContentFileList
{
    public const string KEY_PAGES_BY_META = 'index';
    public const string KEY_PAGE_INDEX = 'pages';
    /**
     * @var array{0: list<string>, 1: list<string>}
     */
    private array $filenames = [ContentFile::TYPE_PARSE => [], ContentFile::TYPE_COPY => []];

    public static function createFromDirectory(string $content_dir): ContentFileList
    {
        Utils::getLogger()->info('Indexing content files in ' . realpath($content_dir));
        $all_files = Utils::findFilesRecursive($content_dir);
        $list = new self;
        $file_count = 0;
        foreach ($all_files as $filename) {
            $list->push($filename);
            $file_count++;
        }
        if ($file_count === 0) {
            throw new RuntimeException('No files found - is the content directory correct?');
        }
        Utils::getLogger()->info('Files found: ' . $file_count);

        return $list;
    }

    /**
     * Pushes a file onto the appropriate file type list
     */
    public function push(string $filename): self
    {
        $this->filenames[ContentFile::filenameIsParsable($filename) ? ContentFile::TYPE_PARSE : ContentFile::TYPE_COPY][] = $filename;

        return $this;
    }

    /**
     * Looks through the content files that exist in this list and compiles their metadata for use by templates.
     * Also provides a master list of pages.
     *
     * @param Build $build
     *
     * @return array{index: array<string, array<string, list<string>>>, pages: array<string, array{meta: array<string, mixed>, path: string}>}
     * @throws Exception
     */
    public function compileContentMetadata(Build $build): array
    {
        Utils::getLogger()->info('Indexing page metadata');

        $pages_by_meta = [];
        $page_index = [];
        $keys_to_index = $build->config['index'] ?? [];
        if (count($keys_to_index) > 0) {
            Utils::getLogger()->debug('Indexing metadata keys:', $keys_to_index);
        }
        foreach ($this->filenames[ContentFile::TYPE_PARSE] as $filename) {
            $content_file = new ContentFile($filename);
            $page_meta = $content_file->meta;
            // Don't index anything in draft pages
            if (($page_meta[ContentFile::KEY_META_DRAFT] ?? false) || ($page_meta[ContentFile::KEY_META_HIDDEN] ?? false)) {
                continue;
            }
            $page_path = $content_file->getPath($build);
            $page_index[$page_path] = $content_file->getBasePageData($build);
            if (count($keys_to_index) > 0) {
                foreach ($keys_to_index as $k) {
                    if (isset($page_meta[$k])) {
                        if (!isset($pages_by_meta[$k])) {
                            $pages_by_meta[$k] = [];
                        }
                        $v = $page_meta[$k];
                        if (!is_array($v)) {
                            $v = [$v];
                        }
                        foreach ($v as $v_sub) {
                            if (!isset($pages_by_meta[$k][$v_sub])) {
                                $pages_by_meta[$k][$v_sub] = [];
                            }
                            $pages_by_meta[$k][$v_sub][] = $page_path;
                        }
                    }
                }
            }
        }

        // Sort each index by value of indexed key
        foreach ($pages_by_meta as &$v) {
            ksort($v);
        }
        unset($v);

        // Sort page index by descending date
        /** @var array<string, array{meta: array<string, mixed>, path: string}> $page_index */
        uasort($page_index, static function ($a, $b) {
            return $b[ContentFile::KEY_META][ContentFile::KEY_META_DATE] <=> $a[ContentFile::KEY_META][ContentFile::KEY_META_DATE];
        });
        Utils::getLogger()->debug('Page list sorted', $page_index);

        return [self::KEY_PAGES_BY_META => $pages_by_meta, self::KEY_PAGE_INDEX => $page_index];
    }

    /**
     * Processes all files in this content list
     *
     * @throws Exception
     */
    public function process(Build $build): void
    {
        Utils::getLogger()->info('Building content');

        // Build the Zettel ID index in metadata
        Zettelkasten::getZettelIndex($build);

        while (null !== ($file = $this->popType(ContentFile::TYPE_COPY))) {
            $file->process($build);
        }
        while (null !== ($file = $this->popType(ContentFile::TYPE_PARSE))) {
            $file->process($build);
        }
    }

    /**
     * Pops a ContentFile from one of the file type lists, or null if nothing left
     *
     * @param ContentFile::TYPE_* $type
     * @return ContentFile|null
     * @throws Exception
     */
    public function popType(int $type): ?ContentFile
    {
        if (!self::validateType($type)) {
            throw new RuntimeException('Invalid content file type ' . $type);
        }
        if (count($this->filenames[$type]) === 0) {
            return null;
        }

        $next_filename = array_pop($this->filenames[$type]);
        if (!$next_filename) {
            return null;
        }

        return new ContentFile($next_filename);
    }

    /**
     * Checks if a type is valid
     *
     * @param int $type
     *
     * @return bool
     */
    private static function validateType(int $type): bool
    {
        return ($type === ContentFile::TYPE_COPY || $type === ContentFile::TYPE_PARSE);
    }
}