<?php

namespace Tvp\TemplaVoilaPlus\Controller\Backend\ControlCenter\Update;

/*
 * This file is part of the TYPO3 CMS project.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 *
 * For the full copyright and license information, please read the
 * LICENSE.txt file that was distributed with this source code.
 *
 * The TYPO3 project - inspiring people to share!
 */

use Tvp\TemplaVoilaPlus\Domain\Model\Configuration\DataConfiguration;
use Tvp\TemplaVoilaPlus\Domain\Model\Place;
use Tvp\TemplaVoilaPlus\Service\ConfigurationService;
use Tvp\TemplaVoilaPlus\Utility\ApiHelperUtility;
use Tvp\TemplaVoilaPlus\Utility\DataStructureUtility;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\Restriction\DeletedRestriction;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Package\PackageManager;
use TYPO3\CMS\Core\Resource\ResourceStorage;
use TYPO3\CMS\Core\Resource\FileRepository;
use TYPO3\CMS\Core\Resource\ResourceFactory;
use TYPO3\CMS\Core\Resource\StorageRepository;
use TYPO3\CMS\Core\Utility\ArrayUtility;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Controller to migrate files to FAL
 *
 * @author Alexander Opitz <opitz.alexander@pluspol-interactive.de>
 */
class FalUpdateHandler extends AbstractUpdateController
{
    const FOLDER_ContentUploads = '_migrated/templavoilaplus_uploads';

    /**
     * @var string
     */
    protected $targetDirectory;

    /**
     * @var ResourceFactory
     */
    protected $fileFactory;

    /**
     * @var FileRepository
     */
    protected $fileRepository;

    /**
     * @var ResourceStorage
     */
    protected $storage;

    /**
     * @var ConnectionPool
     */
    protected $connectionPool;

    /**
     * @var ConfigurationService
     */
    protected $configurationService;


    protected $placesService;

    protected $toFixIdentifiers = [];
    protected $falElementInfo = [];

    public function checkAllFal()
    {
        $notMigratedTTContentCount = 0;

        $this->connectionPool = GeneralUtility::makeInstance(ConnectionPool::class);
        $this->configurationService = GeneralUtility::makeInstance(ConfigurationService::class);
        $this->placesService = $this->configurationService->getPlacesService();

        $notMigratedTTContentCount = $this->checkForUpdate();

        return $notMigratedTTContentCount;
    }
    
    public function updateAllFal()
    {
        $count = 0;

        $this->connectionPool = GeneralUtility::makeInstance(ConnectionPool::class);
        $this->configurationService = GeneralUtility::makeInstance(ConfigurationService::class);
        $this->placesService = $this->configurationService->getPlacesService();

        $notMigratedTTContentCount = $this->checkForUpdate();

        if ($notMigratedTTContentCount > 0) {
            $count = $this->performUpdate();
        }

        return $count;
    }
        
    protected function init()
    {
        $fileadminDirectory = rtrim($GLOBALS['TYPO3_CONF_VARS']['BE']['fileadminDir'], '/') . '/';
        /** @var $storageRepository StorageRepository */
        $storageRepository = GeneralUtility::makeInstance(StorageRepository::class);
        $storages = $storageRepository->findAll();
        foreach ($storages as $storage) {
            $storageRecord = $storage->getStorageRecord();
            $configuration = $storage->getConfiguration();
            $isLocalDriver = $storageRecord['driver'] === 'Local';
            $isOnFileadmin = !empty($configuration['basePath']) && GeneralUtility::isFirstPartOfStr($configuration['basePath'], $fileadminDirectory);
            if ($isLocalDriver && $isOnFileadmin) {
                $this->storage = $storage;
                break;
            }
        }
        if (!isset($this->storage)) {
            throw new \RuntimeException('Local default storage could not be initialized - might be due to missing sys_file* tables.');
        }
        $this->fileFactory = GeneralUtility::makeInstance(ResourceFactory::class);
        $this->fileRepository = GeneralUtility::makeInstance(FileRepository::class);
        $this->targetDirectory = Environment::getPublicPath() . '/' . $fileadminDirectory . self::FOLDER_ContentUploads . '/';
    }

