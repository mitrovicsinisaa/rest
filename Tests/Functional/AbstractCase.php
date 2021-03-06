<?php

namespace Cundd\Rest\Tests\Functional;

use Cundd\Rest\Authentication\UserProvider\FeUserProvider;
use Cundd\Rest\Authentication\UserProviderInterface;
use Cundd\Rest\Configuration\ConfigurationProviderInterface;
use Cundd\Rest\Configuration\TypoScriptConfigurationProvider;
use Cundd\Rest\Dispatcher;
use Cundd\Rest\Dispatcher\DispatcherInterface;
use Cundd\Rest\Handler\AuthHandler;
use Cundd\Rest\Handler\CrudHandler;
use Cundd\Rest\Http\RestRequestInterface;
use Cundd\Rest\Log\LoggerInterface as CunddLoggerInterface;
use Cundd\Rest\Tests\ClassBuilderTrait;
use Cundd\Rest\Tests\Functional\Database\DatabaseConnectionInterface;
use Cundd\Rest\Tests\Functional\Database\Factory;
use Cundd\Rest\Tests\Functional\Integration\StreamLogger;
use Cundd\Rest\Tests\RequestBuilderTrait;
use Cundd\Rest\Tests\ResponseBuilderTrait;
use Doctrine\DBAL\DBALException;
use Psr\Log\LoggerInterface as PsrLoggerInterface;
use TYPO3\CMS\Core\Tests\FunctionalTestCase;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Extbase\Object\Container\Container;
use TYPO3\CMS\Extbase\Object\ObjectManager;
use Webmozart\Assert\Assert;

class AbstractCase extends FunctionalTestCase
{
    use ResponseBuilderTrait;
    use RequestBuilderTrait;
    use ClassBuilderTrait;

    /**
     * @var ObjectManager
     */
    protected $objectManager;

    public function setUp()
    {
        try {
            parent::setUp();
        } catch (\TYPO3\CMS\Core\Exception $exception) {
        } catch (DBALException $e) {
        }

        $_SERVER['HTTP_HOST'] = 'rest.cundd.net';

        $GLOBALS['TYPO3_DB'] = $this->getDatabaseConnection();

        $this->registerLoggerImplementation();
        $this->objectManager = $this->buildConfiguredObjectManager();
        $this->configureConfigurationProvider();
    }

    /**
     * Build a new request with the given URI
     *
     * @param string $uri
     * @param string $format
     * @param string $method
     * @return RestRequestInterface
     */
    public function buildRequestWithUri($uri, $format = null, $method = null)
    {
        return RequestBuilderTrait::buildTestRequest(
            $uri,
            $method,   // $method
            [],     // $params
            [],     // $headers
            null,   // $rawBody
            null,   // $parsedBody
            $format
        );
    }

    /**
     * Imports a data set represented as XML into the test database,
     *
     * @param string $path Absolute path to the XML file containing the data set to load
     * @return void
     * @throws \Exception
     */
    protected function importDataSet($path)
    {
        if (method_exists('\TYPO3\CMS\Core\Tests\FunctionalTestCase', 'importDataSet')) {
            parent::importDataSet($path);

            return;
        }

        if (!is_file($path)) {
            throw new \Exception(
                'Fixture file ' . $path . ' not found',
                1376746261
            );
        }

        $database = $this->getDatabaseConnection();

        $xml = simplexml_load_file($path);
        $foreignKeys = [];

        /** @var $table \SimpleXMLElement */
        foreach ($xml->children() as $table) {
            $insertArray = [];

            /** @var $column \SimpleXMLElement */
            foreach ($table->children() as $column) {
                $columnName = $column->getName();
                $columnValue = null;

                if (isset($column['ref'])) {
                    list($tableName, $elementId) = explode('#', $column['ref']);
                    $columnValue = $foreignKeys[$tableName][$elementId];
                } elseif (isset($column['is-NULL']) && ($column['is-NULL'] === 'yes')) {
                    $columnValue = null;
                } else {
                    $columnValue = (string)$table->$columnName;
                }

                $insertArray[$columnName] = $columnValue;
            }

            $tableName = $table->getName();
            $result = $database->exec_INSERTquery($tableName, $insertArray);
            if ($result === false) {
                $this->markTestSkipped(
                    sprintf(
                        'Error when processing fixture file: %s. Can not insert data to table %s: %s',
                        $path,
                        $tableName,
                        $database->sql_error()
                    )
                );
            }
            if (isset($table['id'])) {
                $elementId = (string)$table['id'];
                $foreignKeys[$tableName][$elementId] = $database->sql_insert_id();
            }
        }
    }

