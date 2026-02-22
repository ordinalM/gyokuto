<?php

namespace Gyokuto;

class Zettelkasten implements ContentFilePlugin {
	private const array KEYS_ZETTEL_ID = ['zid', 'id', 'zettel'];
	private const string KEY_ZETTEL_INDEX = 'zettel';

	/**
	 * @throws GyokutoException
	 */
	public static function processHtml(string $html, Build $build): string{
		$zettel_index = self::getZettelIndex($build);

        return preg_replace_callback('/href\s*=\s*\"(\d+)\"/', static function ($matches) use ($zettel_index){
            $zid = (int) $matches[1];
            $href = $matches[0];
            if (!isset($zettel_index[$zid])){
                return $href;
            }
            $href = str_replace((string) $zid, $zettel_index[$zid], $href);
            Utils::getLogger()
                ->debug("Replaced zettel ID $zid with $zettel_index[$zid]");

            return $href;
        }, $html);
	}

	/**
	 * @return array<int, string>
	 * @throws GyokutoException
	 */
	public static function getZettelIndex(Build $build): array{
        $logger = Utils::getLogger();
        $logger->debug('Building Zettel index');
		$build_metadata = $build->getBuildMetadata();
		$zettel_index = $build_metadata[ContentFileList::KEY_PAGES_BY_META][self::KEY_ZETTEL_INDEX] ?? false;
		if ($zettel_index){
			return $build_metadata[ContentFileList::KEY_PAGES_BY_META][self::KEY_ZETTEL_INDEX];
		}
		$zettel_index = self::createZettelIndex($build);
		$build->setBuildMetadata(array_merge_recursive($build_metadata, [
			ContentFileList::KEY_PAGES_BY_META => [self::KEY_ZETTEL_INDEX => $zettel_index,],
		]));
		$logger->debug('Zettel index built added to build metadata', $build->getBuildMetadata());

		return $zettel_index;
	}

	/**
	 * @return array<int, string>
	 * @throws GyokutoException
	 */
	private static function createZettelIndex(Build $build): array{
		$zettel_index = [];
        /** @var array<string, mixed> $page */
        foreach ($build->getBuildMetadata()[ContentFileList::KEY_PAGE_INDEX] as $page){
			// Get zid
			$zid = self::getZidFromPageMeta($page[ContentFile::KEY_META]);
			if (!$zid){
				continue;
			}
			$path = $page[ContentFile::KEY_PATH];
            if (!is_string($path)){
                throw new GyokutoException('$path found that is not a string');
            }
			if (isset($zettel_index[$zid])){
				throw new GyokutoException("Duplicate Zettel ID $zid found for paths $path and $zettel_index[$zid]");
			}
			$zettel_index[$zid] = $path;
		}

		return $zettel_index;
	}

    /**
     * @param array<string, mixed> $meta
     */
    private static function getZidFromPageMeta(array $meta): ?int{
		foreach (self::KEYS_ZETTEL_ID as $key){
			if (self::isValidZettelId($meta[$key] ?? null)){
				return (int) $meta[$key];
			}
		}

		return null;
	}

    /**
     * @phpstan-assert-if-true string|int $key
     */
    public static function isValidZettelId(mixed $key): bool{
		if (is_int($key)){
			return true;
		}
		if (is_string($key) && preg_match('/^\d+$/', $key)){
			return true;
		}

		return false;
	}
}