<?php
/**
 * http://yokai.com/gyokuto/
 */
namespace Gyokuto;

require_once(__DIR__ . '/../vendor/autoload.php');
error_reporting(E_ALL);

use \Twig\Extra\Markdown\DefaultMarkdown;
use \Twig\Extra\Markdown\MarkdownRuntime;
use \Twig\RuntimeLoader\RuntimeLoaderInterface;

class App {
  const BUILD_OP_PARSE = 0;
  const BUILD_OP_BUILD = 1;
  public $debug = FALSE;

  public function __construct(array $config = []) {
    if (!empty($config['debug'])) {
      $this->debug = TRUE;
    }
    // The root of the entire application should be this.
    $this->app_base = realpath(__DIR__ . '/..');

    // Default configuration
    $this->config = array(
      'base_url'          => '',
      'config_dir'        => $this->app_base . '/config',
      'watch_interval'    => 1,
      'metadata_index'    => [], // A list of metadata tags to index posts under
      'queue_buffer_size' => 1000,
      'output_variable'   => [],
      'exclude_files'     => [],
    );

    // Merge in passed config - this can modify the config dir so do this first
    foreach ($config as $k => $v) {
      $this->config[$k] = $v;
    }

    // Read in and merge config files.
    if (is_dir($this->config['config_dir'])) {
      $config_files = scandir($this->config['config_dir']);
      sort($config_files);
      foreach ($config_files as $file) {
        $file = $this->config['config_dir'] . '/' . $file;
        if (static::fileIsYaml($file) && ($config = \Symfony\Component\Yaml\Yaml::parseFile($file))) {
          foreach ($config as $k => $v) {
            $this->config[$k] = $v;
          }
        }
      }
    }
    else {
      throw new \Exception('Config dir is not a directory: ' . $this->config['config_dir']);
    }

    // Calculate default directories
    $default_folder = realpath($this->config['config_dir'] . '/..') . '/';
    foreach ([
      'content_dir'       => 'content',
      'template_dir'      => 'templates',
      'output_dir'        => 'public_html',
      'cache_dir'         => 'cache',
    ] as $item => $subfolder) {
      if (!isset($this->config[$item])) {
        $this->config[$item] = $default_folder . $subfolder;
      }
    }

    // Trim RHS slashes on directories
    foreach ([ 'base_url', 'content_dir', 'template_dir', 'output_dir', 'config_dir' ] as $option) {
      $this->config[$option] = rtrim($this->config[$option], '/');
    }

    if ($this->debug) {
      $this->log("Config loaded:\n---\n" . \Symfony\Component\Yaml\Yaml::dump($this->config) . "---");
    }

    // Check content directory.
    if (!file_exists($this->config['content_dir']) && is_dir($this->config['content_dir'])) {
      throw new \Exception('Cannot find content directory at ' . $this->config['content_dir']);
    }

    // Check that the configured template directory exists.
    if (!is_dir($this->config['template_dir'])) {
      throw new \Exception('Could not find template directory at ' . $this->config['template_dir']);
    }

    $this->loadTwigEnvironment();
  }

  public function loadTwigEnvironment() {
    unset($this->twig);
    // This is the application template folder.
    $loader_file_src = new \Twig\Loader\FilesystemLoader($this->app_base . '/src/templates');
    // This is the user template folder.
    $loader_file = new \Twig\Loader\FilesystemLoader($this->config['template_dir']);
    // Chain them together
    $loader = new \Twig\Loader\ChainLoader([ $loader_file, $loader_file_src ]);
    $this->twig = new \Twig\Environment(
      $loader,
      [
        'autoescape' => FALSE,
        'strict_variables' => TRUE,
        'auto_reload' => TRUE,
        'cache' => $this->config['cache_dir'] . '/twig',
      ]
    );
    $this->twig->addRuntimeLoader(new class implements RuntimeLoaderInterface {
        public function load($class) {
            if (MarkdownRuntime::class === $class) {
                return new MarkdownRuntime(new DefaultMarkdown());
            }
        }
    });    // We use the string loader extension to parse Twig in markdown body.
    $this->twig->addExtension(new \Twig\Extension\StringLoaderExtension());
    $this->twig->addExtension(new \Twig\Extra\Markdown\MarkdownExtension());
    // Add a function for making galleries.
    $this->twig->addFunction(new \Twig\TwigFunction('gyokuto_gallery', '\Gyokuto\App::makeGallery', [ 'needs_context' => TRUE ]));
  }

