<?php

namespace Gyokuto;

use Exception;
use RuntimeException;
use Symfony\Component\Yaml\Yaml;
use Twig\Error\LoaderError;
use Twig\Error\RuntimeError;
use Twig\Error\SyntaxError;

class ContentFile
{
    public const int TYPE_PARSE = 0;
    public const int TYPE_COPY = 1;
    public const string KEY_META_DRAFT = 'draft';
    public const string KEY_META_HIDDEN = 'hidden';
    public const string KEY_META = 'meta';
    public const string KEY_META_DATE = 'date';
    private const string KEY_META_MODIFIED = 'modified';
    private const string KEY_META_CREATED = 'created';
    private const string KEY_META_TITLE = 'title';
    private const string KEY_CONTENT = 'content';
    private const string KEY_CURRENT_PAGE = 'current_page';
    private const string KEY_CONFIG = 'config';
    public const string KEY_PATH = 'path';
    private const string REGEX_MARKDOWN_EXTENSION = '/\.(md|markdown)$/';

    /**
     * @param array<string, mixed>|null $meta
     * @throws Exception
     */
    public function __construct(private readonly string $filename, private ?string $markdown = null, public ?array $meta = null)
    {
        if (!is_file($filename)) {
            throw new RuntimeException('Bad filename ' . $filename);
        }
        $this->readAndSplit();
    }

    /**
     * Processes the content file, pulling metadata and raw Markdown.
     *
     * @throws Exception
     */
    private function readAndSplit(): void
    {
        if (!$this->isParsable()) {
            return;
        }
        $raw = Utils::getFileContentsOrThrow($this->filename);
        if (preg_match('/^---\n(.+?)\n---\n\s*(.*)\s*$/s', $raw, $matches)) {
            $this->meta = Yaml::parse($matches[1]);
            $this->markdown = $matches[2];
        } else {
            $this->meta = [];
            $this->markdown = $raw;
        }
        // Try to parse the date if it's left it as a string
        // If it has been turned into an int, we assume YAML has parsed it already.
        if (isset($this->meta[self::KEY_META_DATE]) && is_string($this->meta[self::KEY_META_DATE])) {
            $parsed_date = strtotime($this->meta[self::KEY_META_DATE]);
            if ($parsed_date === false) {
                $f = json_encode($this->meta) ?: '<unknown>';
                throw new Exception("Tried to parse date field as a date but it didn't work - full meta is: $f");
            }
            if ((string)$parsed_date !== $this->meta[self::KEY_META_DATE]) {
                $this->setMetaValue(self::KEY_META_DATE, $parsed_date);
            }
        }
        // If the title is missing or empty, generate it. (It may be an empty string, which we can't use.)
        if (empty($this->meta[self::KEY_META_TITLE])) {
            $this->setMetaValue(self::KEY_META_TITLE, $this->getTitleFromFilename());
        }
        // Otherwise, set defaults.
        $this->meta += [
            self::KEY_META_DATE => filemtime($this->filename),
            self::KEY_META_CREATED => filectime($this->filename),
            self::KEY_META_MODIFIED => filemtime($this->filename),
        ];
    }

    private function isParsable(): bool
    {
        return self::filenameIsParsable($this->filename);
    }

    public static function filenameIsParsable(string $filename): bool
    {
        return (bool)preg_match(self::REGEX_MARKDOWN_EXTENSION, $filename);
    }

    private function setMetaValue(string $key, mixed $value): void
    {
        $meta = $this->meta;
        $meta[$key] = $value;
        $this->meta = $meta;
    }

    /**
     * Generates a title from the filename, in cases where there is no title metadata
     *
     * @return string
     */
    private function getTitleFromFilename(): string
    {
        $title = basename($this->filename);
        $title = preg_replace(['/\.[^.]+$/', '/[-_]+/'], ['', ' '], $title);
        assert(!is_null($title));

        return trim($title);
    }

    /**
     * @param Build $build
     *
     * @throws Exception
     */
    public function process(Build $build): void
    {
        $target_filename = $this->getBuildFilename($build);
        if (!is_dir(dirname($target_filename)) && !mkdir($concurrentDirectory = dirname($target_filename), 0755, true) && !is_dir($concurrentDirectory)) {
            throw new RuntimeException('Could not create target dir ' . dirname($target_filename));
        }
        if (!$this->isParsable()) {
            copy($this->filename, $target_filename);
            Utils::getLogger()
                ->debug('Copied file', [$this->filename, $target_filename]);

            return;
        }
        if ($this->meta[self::KEY_META_DRAFT] ?? false) {
            Utils::getLogger()
                ->debug('Skipping draft file', $this->meta);

            return;
        }
        $html = $this->render($build);
        $html = Zettelkasten::processHtml($html, $build);
        file_put_contents($target_filename, $html);
        Utils::getLogger()
            ->debug('Wrote parsed file', [$this->filename, $target_filename]);
    }

    private function getBuildFilename(Build $build): string
    {
        return $build->getTempDir() . $this->getPath($build, false);
    }

    /**
     * @param Build $build
     * @param bool $strip_index
     *
     * @return string
     */
    public function getPath(Build $build, bool $strip_index = true): string
    {
        // If path metadata value is set, use that, otherwise calculate output path based on the content filename.
        if (empty($this->meta[self::KEY_PATH])) {
            $path = preg_replace(self::REGEX_MARKDOWN_EXTENSION, '.html', $this->filename) ?? '';
            if ($strip_index) {
                $path = preg_replace('|index\.html$|', '', $path);
                assert(!is_null($path));
            }
            $path = str_replace($build->getContentDir(), '', $path);
        } else {
            $path = $this->meta[self::KEY_PATH];
        }

        return '/' . ltrim($path, '/');
    }

    /**
     * @throws SyntaxError
     * @throws RuntimeError
     * @throws LoaderError
     * @throws Exception
     */
    private function render(Build $build): string
    {
        $page_params = [
            self::KEY_CURRENT_PAGE => $this->getBasePageData($build),
            self::KEY_CONFIG => $build->config,
        ];
        $page_params[self::KEY_CURRENT_PAGE][self::KEY_CONTENT] = $this->getMarkdown();
        $page_params = array_merge($build->getBuildMetadata(), $page_params);

        // Render Markdown content, using Twig content filter first
        Utils::getLogger()
            ->debug('Rendering', ['meta' => $this->meta]);

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
     * @return array{meta: array<string, mixed>, path: string}
     */
    public function getBasePageData(Build $build): array
    {
        if (is_null($this->meta)) {
            throw new RuntimeException('Meta has not been built yet');
        }

        return [
            self::KEY_META => $this->meta,
            self::KEY_PATH => $this->getPath($build),
        ];
    }

    /**
     * @throws Exception
     */
    private function getMarkdown(): string
    {
        if (is_null($this->markdown)) {
            $this->readAndSplit();
        }
        assert(!is_null($this->markdown));

        return $this->markdown;
    }

    /**
     * @return string
     */
    private function getTemplate(): string
    {
        return $this->meta['template'] ?? 'default.twig';
    }

}