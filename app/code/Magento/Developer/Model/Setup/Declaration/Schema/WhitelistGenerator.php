<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Magento\Developer\Model\Setup\Declaration\Schema;

use Magento\Framework\App\DeploymentConfig;
use Magento\Framework\Component\ComponentRegistrar;
use Magento\Framework\Config\ConfigOptionsListConstants;
use Magento\Framework\Config\FileResolverByModule;
use Magento\Framework\Exception\ConfigurationMismatchException;
use Magento\Framework\Module\Dir;
use Magento\Framework\Setup\Declaration\Schema\Declaration\ReaderComposite;
use Magento\Framework\Setup\Declaration\Schema\Declaration\TableElement\ElementNameResolver;
use Magento\Framework\Setup\Declaration\Schema\Diff\Diff;
use Magento\Framework\Setup\Declaration\Schema\Dto\Schema;
use Magento\Framework\Setup\Declaration\Schema\SchemaConfig;
use Magento\Framework\Setup\JsonPersistor;

/**
 * Generate whitelist declaration declarative schema.
 */
class WhitelistGenerator
{
    /**
     * @var SchemaConfig
     */
    private $schemaConfig;

    /**
     * @var ComponentRegistrar
     */
    private $componentRegistrar;

    /**
     * @var JsonPersistor
     */
    private $jsonPersistor;

    /**
     * @var ReaderComposite
     */
    private $readerComposite;

    /**
     * @var array
     */
    private $primaryDbSchema;

    /**
     * @var ElementNameResolver
     */
    private $elementNameResolver;

    /**
     * @var DeploymentConfig
     */
    private $deploymentConfig;

    /**
     * @param ComponentRegistrar $componentRegistrar
     * @param JsonPersistor $jsonPersistor
     * @param SchemaConfig $schemaConfig
     * @param ReaderComposite $readerComposite
     * @param ElementNameResolver $elementNameResolver
     * @param DeploymentConfig $deploymentConfig
     */
    public function __construct(
        ComponentRegistrar $componentRegistrar,
        JsonPersistor $jsonPersistor,
        SchemaConfig $schemaConfig,
        ReaderComposite $readerComposite,
        ElementNameResolver $elementNameResolver,
        DeploymentConfig $deploymentConfig
    ) {
        $this->componentRegistrar = $componentRegistrar;
        $this->jsonPersistor = $jsonPersistor;
        $this->schemaConfig = $schemaConfig;
        $this->readerComposite = $readerComposite;
        $this->elementNameResolver = $elementNameResolver;
        $this->deploymentConfig = $deploymentConfig;
    }

    /**
     * Generate whitelist declaration.
     *
     * @param string $moduleName
     * @throws ConfigurationMismatchException
     */
    public function generate(string $moduleName)
    {
        $this->checkMagentoInstallation();
        $schema = $this->schemaConfig->getDeclarationConfig();
        if ($moduleName === FileResolverByModule::ALL_MODULES) {
            foreach (array_keys($this->componentRegistrar->getPaths('module')) as $moduleName) {
                $this->persistModule($schema, $moduleName);
            }
        } else {
            $this->persistModule($schema, $moduleName);
        }
    }

    /**
     * Check the configuration of the installed instance.
     *
     * @throws ConfigurationMismatchException
     */
    private function checkMagentoInstallation()
    {
        $tablePrefixLength = $this->deploymentConfig->get(ConfigOptionsListConstants::CONFIG_PATH_DB_PREFIX);
        if ($tablePrefixLength) {
            throw new ConfigurationMismatchException(
                __('Magento was installed with a table prefix. Please re-install without prefix.')
            );
        }
    }