  public function build() {
    while(!($this->buildTry())) {}
    return TRUE;
  }

  public function buildTry() {
    try {
      $this->prepareBuild();
      if ($this->performBuildOp()) {
        return FALSE;
      }
      $this->outputBuild();
      $this->clearBuild();
    }
    catch(Exception $e) {
      $this->log("ERROR\n" . (string)$e);
      $this->clearBuild();
      return TRUE;
    }
    $this->finishBuild();
    return TRUE;
  }

  /**
   * Clear cached build
   */
  public function clearBuild() {
    if (is_dir($build_cache = $this->config['cache_dir'] . '/build')) {
      $this->deleteDir($build_cache);
      $this->log('Cleared cached build');
    }
    else {
      $this->log('No cached build to clear');
    }
  }

  /**
   * Initialise or load current build
   */
  public function prepareBuild() {
    if ($build = $this->loadCurrentBuild()) {
      if ($this->debug) {
        $this->log('Existing build found, loading and continuing');
      }
      $this->build = $build;
    }
    else {
      $this->log('Starting new build at ' . date('c'));
      $this->build = [
        'config' => [
          'id' => uniqid('gbuild_'),
          'start_microtime' => microtime(TRUE),
          'metadata_index' => [],
          'parsed' => 0,
          'built' => 0,
          'dir' => $this->config['cache_dir'] . '/build/public_html',
          'queue_id' => 'build/data/queue',
        ],
        'pages' => [],
      ];

      // Create build dir
      if (mkdir($this->build['config']['dir'], 0775, TRUE)) {
        $this->log('Created build base dir at ' . $this->build['config']['dir']);
      }
      else {
        throw new \Exception('Could not create build base dir at ' . $this->build['config']['dir']);
      }

      // Collate content
      $content_files = array_map(
        function ($file) { return $this->_stripContentDir($file); },
        $this->findAllFiles($this->config['content_dir'])
      );

      $this->log('Content files found: ' . count($content_files));
      foreach ($this->config['metadata_index'] as $item) {
        $this->build['config']['metadata_index'][$item] = [];
      }

      // Calculate required operations
      $queue = [];
      foreach ([static::BUILD_OP_PARSE, static::BUILD_OP_BUILD] as $op) {
        foreach ($content_files as $file) {
          $queue[] = [ $op, $file ];
          if (count($queue) % 1000 == 0) {
            $this->addQueueItems($this->build['config']['queue_id'], $queue);
            $queue = [];
          }
        }
      }
      $this->addQueueItems($this->build['config']['queue_id'], $queue);

      $this->log('Added required operations to queue');

      // Cache this build
      $this->saveCurrentBuild();
    }
  }

  function performBuildOp() {
    $this->config['queue_buffer_size'] = 1000;
    $queue_buffer = $this->getQueueItems($this->build['config']['queue_id'], $this->config['queue_buffer_size']);
    if (empty($queue_buffer)) {
      if ($this->debug) {
        $this->log('No build queue items left');
      }
      return FALSE;
    }
    $this->loadTwigEnvironment();
    foreach ($queue_buffer as $item) {
      list($op, $file) = $item;
      $file = $this->config['content_dir'] . $file;
      $recache = FALSE;
      switch($op) {
        case static::BUILD_OP_PARSE:
          // This pass just parses all the metadata for markdown files.
          if (($page_data = $this->parseMarkdownFileToPage($file)) && empty($page_data['meta']['draft'])) {
            $this->build['pages'][$page_data['id']] = $page_data;
            $this->indexMetadata($page_data);
            $this->build['config']['parsed']++;
            if ($this->build['config']['parsed'] % 500 == 0) {
              $this->log(sprintf('Parsed %d files', $this->build['config']['parsed']));
            }
          }
          break;
        case static::BUILD_OP_BUILD:
          // This is the final build phase.
          $this->log(
            sprintf("%d\t%s", ++$this->build['config']['built'], $this->processContentToBuild($file))
          );
          break;
        default:
          throw new \Exception(sprintf('Build operation %d not understood', $op));
      }
    }
    $this->saveCurrentBuild();
    $this->removeQueueItems($this->build['config']['queue_id'], count($queue_buffer));
    if ($this->debug) {
      $this->log('Finished build run');
    }
    return TRUE;
  }

