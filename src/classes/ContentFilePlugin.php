<?php

namespace Gyokuto;

interface ContentFilePlugin {
	public static function processHtml(string $html, Build $build): string;
}