<?php

namespace Gyokuto;

use Monolog\Handler\ErrorLogHandler;
use Monolog\Logger;
use RuntimeException;

class Utils {
	private static $log_level = Logger::INFO;

	public static function getLogger(): Logger{
		static $logger;
		if (!isset($logger)){
			$logger = new Logger(__NAMESPACE__);
			$logger->pushHandler(new ErrorLogHandler(ErrorLogHandler::OPERATING_SYSTEM, self::$log_level));
		}

		return $logger;
	}

	/**
	 * @param int $log_level
	 */
	public static function setLogLevel(int $log_level): void{
		self::$log_level = $log_level;
	}

	/**
	 * Deletes an entire directory.
	 *
	 * @param string $dir
	 *
	 * @return bool
	 */
	public static function deleteDir(string $dir): bool{
		$dir = realpath($dir);
		if (!is_dir($dir)) {
			return true;
		}

		$files = Utils::getDirectoryContents($dir);

		foreach ($files as $file){
			$file = $dir.'/'.$file;
			if (is_dir($file)){
				self::deleteDir($file);
			}
			elseif (!unlink($file)) {
				throw new RuntimeException('Could not delete file '.$file);
			}
		}

		if (!rmdir($dir)){
			throw new RuntimeException('Could not delete dir '.$dir);
		}

		return true;
	}

	public static function getDirectoryContents(string $dir): array{
		return array_values(array_diff(scandir($dir), ['..', '.']));
	}

	public static function findFilesRecursive(string $dir, callable $filter = null): array{
		if (!is_dir($dir)){
			throw new RuntimeException('Directory "'.$dir.'" is not a directory');
		}

		$dir = realpath($dir);
		$result_files = [];

		$files = array_diff(scandir($dir), ['..', '.', '.DS_Store']);
		if ($filter){
			$files = array_filter($files, $filter);
		}
		while (count($files)>0){
			$file = sprintf('%s/%s', $dir, rtrim(array_pop($files), '/'));
			if (is_dir($file)){
				$result_files = array_merge($result_files, self::findFilesRecursive($file, $filter));
			}
			else {
				$result_files[] = $file;
			}
		}

		return $result_files;
	}

}