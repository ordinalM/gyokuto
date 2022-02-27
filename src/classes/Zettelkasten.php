<?php

namespace Gyokuto;

class Zettelkasten implements ContentFilePlugin {
	private const KEY_ZETTEL_ID = 'zid';
	private const KEY_ZETTEL_INDEX = 'zettel';

	public static function processHtml(string $html, Build $build): string{
		$zettel_index = self::getZettelIndex($build);

		$html = preg_replace_callback('/href\s*=\s*\"(\d+)\"/', static function ($matches) use ($zettel_index){
			$zid = (int)$matches[1];
			$href = $matches[0];
			if (!isset($zettel_index[$zid])) {
				return $href;
			}
			$href = str_replace((string)$zid, $zettel_index[$zid], $href);
			Utils::getLogger()->debug("Replaced zettel ID $zid with $zettel_index[$zid]");

			return $href;
		}, $html);

		return $html;
	}

	/**
	 * @return array<string, int>
	 */
	private static function getZettelIndex(Build $build): array{
		$build_metadata = $build->getBuildMetadata();
		if (isset($build_metadata[self::KEY_ZETTEL_INDEX])){
			return $build_metadata[self::KEY_ZETTEL_INDEX];
		}
		$zettel_index = [];
		foreach ($build->getBuildMetadata()[ContentFileList::KEY_PAGE_INDEX] as $page){
			// Get zid
			$zid = self::getZidFromPageMeta($page[ContentFile::KEY_META]);
			if (!$zid){
				continue;
			}
			$zettel_index[$zid] = $page[ContentFile::KEY_PATH];
		}
		$build->setBuildMetadata(array_merge($build_metadata, [
			self::KEY_ZETTEL_INDEX => $zettel_index,
		]));

		return $zettel_index;
	}

	private static function getZidFromPageMeta(array $meta): ?int{
		if ($meta[self::KEY_ZETTEL_ID] ?? false){
			return (int) $meta[self::KEY_ZETTEL_ID];
		}

		return null;
	}
}