  public function loadCurrentBuild() {
    $build_cache_dir = $this->config['cache_dir'] . '/build/data';
    if (!is_dir($build_cache_dir)) {
      return FALSE;
    }
    $build = [];
    foreach (array_diff(scandir($this->config['cache_dir'] . '/build/data'), array('..', '.')) as $index) {
      $build[$index] = $this->cacheGet('build/data/' . $index);
    }
    return empty($build) ? FALSE : $build;
  }

  public function saveCurrentBuild() {
    foreach ($this->build as $index => $data) {
      if ($index != 'queue') {
        if ($this->debug) {
          $this->log('Saving CID build/data/' . $index);
        }
        $this->cacheSet('build/data/' . $index, $data);
      }
    }
  }

  public function outputBuild() {
    // Output to output base
    if (file_exists($this->config['output_dir'])) {
      $this->log('Removing old output dir ' . $this->config['output_dir']);
      if (is_dir($this->config['output_dir'])) {
        $this->deleteDir($this->config['output_dir']);
      }
      else {
        throw new Exception($this->config['output_dir'] . ' is not a directory for some reason');
      }
    }
    $this->log(sprintf('Moving build dir %s to output dir %s', $this->build['config']['dir'], $this->config['output_dir']));
    rename($this->build['config']['dir'], $this->config['output_dir']);
  }

  public function finishBuild() {
    if ($this->debug) {
      $this->log('Finishing build...');
    }
    // Remove build dir if it wasn't moved (probably because of an exception)
    if (file_exists($this->build['config']['dir'])) {
      $this->log('Deleting build dir ' . $this->build['config']['dir']);
      $this->deleteDir($this->build['config']['dir']);
    }

    // Output the exception if it interrupted build
    if (isset($e)) {
      $this->log((string)$e);
    }

    $this->log(sprintf('Finished in %f seconds', (microtime(TRUE) - $this->build['config']['start_microtime'])));
  }

  /**
   * Find all files in a directory recursively.
   */
  public function findAllFiles($base_dir, $options = array(), &$files = array()) {
    $options = array_merge(
      [
        'include_all'      => FALSE,
        'save_to_queue_id' => FALSE,
      ],
      $options
    );
    $dirname = rtrim($base_dir, '/') . '/';
    $content = array_diff(scandir($dirname), array('..', '.'));
    foreach ($content as $file) {
      if ($options['include_all'] || !(in_array(basename($file), $this->config['exclude_files']) || $this->_checkFileVsExcludeRegex($file))) {
        $file = $dirname . $file;
        $add_this_file = TRUE;
        if (is_dir($file)) {
          $this->findAllFiles($file, $options, $files);
          $add_this_file = $options['include_all'];
        }
        if ($add_this_file) {
          if ($options['save_to_queue_id']) {
            $this->addQueueItems($options['save_to_queue_id'], $file);
          }
          else {
            $files[] = $file;
          }
        }
      }
    }
    return $files;
  }

  public function _checkFileVsExcludeRegex($file) {
    $file = basename($file);
    if (!empty($this->config['exclude_regex'])) {
      foreach ($this->config['exclude_regex'] as $regex) {
        if (preg_match("/$regex/", $file)) {
          return TRUE;
        }
      }
    }
    return FALSE;
  }