    /**
     * Checks if an update is needed
     *
     * @return bool TRUE if an update is needed, FALSE otherwise
     */
    protected function checkForUpdate()
    {
        $notMigratedTTContentCount = 0;

        if ($this->calculateToFixIdentifiers() > 0) {
            $toFix = array_column($this->toFixIdentifiers, 'combinedMappingIdentifier');

            $queryBuilder = $this->connectionPool->getQueryBuilderForTable('tt_content');
            $notMigratedTTContentCount = $queryBuilder
                ->count('uid')
                ->from('tt_content')
                ->where(
                    $queryBuilder->expr()->in(
                        'tx_templavoilaplus_map',
                        $queryBuilder->createNamedParameter($toFix, Connection::PARAM_STR_ARRAY)
                    )
                )
                ->execute()
                ->fetchColumn(0);
        }

        return $notMigratedTTContentCount;
    }
    
    /**
     * Performs the database update.
     *
     */
    protected function performUpdate()
    {
        $this->init();
        $this->checkPrerequisites();

        $toFix = [];
        foreach ($this->toFixIdentifiers as $identifiers) {
            $dataStructure = ApiHelperUtility::getDataStructure($identifiers['combinedDataStructureIdentifier']);
            $xmlDataStructure = $dataStructure->getDataStructure();

            $this->falElementInfo = [];
            foreach ($xmlDataStructure['ROOT']['el'] as $name => &$element) {
                $elementCallbacks = [[$this, 'getFALElementInfo']];
                $this->datastructureFixPerElement($name, $element, $elementCallbacks);
            }
            $toFix[$identifiers['combinedMappingIdentifier']] = $this->falElementInfo;
        }
        
        $records = $this->getTTContentTofix($toFix);

        foreach ($records as $singleRecord) {
            $this->migrateRecord($singleRecord);
        }

        return count($toFix);
    }

    /**
     * Ensures a new folder "fileadmin/content_upload/" is available.
     *
     * @return void
     */
    protected function checkPrerequisites()
    {
        if (!$this->storage->hasFolder(self::FOLDER_ContentUploads)) {
            $this->storage->createFolder(self::FOLDER_ContentUploads, $this->storage->getRootLevelFolder());
        }
    }

    protected function calculateToFixIdentifiers()
    {
        $mappingPlaces = $this->placesService->getAvailablePlacesUsingConfigurationHandlerIdentifier(
            \Tvp\TemplaVoilaPlus\Handler\Configuration\MappingConfigurationHandler::$identifier
        );
        $this->placesService->loadConfigurationsByPlaces($mappingPlaces);        

        foreach ($mappingPlaces as $identifier => $mappingPlace) {
            foreach ($mappingPlace->getConfigurations() as $mappingConfiguration) {
                $dataStructure = ApiHelperUtility::getDataStructure($mappingConfiguration->getCombinedDataStructureIdentifier());
                $xmlDataStructure = $dataStructure->getDataStructure();

                $recursiveIterator = new \RecursiveIteratorIterator(new \RecursiveArrayIterator($xmlDataStructure), \RecursiveIteratorIterator::SELF_FIRST);
                foreach($recursiveIterator as $k => $v) {
                    if (
                        !empty($v['type']) && trim($v['type']) === 'group'
                        && isset($v['internal_type']) && trim($v['internal_type']) === 'file'
                    ) {
                        $combinedMappingIdentifier = $mappingPlace->getIdentifier() . ':' . $mappingConfiguration->getIdentifier();
                        $this->toFixIdentifiers[] = array('identifier' => $identifier, 'dataStructureIdentifier' => $dataStructure->getIdentifier(), 'combinedMappingIdentifier' => $combinedMappingIdentifier, 'combinedDataStructureIdentifier' => $mappingConfiguration->getCombinedDataStructureIdentifier());
                        break;
                    }
                }
            }
        }
        return count($this->toFixIdentifiers);
    }
    
