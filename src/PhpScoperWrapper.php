<?php

/*
 * This file is part of the "composer-php-scoper-wrapper" plugin.
 *
 * © Robert Sæther <robert@servebolt.com>
 */

namespace Servebolt\Composer;

use Composer\Util\Filesystem;
use Composer\Composer;
use Composer\EventDispatcher\EventSubscriberInterface;
use Composer\IO\IOInterface;
use Composer\Package\PackageInterface;
use Composer\Plugin\PluginInterface;
use Composer\Script\ScriptEvents;
use Composer\Util\ProcessExecutor;

class PhpScoperWrapper implements
    PluginInterface,
    EventSubscriberInterface
{
    const NAMESPACE_PREFIX = 'ServeboltOptimizer_Vendor';
    const OUTPUT_FOLDER_NAME = 'vendor_prefixed';
    const CONFIG_FOLDER_PATH = 'config/php-scoper/';
    const PACKAGE_MATCH_REGEX = '/^servebolt\//';
    const REPOSITORY_MATCH_REGEX = '/servebolt/';

    const PLUGIN_SETTINGS_PROPERTY = 'php-scoper-wrapper';
    const OUTPUT_FOLDER_PATH_PROPERTY = 'output-path';
    const NAMESPACE_PREFIX_PROPERTY = 'namespace-prefix';
    const ADDITIONAL_PHP_SCOPER_ARGS_PROPERTY = 'additional-php-scoper-args';
    const PACKAGE_MATCH_REGEX_PROPERTY = 'package-match-regex';
    const REPOSITORY_MATCH_REGEX_PROPERTY = 'repository-match-regex';

    /**
     * @var Composer
     */
    private $composer;

    /**
     * @var IOInterface
     */
    private $io;

    /**
     * Apply plugin modifications to Composer.
     *
     * @param Composer $composer The Composer instance.
     * @param IOInterface $io The Input/Output instance.
     * @return void
     */
    public function activate(Composer $composer, IOInterface $io)
    {
        $this->composer = $composer;
        $this->io = $io;
    }

    /**
     * Remove any hooks from Composer.
     *
     * @codeCoverageIgnore
     *
     * @param Composer $composer The Composer instance.
     * @param IOInterface $io The Input/Output instance.
     * @return void
     */
    public function deactivate(Composer $composer, IOInterface $io)
    {
        // no need to deactivate anything
    }

    /**
     * Prepare the plugin to be uninstalled.
     *
     * @codeCoverageIgnore
     *
     * @param Composer $composer The Composer instance.
     * @param IOInterface $io The Input/Output instance.
     * @return void
     */
    public function uninstall(Composer $composer, IOInterface $io)
    {
        // no need to uninstall anything
    }

    /**
     * Gets a list of event names this subscriber wants to listen to.
     *
     * @return array The event names to listen to.
     */
    public static function getSubscribedEvents()
    {
        return array(
            ScriptEvents::PRE_INSTALL_CMD => array(
                array('ensurePrefixedVendorFolderExists', 2),
            ),
            ScriptEvents::PRE_UPDATE_CMD => array(
                array('ensurePrefixedVendorFolderExists', 2),
            ),
            ScriptEvents::PRE_AUTOLOAD_DUMP => array(
                array('ensurePrefixedVendorFolderExists', 2),
                array('runPhpScoper', 2),
            ),
            ScriptEvents::POST_AUTOLOAD_DUMP => array(
                array('removePrefixedVendorFolderIfEmpty', 0)
            ),
        );
    }

    /**
     * Remove prefixed vendor folder if empty.
     */
    public function removePrefixedVendorFolderIfEmpty()
    {
        $filesystem = new Filesystem;
        $outputDir = $this->getOutputDirPath();
        if ($filesystem->isDirEmpty($outputDir)) {
            $this->io->write('Removed prefixed vendor folder since it was empty.');
            $filesystem->removeDirectory($outputDir);
        } else {
            $this->io->write('Did not remove prefixed vendor folder since it was not empty.');
        }
    }

    /**
     * Ensure existence of prefixed vendor folder.
     */
    public function ensurePrefixedVendorFolderExists()
    {
        $this->io->write('Ensure prefixed vendor exists.');
        $filesystem = new Filesystem;
        $filesystem->ensureDirectoryExists($this->getOutputDirPath());
    }

    /**
     * Get the path to php-scoper phar file.
     *
     * @return string
     */
    private function getPharPath()
    {
        return __DIR__ . '/php-scoper.phar';;
    }

    /**
     * Execute PHP CLI command through composer.
     *
     * @param $command
     */
    private function runCommand($command)
    {
        $processExecutor = new ProcessExecutor($this->io);
        $pharRunner = new PharRunner($this->composer, $this->io, $processExecutor);
        $pharRunner->execute($command);
    }

    private function getPhpScoperConfigFiles()
    {
        $configFiles = array();

        $rootPackageConfigFolderPath = rtrim(getcwd(), '/') . '/' . trim(self::CONFIG_FOLDER_PATH, '/');
        if ($rootPackageConfigFiles = $this->listPhpFilesInFolder($rootPackageConfigFolderPath)) {
            $this->io->write(sprintf('<info>Found %s php-scoper config files in root package ("%s")</info>', count($rootPackageConfigFiles), $this->composer->getPackage()->getName()));
            $configFiles = array_merge($configFiles, $rootPackageConfigFiles);
        }

        if ($packagesToParse = $this->getPackagesToParse()) {
            foreach ($packagesToParse as $packageToParse) {
                $this->io->debug(sprintf('Looking for php-scoper config files in package "%s"', $packageToParse->getName()));
                if ($configFilesInPackage = $this->getConfigFilesInPackage($packageToParse)) {
                    $this->io->write(sprintf('<info>Found %s php-scoper config files in package "%s"</info>', count($configFiles), $packageToParse->getName()));
                    $configFiles = array_merge($configFiles, $configFilesInPackage);
                } else {
                    $this->io->debug(sprintf('Did not find any php-scoper config files in package "%s"', $packageToParse->getName()));
                }
            }
        }

        return empty($configFiles) ? false : $configFiles;
    }

    /**
     * Look for php-scoper config files in packages belonging to Servebolt, then run php-scoper for each config file.
     */
    public function runPhpScoper()
    {
        $this->io->write(sprintf('<info>Starting PHP scoper wrapper</info>'));
        $this->io->debug(sprintf('Looking for packages to run php-scoper in using regex "%s".', $this->getPackageMatchRegexString()));

        if ($configFiles = $this->getPhpScoperConfigFiles()) {
            foreach ($configFiles as $configFilePath) {
                $command = sprintf('%s add-prefix --prefix=%s --output-dir=%s --config=%s %s', $this->getPharPath(), $this->getNamespacePrefix(), $this->getOutputDirPathFromConfigPath($configFilePath), $configFilePath, $this->getAdditionalPhpScoperArgs());
                if ($this->io->isDebug()) {
                    $this->io->debug(sprintf('Running command: %s', $command));
                } else {
                    $this->io->write(sprintf('Running php-scoper with config file "%s"', $configFilePath));
                }
                $this->runCommand($command);
            }
        } else {
            $this->io->write('Could not find any php-scoper config files.');
        }

        $this->io->write('<info>PHP scoper wrapper is done!</info>');
    }

    /**
     * Get packages that we should look for php-scoper config files in.
     *
     * @return array
     */
    private function getPackagesToParse()
    {
        $composer = $this->composer;
        $repositoryManager = $composer->getRepositoryManager();
        $packagesToParse = array();

        foreach ($repositoryManager->getRepositories() as $repository) {
            if (preg_match($this->getRepositoryMatchRegexString(), $repository->getRepoName())) {
                if ($packagesInRepository = $repository->getPackages()) {
                    foreach ($packagesInRepository as $package) {
                        if ($package == $composer->getPackage()->getName()) {
                            continue;
                        }
                        if (preg_match($this->getPackageMatchRegexString(), $package->getName())) {
                            $packagesToParse[] = $package;
                        }
                    }
                }
            }
        }
        return $packagesToParse;
    }

    /**
     * Get config files in packages.
     *
     * @param PackageInterface $packageToParse
     * @return array|false|string[]
     */
    private function getConfigFilesInPackage(PackageInterface $packageToParse)
    {
        $composer = $this->composer;
        $installationManager = $composer->getInstallationManager();
        $installPath = $installationManager->getInstallPath($packageToParse);

        $configFolderPath = rtrim($installPath, '/') . '/' . trim(self::CONFIG_FOLDER_PATH, '/');
        return $this->listPhpFilesInFolder($configFolderPath);
    }

    private function listPhpFilesInFolder($folderPath)
    {

        if (!file_exists($folderPath) || !is_dir($folderPath)) {
            return false;
        }

        $files = glob($folderPath . '/*.php');
        if (empty($folderPath)) {
            return false;
        }

        return $files;
    }

    /**
     * Get output dir path from config file path.
     *
     * @param $configFilePath
     * @return string
     */
    private function getOutputDirPathFromConfigPath($configFilePath)
    {
        $outputDir = $this->getOutputDirPath();
        $folderName = str_replace('.php', '', basename($configFilePath));
        $folderName = str_replace('.inc', '', basename($folderName));
        return rtrim($outputDir, '/') . '/' . $folderName;
    }

    /**
     * Get regex string for matching Servebolt-repositories.
     *
     * @return string
     */
    private function getRepositoryMatchRegexString()
    {
        $extra = $this->getPluginSettings();
        $property = self::REPOSITORY_MATCH_REGEX_PROPERTY;

        if (isset($extra[$property]) && is_array($extra[$property])) {
            return $extra[$property];
        }

        return self::REPOSITORY_MATCH_REGEX;
    }

    /**
     * Get regex string for matching Servebolt-packages.
     *
     * @return string
     */
    private function getPackageMatchRegexString()
    {
        $extra = $this->getPluginSettings();
        $property = self::PACKAGE_MATCH_REGEX_PROPERTY;

        if (isset($extra[$property]) && is_array($extra[$property])) {
            return $extra[$property];
        }

        return self::PACKAGE_MATCH_REGEX;
    }

    /**
     * Get prefixed vendor folder path.
     *
     * @return string
     */
    private function getOutputDirPath()
    {
        $extra = $this->getPluginSettings();
        $property = self::OUTPUT_FOLDER_PATH_PROPERTY;

        if (isset($extra[$property]) && is_array($extra[$property])) {
            return $extra[$property];
        }

        $config = $this->composer->getConfig();
        return $config->get('vendor-dir') . '/' . self::OUTPUT_FOLDER_NAME;
    }

    /**
     * Get namespace prefix.
     *
     * @return string
     */
    private function getNamespacePrefix()
    {
        $extra = $this->getPluginSettings();
        $property = self::NAMESPACE_PREFIX_PROPERTY;

        if (isset($extra[$property]) && is_array($extra[$property])) {
            return $extra[$property];
        }

        return self::NAMESPACE_PREFIX;
    }

    /**
     * Get additional arguments for php-scoper.
     *
     * @return string
     */
    private function getAdditionalPhpScoperArgs()
    {
        $extra = $this->getPluginSettings();
        $property = self::ADDITIONAL_PHP_SCOPER_ARGS_PROPERTY;

        if (isset($extra[$property]) && is_array($extra[$property])) {
            return $extra[$property];
        }

        return implode(' ', [
            '--force',
            '--quiet'
        ]);
    }

    /**
     * Get plugin settings.
     *
     * @return array|false
     */
    private function getPluginSettings()
    {
        $package = $this->composer->getPackage();
        $extra = $package->getExtra();
        $property = self::PLUGIN_SETTINGS_PROPERTY;

        if (isset($extra[$property]) && is_array($extra[$property])) {
            return $extra[$property];
        }

        return false;
    }
}