    /**
     * @return DatabaseConnectionInterface
     */
    protected function getDatabaseConnection()
    {
        return Factory::getConnection();
    }

    /**
     * @param mixed  $propertyValue
     * @param string $propertyKey
     * @param object $object
     * @return object
     */
    public function injectPropertyIntoObject($propertyValue, $propertyKey, $object)
    {
        $reflectionMethod = new \ReflectionProperty(get_class($object), $propertyKey);
        $reflectionMethod->setAccessible(true);
        $reflectionMethod->setValue($object, $propertyValue);

        return $object;
    }

    /**
     * @return ObjectManager
     */
    private function buildConfiguredObjectManager()
    {
        /** @var Container $objectContainer */
        $objectContainer = GeneralUtility::makeInstance(Container::class);

        $objectContainer->registerImplementation(
            ConfigurationProviderInterface::class,
            TypoScriptConfigurationProvider::class
        );
        $objectContainer->registerImplementation(
            UserProviderInterface::class,
            FeUserProvider::class
        );
        $objectContainer->registerImplementation(
            DispatcherInterface::class,
            Dispatcher::class
        );

        return new ObjectManager();
    }

    protected function configureConfigurationProvider()
    {
        /** @var TypoScriptConfigurationProvider $configurationProvider */
        $configurationProvider = $this->objectManager->get(ConfigurationProviderInterface::class);
        $configurationProvider->setSettings(
            [
                "paths"            => [
//                    "all" => [
//                        "path"         => "all",
//                        "read"         => "deny",
//                        "write"        => "deny",
//                        "handlerClass" => CrudHandler::class,
//                    ],
//
//                    "document" => [
//                        "path"  => "Document",
//                        "read"  => "deny",
//                        "write" => "deny",
//                    ],
//
//                    "auth" => [
//                        "path"         => "auth",
//                        "read"         => "allow",
//                        "write"        => "allow",
//                        "handlerClass" => AuthHandler::class,
//                    ],
                ],

                # Define words that should not be converted to singular
                "singularToPlural" => [
                    "news"        => "news",
                    "equipment"   => "equipment",
                    "information" => "information",
                    "rice"        => "rice",
                    "money"       => "money",
                    "species"     => "species",
                    "series"      => "series",
                    "fish"        => "fish",
                    "sheep"       => "sheep",
                    "press"       => "press",
                    "sms"         => "sms",
                ],
            ]
        );

        $this->configurePath(
            "all",
            [
                "path"         => "all",
                "read"         => "deny",
                "write"        => "deny",
                "handlerClass" => CrudHandler::class,
            ]
        );
        $this->configurePath(
            "document",
            [
                "path"  => "Document",
                "read"  => "deny",
                "write" => "deny",
            ]
        );
        $this->configurePath(
            "auth",
            [
                "path"         => "auth",
                "read"         => "allow",
                "write"        => "allow",
                "handlerClass" => AuthHandler::class,
            ]
        );
    }

    protected function configurePath($path, array $pathConfiguration)
    {
        Assert::string($path);
        /** @var TypoScriptConfigurationProvider $configurationProvider */
        $configurationProvider = $this->objectManager->get(ConfigurationProviderInterface::class);
        $configuration = $configurationProvider->getSettings();
        $configuration['paths'][$path] = $pathConfiguration;
        $configurationProvider->setSettings($configuration);
    }

    protected function registerLoggerImplementation()
    {
        /** @var Container $container */
        $container = GeneralUtility::makeInstance(Container::class);
        $container->registerImplementation(PsrLoggerInterface::class, StreamLogger::class);
        $container->registerImplementation(CunddLoggerInterface::class, StreamLogger::class);
    }
}
