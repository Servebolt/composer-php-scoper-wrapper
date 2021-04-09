<?php

/*
 * This file is part of the "composer-php-scoper-wrapper" plugin.
 *
 * © Robert Sæther <robert@servebolt.com>
 */

namespace Servebolt\Composer;

use Composer\Composer;
use Composer\EventDispatcher\EventSubscriberInterface;
use Composer\IO\IOInterface;
use Composer\Package\PackageInterface;
use Composer\Plugin\PluginInterface;
use Composer\Script\ScriptEvents;

class PhpScoperWrapper implements
    PluginInterface,
    EventSubscriberInterface
{
    const NAMESPACE_PREFIX = 'ServeboltOptimizer_Vendor';
    const OUTPUT_FOLDER_PATH = 'vendor/vendor_prefixed/';
    const CONFIG_FOLDER_PATH = 'config/php-scoper/';
    const PACKAGE_MATCH_REGEX = '/^servebolt\//';

    const PLUGIN_SETTINGS_PROPERTY = 'php-scoper-wrapper';
    const OUTPUT_FOLDER_PATH_PROPERTY = 'output-path';
    const NAMESPACE_PREFIX_PROPERTY = 'namespace-prefix';
    const ADDITIONAL_PHP_SCOPER_ARGS_PROPERTY = 'additional-php-scoper-args';
    const PACKAGE_MATCH_REGEX_PROPERTY = 'package-match-regex';

    /**
     * @var Composer
     */
    private $composer;

    /**
     * @var IOInterface
     */
    private $io;

    /**
     * @var array Property containing the package autoload during package examination.
     */
    private $autoload;

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
            ScriptEvents::PRE_AUTOLOAD_DUMP => array('runPhpScoper', 2),
        );
    }

    /**
     *
     */
    public function runPhpScoper()
    {
        $this->io->write(sprintf('<info>Starting PHP scoper wrapper</info>'));
        $this->io->debug(sprintf('Looking for packages to run php-scoper in using regex "%s".', $this->getPackageMatchRegexString()));
        if ($packagesToParse = $this->getPackagesToParse()) {
            foreach ($packagesToParse as $packageToParse) {
                $this->io->debug(sprintf('Looking for php-scoper config files in package "%s"', $packageToParse->getName()));
                if ($configFiles = $this->getConfigFiles($packageToParse)) {
                    $this->io->write(sprintf('<info>Found %s php-scoper config files in package "%s"</info>', count($configFiles), $packageToParse->getName()));
                    foreach($configFiles as $configFilePath) {
                        $pharPath = __DIR__ . '/php-scoper.phar';
                        $command = sprintf('%s add-prefix --prefix=%s --output-dir=%s --config=%s %s', $pharPath, $this->getNamespacePrefix(), $this->getOutputDirPathFromConfigPath($configFilePath), $configFilePath, $this->getAdditionalArgs());
                        $this->io->write(sprintf('Running command: %s', $command));
                        exec($command, $output, $result);
                        $this->io->writeRaw($output);
                    }
                } else {
                    $this->io->debug(sprintf('Did not find any php-scoper config files in package "%s"', $packageToParse->getName()));
                }
            }
        } else {
            $this->io->write('Could not find any matching packages.');
        }
        //ci/php-scoper.phar add-prefix --prefix=ServeboltOptimizer_Vendor --output-dir=vendor/vendor_prefixed/guzzlehttp --config=config/php-scoper/guzzlehttp.inc.php --force --quiet
        $this->io->write('<info>PHP scoper wrapper is done!</info>');
    }

    /**
     * @return array
     */
    private function getPackagesToParse()
    {
        $composer = $this->composer;
        $repositoryManager = $composer->getRepositoryManager();
        $localRepository = $repositoryManager->getLocalRepository();
        $packagesToParse = array();

        $packages = $localRepository->getPackages();

        foreach ($packages as $package) {
            if ($package == $composer->getPackage()->getName()) {
                continue;
            }
            if (preg_match($this->getPackageMatchRegexString(), $package->getName())) {
                $packagesToParse[] = $package;
                break;
            }
        }
        return $packagesToParse;
    }

    /**
     * @param PackageInterface $packageToParse
     * @return array|false|string[]
     */
    private function getConfigFiles(PackageInterface $packageToParse)
    {

        $composer = $this->composer;
        $installationManager = $composer->getInstallationManager();
        $installPath = $installationManager->getInstallPath($packageToParse);

        $configFolderPath = rtrim($installPath, '/') . '/' . trim(self::CONFIG_FOLDER_PATH, '/');

        if (!file_exists($configFolderPath) || !is_dir($configFolderPath)) {
            return false;
        }

        $configFiles = glob($configFolderPath . '/*.php');
        if (empty($configFiles)) {
            return false;
        }

        return $configFiles;
    }

    /**
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
     * @return string
     */
    private function getOutputDirPath()
    {
        $extra = $this->getPluginSettings();
        $property = self::OUTPUT_FOLDER_PATH_PROPERTY;

        if (isset($extra[$property]) && is_array($extra[$property])) {
            return $extra[$property];
        }

        return self::OUTPUT_FOLDER_PATH;
    }

    /**
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
     * @return string
     */
    private function getAdditionalArgs()
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