    protected function getTTContentTofix($toFix)
    {
        $records = [];

        foreach ($toFix as $combinedMappingIdentifier => $dsPropertiesInfos) {
            $queryBuilder = $this->connectionPool->getQueryBuilderForTable('tt_content');
            $queryBuilder->getRestrictions()->removeAll();
            $statement = $queryBuilder->select('uid', 'pid', 'sorting', 'tx_templavoilaplus_flex')
                ->from('tt_content')
                ->where(
                    $queryBuilder->expr()->eq(
                        'tx_templavoilaplus_map',
                        $queryBuilder->createNamedParameter($combinedMappingIdentifier, \PDO::PARAM_STR)
                    )
                )
                ->execute();

            while ($row = $statement->fetch()) {
                $record = $row;

                foreach ($dsPropertiesInfos as $dsPropertiesInfo) {
                    $record['uploadFolder'] = $dsPropertiesInfo['uploadFolder'];
                    $record['fieldname'] = $dsPropertiesInfo['fieldname'];
                    
                    $xmlArray = GeneralUtility::xml2array($row['tx_templavoilaplus_flex']);
                    
                    $files = [];
                    $recursiveIterator = new \RecursiveIteratorIterator(new \RecursiveArrayIterator($xmlArray), \RecursiveIteratorIterator::SELF_FIRST);
                    foreach($recursiveIterator as $k => $v) {
                        if($k == $dsPropertiesInfo['fieldname']) {
                            $files[] = $v['vDEF'];
                        }
                    }
                    /*array_walk_recursive($xmlArray, function($v,$k) use(&$files, $dsPropertiesInfo){
                        if($k == $dsPropertiesInfo['fieldname']) $files[] = $v;
                    });*/

                    if (count($files) > 0)
                    {
                        $record['files'] = $files;
                        $records[] = $record;
                    }
                }
            }
        }
        return $records;
    }
    
    protected function migrateRecord(array $record)
    {
        $files = $record['files'];

        foreach ($files as $file) {
            if (!empty($file) && file_exists(Environment::getPublicPath() . '/' . $record['uploadFolder'] . '/' . $file)) {
                GeneralUtility::upload_copy_move(
                    Environment::getPublicPath() . '/' . $record['uploadFolder'] . '/' . $file,
                    $this->targetDirectory . $file);

                $fileObject = $this->storage->getFile(self::FOLDER_ContentUploads . '/' . $file);
                $this->fileRepository->add($fileObject);
                $dataArray = [
                    'uid_local' => $fileObject->getUid(),
                    'tablenames' => 'tt_content',
                    'fieldname' => $record['fieldname'],
                    'uid_foreign' => $record['uid'],
                    'table_local' => 'sys_file',
                    'cruser_id' => 999,
                    'pid' => $record['pid'],
                    'sorting_foreign' => $record['sorting']+= 10,
                    'hidden' => 0,
                ];

                $queryBuilder = $this->connectionPool->getQueryBuilderForTable('sys_file_reference');
                $queryBuilder->getRestrictions()->removeAll();
                $affectedRows = $queryBuilder->insert('sys_file_reference')
                    ->values($dataArray)
                    ->execute();
            }
        }
    }
    
    public function getFALElementInfo(string $name, array &$element): bool
    {
        $changed = false;

        if (
            !empty($element['TCEforms']['config']['type']) && trim($element['TCEforms']['config']['type']) === 'group'
            && isset($element['TCEforms']['config']['internal_type']) && trim($element['TCEforms']['config']['internal_type']) === 'file'
        ) {
            $this->falElementInfo[] = ['fieldname' => $name, 'uploadFolder' => $element['TCEforms']['config']['uploadfolder']];
            
            $changed = true;
        }

        return $changed;
    }
    
    protected function datastructureFixPerElement(string $name, array &$element, array $elementCallbacks)
    {
        $changed = false;

        foreach ($elementCallbacks as $callback) {
            if (is_callable($callback)) {
                $changed = $callback($name, $element) || $changed;
            } else {
                throw new \Exception('Callback function "' . $callback[1] . '" not available. Can\'t update DataStructure.');
            }
        }

        if (isset($element['type']) && $element['type'] === 'array') {
            if (is_array($element['el'])) {
                foreach ($element['el'] as $subElementName => &$subElement) {
                    $changed = $this->datastructureFixPerElement($subElementName, $subElement, $elementCallbacks) || $changed;
                }
            }
        }

        return $changed;
    }
}