  /**
   * Initial pass to get metadata from pages.
   */
  public function parseMarkdownFileToPage(string $file) {
    if (!static::fileIsMarkdown($file)) {
      return FALSE;
    }
    $raw = file_get_contents($file);
    $page_meta = array_merge(
      array(
        'template' => 'default.twig',
        'date' => filemtime($file),
      ),
      static::splitMarkdownFile($raw)['meta']
    );
    if (!isset($page_meta['output_file'])) {
      $output_file = preg_replace('/(\.md)$/', '.html', $file);
    }
    elseif (empty(trim($page_meta['output_file']))) {
      $output_file = FALSE;
      $this->log($file . ' has empty output_file');
    }
    else {
      $output_file = $this->config['content_dir'] . '/' . ltrim($page_meta['output_file'], '/');
    }
    $path = $this->_stripContentDir($output_file);
    $page = array(
      'meta' => $page_meta,
      'original_path' => $this->config['base_url'] . $path,
      '_build_file' => $output_file === FALSE ? FALSE : $this->_contentToBuildFile($output_file),
      'id' => $this->getPageIdFromContentFile($file),
    );
    $page['path'] = preg_replace('/\/index\.html$/', '', $page['original_path']);
    if ($page['path'] == '') {
      $page['path'] = '/';
    }
    $page['level'] = count(explode('/', trim($page['path'], '/'))) - 1;
    return $page;
  }

/**
 * Index any metadata in a page as per config.
 */
  public function indexMetadata($page) {
    if (empty($this->config['metadata_index'])) {
      return;
    }
    foreach ($this->config['metadata_index'] as $name) {
      if (isset($page['meta'][$name])) {
        $values = (is_string($page['meta'][$name]) || is_integer($page['meta'][$name])) ? [ $page['meta'][$name] ] : $page['meta'][$name];
        foreach ($values as $i) {
          if (!isset($this->build['config']['metadata_index'][$name][$i])) {
            $this->build['config']['metadata_index'][$name][$i] = [];
          }
          $this->build['config']['metadata_index'][$name][$i][$page['id']] = $page['id'];
        }
      }
    }
  }

