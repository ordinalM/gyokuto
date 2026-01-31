<?php

namespace Gyokuto;

use Exception;
use RuntimeException;
use Symfony\Component\Yaml\Yaml;
use Throwable;
use Twig\Environment;
use Twig\Extension\StringLoaderExtension;
use Twig\Extra\Markdown\DefaultMarkdown;
use Twig\Extra\Markdown\MarkdownExtension;
use Twig\Extra\Markdown\MarkdownRuntime;
use Twig\Loader\ChainLoader;
use Twig\Loader\FilesystemLoader;
use Twig\RuntimeLoader\RuntimeLoaderInterface;
use Twig\TwigFilter;

class Build
{
    private const OPTION_CONTENT_DIR = 'content_dir';
    private const OPTION_OUTPUT_DIR = 'output_dir';
    private const OPTION_TEMP_DIR = 'temp_dir';
    private const OPTION_TEMPLATE_DIR = 'template_dir';
    private const TEMPLATE_DIR_USER_DEFAULT = './templates';
    private const TEMPLATE_DIR_BUILTIN = '../templates'; // relative to this file
    private const OPTIONS_FILE_DEFAULT = './gyokuto.yml';
    private string $content_dir = './content';
    private string $output_dir = './www';
    private string $temp_dir = './.gyokuto-tmp';
    private array $config;
    private Environment $twig;
    private array $build_metadata = [];

    public function __construct(string $config_file = null)
    {
        $config_file = $config_file ?? self::OPTIONS_FILE_DEFAULT;
        if (is_file($config_file)) {
            $parsed_config = Yaml::parse(file_get_contents($config_file));
            if (!is_array($parsed_config)) {
                Utils::getLogger()->error('Failed to pass config file to array from ' . $config_file, ['config_parsed' => $parsed_config]);
                throw new RuntimeException('Could not parse config properly');
            }
            $this->config = $parsed_config;
            Utils::getLogger()
                ->debug('Read options from file ' . realpath($config_file), $this->config);
        } else {
            $this->config = [];
        }
        $this->content_dir = $this->config[self::OPTION_CONTENT_DIR] ?? $this->content_dir;
        $this->output_dir = $this->config[self::OPTION_OUTPUT_DIR] ?? $this->output_dir;
        $this->temp_dir = $this->config[self::OPTION_TEMP_DIR] ?? $this->temp_dir;
        $this->twig = $this->createTwigEnvironment();
    }

    /**
     * Sets up the default Twig environment for the build.
     *
     * This is done when initialising the object, so that it can be modified by a user before actually running a build.
     *
     * @return Environment
     */
    private function createTwigEnvironment(): Environment
    {
        Utils::getLogger()
            ->info('Creating Twig environment');

        // Template loading
        $loaders = [];

        // This is the user template folder.
        // We don't actually need to have one.
        $template_dir_user = $this->config[self::OPTION_TEMPLATE_DIR] ?? self::TEMPLATE_DIR_USER_DEFAULT;
        if (is_dir($template_dir_user)) {
            // Put the user loader first
            $loaders[] = new FilesystemLoader($template_dir_user);
            Utils::getLogger()
                ->debug('Loading user templates from ' . realpath($template_dir_user), Utils::getDirectoryContents($template_dir_user));
        }

        // This is the application template folder.
        $template_dir_app = __DIR__ . '/' . self::TEMPLATE_DIR_BUILTIN;
        $loaders[] = new FilesystemLoader($template_dir_app);
        Utils::getLogger()
            ->debug('Loading application templates from ' . realpath($template_dir_app), Utils::getDirectoryContents($template_dir_app));

        // Create the Twig environment
        $twig = new Environment(new ChainLoader($loaders), [
            'autoescape' => false,
            'strict_variables' => true,
            'auto_reload' => true,
        ]);

        // Add a loader for the markdown runtime
        $twig->addRuntimeLoader(new class implements RuntimeLoaderInterface {
            public function load($class): ?MarkdownRuntime
            {
                if (MarkdownRuntime::class === $class) {
                    return new MarkdownRuntime(new DefaultMarkdown());
                }

                return null;
            }
        });

        // We use the string loader extension to parse Twig in markdown body.
        $twig->addExtension(new StringLoaderExtension());
        $twig->addExtension(new MarkdownExtension());

        // Add a preg_replace filter
        $twig->addFilter(new TwigFilter('preg_replace', function ($subject, $pattern, $replacement) {
            return preg_replace($pattern, $replacement, $subject);
        }));

        return $twig;
    }

    /**
     * @throws Throwable
     */
    public function run(): bool
    {
        Utils::getLogger()
            ->info('Starting build');
        try {
            $content_files = ContentFileList::createFromDirectory($this->content_dir);

            $this->build_metadata = $content_files->compileContentMetadata($this);
            $content_files->process($this);

            $this->moveTempToOutput();

            $status = true;
        } catch (Throwable $exception) {
            Utils::getLogger()
                ->error('Error in build', [$exception->getFile(), $exception->getLine(), $exception->getMessage()]);

            throw $exception;
        }
        $this->cleanup();
        Utils::getLogger()
            ->info('Finished build');

        return $status;
    }

    private function moveTempToOutput(): void
    {
        Utils::getLogger()
            ->info('Moving temporary build directory to ' . realpath($this->output_dir));
        Utils::deleteDir($this->output_dir);

        rename($this->getTempDir(), $this->output_dir);
    }

    /**
     * @throws RuntimeException
     */
    public function getTempDir(): string
    {
        if (!is_dir($this->temp_dir) && !mkdir($concurrentDirectory = $this->temp_dir, 0755, true) && !is_dir($concurrentDirectory)) {
            throw new RuntimeException(sprintf('Directory "%s" was not created', $concurrentDirectory));
        }

        return realpath($this->temp_dir);
    }

    /**
     * Cleans up a run, removing all temp files
     */
    private function cleanup(): void
    {
        if (is_dir($this->getTempDir())) {
            Utils::deleteDir($this->getTempDir());
            Utils::getLogger()
                ->debug('Deleted temp dir');
        }
    }

    public function getContentDir(): string
    {
        return realpath($this->content_dir);
    }

    /**
     * @return Environment
     */
    public function getTwig(): Environment
    {
        return $this->twig;
    }

    /**
     * @param Environment $twig
     *
     * @return Build
     */
    public function setTwig(Environment $twig): Build
    {
        $this->twig = $twig;

        return $this;
    }

    public function getBuildMetadata(): array
    {
        return $this->build_metadata;
    }

    public function setBuildMetadata(array $build_metadata): Build
    {
        $this->build_metadata = $build_metadata;

        return $this;
    }

    public function getConfig(): array
    {
        return $this->config;
    }

}