    /**
     * Update whitelist tables for all modules that are enabled on the moment.
     *
     * @param Schema $schema
     * @param string $moduleName
     * @return void
     */
    private function persistModule(Schema $schema, string $moduleName)
    {
        $content = [];
        $modulePath = $this->componentRegistrar->getPath('module', $moduleName);
        $whiteListFileName = $modulePath
            . DIRECTORY_SEPARATOR
            . Dir::MODULE_ETC_DIR
            . DIRECTORY_SEPARATOR
            . Diff::GENERATED_WHITELIST_FILE_NAME;

        //We need to load whitelist file and update it with new revision of code.
        // phpcs:disable Magento2.Functions.DiscouragedFunction
        if (file_exists($whiteListFileName)) {
            $content = json_decode(file_get_contents($whiteListFileName), true);
        }

        $data = $this->filterPrimaryTables($this->readerComposite->read($moduleName));
        if (!empty($data['table'])) {
            foreach ($data['table'] as $tableName => $tabledata) {
                //Do merge between what we have before, and what we have now and filter to only certain attributes.
                $content = array_replace_recursive(
                    $content,
                    [$tableName => $this->getElementsWithFixedName($tabledata)],
                    [$tableName => $this->getElementsWithAutogeneratedName(
                        $schema,
                        $tableName,
                        $tabledata
                    )]
                );
            }
            if (!empty($content)) {
                $this->jsonPersistor->persist($content, $whiteListFileName);
            }
        }
    }

    /**
     * Provide immutable names of the table elements.
     *
     * @param array $tableData
     * @return array
     */
    private function getElementsWithFixedName(array $tableData): array
    {
        $declaredStructure = [];
        if (!empty($tableData['column'])) {
            $declaredColumns = array_keys($tableData['column']);
            $declaredStructure['column'] = array_fill_keys($declaredColumns, true);
        }
        return $declaredStructure;
    }

    /**
     * Provide autogenerated names of the table elements.
     *
     * @param Schema $schema
     * @param string $tableName
     * @param array $tableData
     * @return array
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)
     */
    private function getElementsWithAutogeneratedName(Schema $schema, string $tableName, array $tableData) : array
    {
        $declaredStructure = [];
        $table = $schema->getTableByName($tableName);

        $elementType = 'index';
        if (!empty($tableData[$elementType])) {
            foreach ($tableData[$elementType] as $tableElementData) {
                if (isset($tableElementData['column'])) {
                    $indexName = $this->elementNameResolver->getFullIndexName(
                        $table,
                        $tableElementData['column'],
                        $tableElementData['indexType'] ?? null
                    );
                    $declaredStructure[$elementType][$indexName] = true;
                }
            }
        }

        $elementType = 'constraint';
        if (!empty($tableData[$elementType])) {
            foreach ($tableData[$elementType] as $tableElementData) {
                $constraintName = null;
                if (isset($tableElementData['type'], $tableElementData['column'])) {
                    if ($tableElementData['type'] === 'foreign') {
                        if (isset(
                                $tableElementData['column'],
                                $tableElementData['referenceTable'],
                                $tableElementData['referenceColumn']
                            )) {
                            $referenceTable = $schema->getTableByName($tableElementData['referenceTable']);
                            $column = $table->getColumnByName($tableElementData['column']);
                            $referenceColumn = $referenceTable->getColumnByName($tableElementData['referenceColumn']);
                            $constraintName = ($column !== false && $referenceColumn !== false) ?
                                $this->elementNameResolver->getFullFKName(
                                    $table,
                                    $column,
                                    $referenceTable,
                                    $referenceColumn
                                ) : null;
                        }
                    } else {
                        if (isset($tableElementData['column'])) {
                            $constraintName = $this->elementNameResolver->getFullIndexName(
                                $table,
                                $tableElementData['column'],
                                $tableElementData['type']
                            );
                        }
                    }
                }
                if ($constraintName) {
                    $declaredStructure[$elementType][$constraintName] = true;
                }
            }
        }

        return $declaredStructure;
    }

    /**
     * Load db_schema content from the primary scope app/etc/db_schema.xml.
     *
     * @return array
     */
    private function getPrimaryDbSchema(): array
    {
        if (!$this->primaryDbSchema) {
            $this->primaryDbSchema = $this->readerComposite->read('primary');
        }
        return $this->primaryDbSchema;
    }

    /**
     * Filter tables from module database schema as they should not contain the primary system tables.
     *
     * @param array $moduleDbSchema
     * @return array
     */
    private function filterPrimaryTables(array $moduleDbSchema): array
    {
        $primaryDbSchema = $this->getPrimaryDbSchema();
        if (isset($moduleDbSchema['table']) && isset($primaryDbSchema['table'])) {
            foreach (array_keys($primaryDbSchema['table']) as $tableNameKey) {
                unset($moduleDbSchema['table'][$tableNameKey]);
            }
        }
        return $moduleDbSchema;
    }
}