  /**
   * Process an individual content file in final pass and write build files.
   */
  public function processContentToBuild($file) {
    $start = microtime(TRUE);
    $action = FALSE;

    // Calculate build filename and create directories if necessary
    $build_file = $this->_contentToBuildFile($file);
    $build_file_dir = dirname($build_file);
    if (!is_dir($build_file_dir)) {
      mkdir($build_file_dir, 0775, TRUE);
    }

    // Decide what to do with the file
    if (static::fileIsMarkdown($file)) {
//       $mp = new \Pagerange\Markdown\MetaParsedown();
      // Render markdown files
      $page_index = $this->getPageIdFromContentFile($file);
      // Check that we have data at all
      if (isset($this->build['pages'][$page_index]) && ($source_page = $this->build['pages'][$page_index])) {
        $pages_to_build = [];
        // Is this paginated?
        if (isset($source_page['meta']['pagination'])) {
          if ($source_page['meta']['pagination']['data'] == 'pages') {
            // For pagination on pages array, split build data up into multiple output pages.
            // Subset existing pages array to match what template wants
            $pages = array_filter($this->build['pages'], function ($page) use ($source_page) {
              return (
                $page['id'] != $source_page['id'] // not the calling page
                && !empty($page['meta']['title']) // has title
                && empty($page['meta']['hidden']) // not hidden
                && (
                  empty($source_page['meta']['index_options']['subpage_prefix'])
                  || preg_match('|^' . preg_quote($source_page['meta']['index_options']['subpage_prefix']) . '|', $page['path'])
                ) // correct page path prefix
                && (
                  empty($source_page['meta']['index_options']['templates'])
                  || in_array($page['meta']['template'], $source_page['meta']['index_options']['templates'])
                ) // correct template
              );
            });
            // Sort by date descending
            usort($pages, function ($a, $b) { return -($a['meta']['date'] <=> $b['meta']['date']); });
            $per_page = empty($source_page['meta']['pagination']['per_page']) ? 20 : $source_page['meta']['pagination']['per_page'];
            $total_chunks = ceil(count($pages) / $per_page);
            $pager = [ $source_page['path'] ];
            for ($p = 2; $p <= $total_chunks; $p++) {
              $pager[] = preg_replace('/\.[a-z]+$/', $p . '$0', $source_page['original_path']);
            }
            foreach (array_chunk($pages, $per_page) as $n => $chunk) {
              $virtual_page = $source_page;
              $n += 1;
              $virtual_page['pagination'] = [
                'pages' => $chunk,
                'n' => $n,
                'total' => $total_chunks,
                'pager' => $pager,
              ];
              if ($n > 1) {
                $virtual_page['_build_file'] = preg_replace('/\.[a-z]+$/', $n . '$0', $source_page['_build_file']);
                $virtual_page['pagination']['prev'] = $pager[$n - 2];
                // Hide all but the first page
                $virtual_page['meta']['title'] .= sprintf(' - page %d of %d', $n, $total_chunks);
                $virtual_page['meta']['hidden'] = TRUE;
              }
              if ($n < $total_chunks) {
                $virtual_page['pagination']['next'] = $pager[$n];
              }
              $pages_to_build[] = $virtual_page;
            }
          }
          elseif (!isset($this->build['config']['metadata_index'][$source_page['meta']['pagination']['data']])) {
            throw new Exception('paginaton.data variable not in metadata_index option in file ' . $file);
          }
          else {
            $indices = $this->build['config']['metadata_index'][$source_page['meta']['pagination']['data']];
            ksort($indices);
            $pager = [];
            foreach (array_keys($indices) as $term) {
              $pager[$term] = preg_replace('/\.[a-z]+$/', '-' . $term . '$0', $source_page['original_path']);
            }
            foreach ($indices as $term => $pages) {
              $virtual_page = $source_page;
              $real_pages = [];
              foreach (array_keys($pages) as $id) {
                $real_pages[$id] = $this->build['pages'][$id];
              }
              $virtual_page['pagination'] = [
                'pages' => $real_pages,
                'term' => $term,
                'pager' => $pager,
              ];
              $virtual_page['_build_file'] = preg_replace('/\.[a-z]+$/', '-' . $term . '$0', $source_page['_build_file']);
              $pages_to_build[] = $virtual_page;
            }
          }
        }
        else {
          $pages_to_build[] = $source_page;
        }

        // Loop through pages to build
        foreach ($pages_to_build as $page) {
          $build_file = $page['_build_file'];
          unset($page['_build_file']);
          $page_params = [
            'current_page' => $page,
            'config' => &$this->config,
            'pages' => &$this->build['pages'],
          ];
          // Render markdown content, using Twig filter first
          $page_params['current_page']['content'] =
            $this->twig->render(
              '_convert_twig_in_content.twig',
              $page_params
              + [
                'content' => static::splitMarkdownFile(file_get_contents($file))['md'],
                'twig' => $this->twig,
              ]
            )
          ;
          // Render page template and output
          $rendered_page = $this->twig->render(
            $page['meta']['template'],
            $page_params
          );
          if (!empty($build_file)) {
            file_put_contents($build_file, $rendered_page);
          }
          if (!empty($page['meta']['output_variable'])) {
            $this->config['output_variable'][$page['meta']['output_variable']] = $rendered_page;
          }
        }
        $action = 'rendered';
      }
      else {
        $action = 'skipped';
      }
    }
    else {
      // Everything else just gets copied
      $action = 'copied';
      if (file_exists($build_file)) {
        unlink($build_file);
      }
      copy($file, $build_file);
    }
    return empty($action) ? '' : sprintf("%s\t%s\t%f", $action, $this->_stripContentDir($file), microtime(TRUE) - $start);
  }

