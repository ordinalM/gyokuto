<?php

namespace Gyokuto;

use RuntimeException;

class Build {
	private const ENV_CONTENT_DIR = 'GYOKUTO_CONTENT_DIR';
	private const ENV_OUTPUT_DIR = 'GYOKUTO_OUTPUT_DIR';
	private const ENV_TEMP_DIR = 'GYOKUTO_TEMP_DIR';
	private $content_dir = '';
	private $output_dir = '';
	private $temp_dir = '';

	public function __construct(){
		$this->content_dir = getenv(self::ENV_CONTENT_DIR) ?? './content';
		$this->output_dir = getenv(self::ENV_OUTPUT_DIR) ?? './www';
		$this->temp_dir = getenv(self::ENV_TEMP_DIR) ?? sprintf('%s/%s', sys_get_temp_dir(), uniqid('gyokuto_', true));
	}

	/**
	 * Begins a build run
	 */
	public function run(){
		$this->log("Build starting");
		$this->validateBuild()
			->indexContentFiles();
	}

	private function log(string $string){
		printf("%s\n", $string);
	}

	private function indexContentFiles(){

	}

	private function validateBuild(){
		// Check we can begin the run at all
		$errors = [];
		if (!is_dir($this->content_dir)){
			$errors[] = 'Content directory is not valid';
		}
		if (!is_dir($this->output_dir)){
			$errors[] = 'Output directory is not valid';
		}
		elseif (!is_writable($this->output_dir)) {
			$errors[] = 'Output directory cannot be written to';
		}
		if (!empty($errors)){
			throw new RuntimeException('Build was not properly configured: '.implode(', ', $errors));
		}

		return $this;
	}

	/**
	 * Cleans up a run, removing all temp files
	 */
	private function cleanup(){
		if (is_dir($this->temp_dir)){
			unlink($this->temp_dir);
		}
	}
}