  public function _stripContentDir(string $file) {
    return str_replace($this->config['content_dir'], '', $file);
  }

  public function _contentToBuildFile(string $file) {
    return str_replace($this->config['content_dir'], $this->build['config']['dir'], $file);
  }

  public static function fileIsMarkdown(string $file) {
    return is_file($file) && substr(strtolower($file), -3) == '.md';
  }

  public static function fileIsYaml(string $file) {
    return is_file($file) && preg_match('/\.ya?ml$/', $file);
  }

  public function getPageIdFromContentFile(string $file) {
    return trim($this->_stripContentDir(preg_replace('/(\.\w+?)$/', '', $file)), '/');
  }

  /**
   * Log messages.
   */
  public static function log($value) {
    if (is_string($value)) {
      echo "$value\n";
    }
    else {
      var_dump($value);
    }
  }

  /**
   * Deletes an entire directory.
   */
  public function deleteDir(string $dir) {
    foreach ($this->findAllFiles($dir, [ 'include_all' => TRUE ]) as $file) {
      if (is_dir($file)) {
        rmdir($file);
      }
      else {
        unlink($file);
      }
    }
    rmdir($dir);
  }

  /**
   * Watch for changes in the content directory.
   */
  public function watch() {
    $this->log("Watching for changes in {$this->config['content_dir']}... (CTRL-C to stop)\n");
    $checksum_cache_id = 'watch/' . md5($this->config['content_dir']);
    $checksum = $this->cacheGet($checksum_cache_id);
    while (($new_checksum = $this->contentChecksum()) == $checksum) {
      sleep($this->config['watch_interval']);
    }
    $this->log(sprintf('Content change detected at %s - old checksum %s, new checksum %s', date('c'), $checksum, $new_checksum));
    $this->cacheSet($checksum_cache_id, $new_checksum);
  }

  /**
   * Generate a checksum of all files in the content directory.
   *
   * Filename is included, to take renaming/moving into account.
   */
  public function contentChecksum() {
    $files = $this->findAllFiles($this->config['content_dir']);
    $checksum = '';
    foreach($files as $file) {
      $checksum .= $file . md5_file($file);
    }
    $checksum = md5($checksum);
    return $checksum;
  }

  /**
   * Create thumbs for a gallery and apply a template.
   */
  public static function makeGallery($context, string $dir, array $options = array()) {
    $dir = rtrim($dir, '/');
    $options += [
      'width' => 200,
      'template' => 'gyokuto_gallery.twig',
      'thumbnails' => TRUE,
    ];
    $file_dir = realpath($context['config']['content_dir'] . $dir);
    if (!is_dir($file_dir)) {
      throw new \Twig\Error\RuntimeError('Could not find gallery image directory ' . $dir);
    }
    $gallery = [ 'images' => [] ];
    $yaml_data = [];
    foreach (scandir($file_dir) as $file) {
      if (preg_match('/\.jpe?g$/', strtolower($file))) {
        $cache_id = sprintf('gyokuto_gallery/image_%s_%s', md5_file($file_dir . '/' . $file), md5($file_dir . serialize($options)));
        if (!($image = static::cacheGetStatic($cache_id, $context['config']['cache_dir']))) {
          $img = imagecreatefromjpeg($file_dir . '/' . $file);
          $image = [
            'src' => $dir . '/' . $file,
            'alt' => htmlentities(str_replace('_', ' ', pathinfo($file, PATHINFO_FILENAME))),
          ];
          if ($options['thumbnails']) {
            $thumb = imagescale($img, $options['width']);
            imagejpeg($thumb, ($thumbfile = tempnam(sys_get_temp_dir(), 'gthumb_')), 75);
            $image['thumbnail'] = [
              'src' => htmlentities('data:image/jpeg;base64,' . base64_encode(file_get_contents($thumbfile))),
              'width' => $options['width'],
            ];
            unlink($thumbfile);
          }
          static::cacheSetStatic($cache_id, $context['config']['cache_dir'], $image);
        }
        $gallery['images'][basename($file)] = $image;
      }
      elseif (static::fileIsYaml($file_dir . '/' . $file)) {
        // Parse YAML data for extra metadata
        $yaml_data = array_merge_recursive($yaml_data, \Symfony\Component\Yaml\Yaml::parseFile($file_dir . '/' . $file));
      }
    }

    // Apply any metadata changes from YAML files in the image folder
    foreach ($yaml_data as $image_name => $metadata) {
      if (isset($gallery['images'][$image_name])) {
        $gallery['images'][$image_name] = array_merge_recursive($gallery['images'][$image_name], $metadata);
      }
    }

    $gallery['options'] = $options;
    return $context['twig']->render($options['template'], [ 'gallery' => $gallery ]);
  }

  public static function cacheGetStatic(string $cache_id, string $cache_dir) {
    $cache_file = static::getCacheFile($cache_id, $cache_dir);
    if (file_exists($cache_file)) {
      return unserialize(file_get_contents($cache_file));
    }
    else {
      return FALSE;
    }
  }

  public static function cacheSetStatic(string $cache_id, string $cache_dir, &$data) {
    $cache_file = static::getCacheFile($cache_id, $cache_dir);
    if (!is_dir(dirname($cache_file))) {
      mkdir(dirname($cache_file), 0755, TRUE);
    }
    file_put_contents($cache_file, serialize($data));
  }

  public function cacheGet(string $cache_id) {
    return static::cacheGetStatic($cache_id, $this->config['cache_dir']);
  }
  public function cacheSet(string $cache_id, &$data) {
    return static::cacheSetStatic($cache_id, $this->config['cache_dir'], $data);
  }

  public static function getCacheFile(string $cache_id, string $cache_dir) {
    return $cache_dir . '/' . $cache_id;
  }

  /**
   * Queue functions
   */
  public function _getQueueFileFromId(string $queue_id) {
    return $this->config['cache_dir'] . '/' . trim($queue_id, '/');
  }
  public function _openQueueFile(string $queue_id, $mode) {
    $file = $this->_getQueueFileFromId($queue_id);
    if (!is_dir(dirname($file))) {
      mkdir(dirname($file), 0755, TRUE);
    }
    return fopen($file, $mode);
  }
  public function getQueueItems(string $queue_id, int $n = 1) {
    $fh = $this->_openQueueFile($queue_id, 'r');
    $data = [];
    while (($line = fgets($fh)) !== FALSE && $n > 0) {
      $line = trim($line);
      if (!empty($line)) {
        $data[] = unserialize($line);
        $n--;
      }
    }
    fclose($fh);
    return $data;
  }
  public function removeQueueItems(string $queue_id, int $n = 1) {
    $fh = $this->_openQueueFile($queue_id, 'r');
    $tmp_q = uniqid('tmp_');
    $tmp = $this->_openQueueFile($tmp_q, 'w');
    while (($line = fgets($fh)) !== FALSE) {
      if ($n > 0) {
        $n--;
      }
      else {
        fputs($tmp, $line);
      }
    }
    fclose($fh);
    fclose($tmp);
    rename($this->_getQueueFileFromId($tmp_q), $this->_getQueueFileFromId($queue_id));
  }
  public function addQueueItems(string $queue_id, array $items) {
    $fh = $this->_openQueueFile($queue_id, 'a');
    foreach ($items as $item) {
      fwrite($fh, serialize($item) . "\n");
    }
    fclose($fh);
  }

  public static function splitMarkdownFile(string $raw) {
    if (preg_match('/^---\n(.+?)\n---\n\s*(.*)\s*$/s', $raw, $matches)) {
      return [
        'meta' => \Symfony\Component\Yaml\Yaml::parse($matches[1]),
        'md' => $matches[2],
      ];
    }
    else {
      throw new \Exception("Failed to parse:\n" . $raw);
      return [ 'meta' => [], 'md' => $raw ];
    }
  }
}
