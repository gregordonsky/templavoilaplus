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

use Tvp\TemplaVoilaPlus\Utility\TemplaVoilaUtility;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\Utility\StringUtility;

/**
 * Controller to migrate/update the MappingConfiguration for TYPO3 v10 LTS
 *
 * @author Alexander Opitz <opitz@extrameile-gehen.de>
 */
class FalMigrationController extends AbstractUpdateController
{
    protected $errors = [];

    protected function stepStartAction()
    {
        /** @var FalUpdateHandler */
        $handler = GeneralUtility::makeInstance(FalUpdateHandler::class);
        $notMigratedTTContentCount = $handler->checkAllFal();

        /** @var MappingConfigurationUpdateHandler */
        $handler = GeneralUtility::makeInstance(MappingConfigurationUpdateHandler::class);
        $notMigratedMappingConfigurationCount = $handler->checkAllMc(
            [],
            [
                [$this, 'fixTyposcriptImageElement']
            ]
        );

        $this->view->assignMultiple([
            'notMigratedTTContentCount' => $notMigratedTTContentCount,
            'notMigratedMappingConfigurationCount' => $notMigratedMappingConfigurationCount,
            'targetFolder' => FalUpdateHandler::FOLDER_ContentUploads,
        ]);
    }

    protected function stepFinalAction()
    {
        $count = [];

        /** @var FalUpdateHandler */
        $handler = GeneralUtility::makeInstance(FalUpdateHandler::class);
        $count[] = $handler->updateAllFal();
        
        /** @var DataStructureUpdateHandler */
        $handler = GeneralUtility::makeInstance(DataStructureUpdateHandler::class);
        $count[] = $handler->updateAllDs(
            [
                [$this, 'migrateFileInternalTypeToFAL'],
            ],
            []
        );

        /** @var MappingConfigurationUpdateHandler */
        $handler = GeneralUtility::makeInstance(MappingConfigurationUpdateHandler::class);
        $count[] = $handler->updateAllMc(
            [],
            [
                [$this, 'fixTyposcriptImageElement']
            ]
        );

        $this->view->assignMultiple([
            'countStatic' => $countStatic,
            'count' => array_sum($count),
            'errors' => $this->errors,
        ]);
    }

    /**
     * Execute fixFALElement for each element
     */
    public function migrateFileInternalTypeToFAL(array &$data): bool
    {
        $changed = false;

        foreach ($data['ROOT']['el'] as $name => &$element) {
            $elementCallbacks = [[$this, 'fixFALElement']];
            $changed = $this->datastructureFixPerElement($name, $element, $elementCallbacks) || $changed;
        }

        return $changed;
    }

    /**
     * Find TCA property values internal_type="file" for columns config type="group" and migrate to FAL references based on type=inline
     */
    public function fixFALElement(string $name, array &$element): bool
    {
        $changed = false;

        if (
            !empty($element['TCEforms']['config']['type']) && trim($element['TCEforms']['config']['type']) === 'group'
            && isset($element['TCEforms']['config']['internal_type']) && trim($element['TCEforms']['config']['internal_type']) === 'file'
        ) {
            unset($element['TCEforms']['config']['internal_type']);
            unset($element['TCEforms']['config']['max_size']);
            unset($element['TCEforms']['config']['uploadfolder']);

            $element['TCEforms']['config']['type'] = 'inline';

            $element['TCEforms']['config']['foreign_table'] = 'sys_file_reference';
            $element['TCEforms']['config']['foreign_table_field'] = 'tablenames';
            $element['TCEforms']['config']['foreign_match_fields']['fieldname'] = $name;
            $element['TCEforms']['config']['foreign_label'] = 'uid_local';
            $element['TCEforms']['config']['foreign_sortby'] = 'sorting_foreign';
            $element['TCEforms']['config']['foreign_field'] = 'uid_foreign';
            $element['TCEforms']['config']['foreign_selector'] = 'uid_local';

            $element['TCEforms']['config']['filter'] = [];
            $element['TCEforms']['config']['filter']['userFunc'] = 'TYPO3\\CMS\\Core\\Resource\\Filter\\FileExtensionFilter->filterInlineChildren';
            $element['TCEforms']['config']['filter']['parameters'] = [];

            $allowed = !empty($element['TCEforms']['config']['allowed']) ? $element['TCEforms']['config']['allowed'] : 'gif,jpg,jpeg,png,svg';
            $element['TCEforms']['config']['filter']['parameters']['allowedFileExtensions'] = $allowed;
            unset($element['TCEforms']['config']['allowed']);

            $element['TCEforms']['config']['appearance'] = [];
            $element['TCEforms']['config']['appearance']['useSortable'] = '1';
            $element['TCEforms']['config']['appearance']['newRecordLinkAddTitle'] = '1';
            $element['TCEforms']['config']['appearance']['headerThumbnail'] = [];
            $element['TCEforms']['config']['appearance']['headerThumbnail']['field'] = 'uid_local';
            $element['TCEforms']['config']['appearance']['headerThumbnail']['width'] = '70';
            $element['TCEforms']['config']['appearance']['headerThumbnail']['height'] = '100';
            $element['TCEforms']['config']['appearance']['enabledControls'] = [];
            $element['TCEforms']['config']['appearance']['enabledControls']['info'] = '1';
            $element['TCEforms']['config']['appearance']['enabledControls']['new'] = '0';
            $element['TCEforms']['config']['appearance']['enabledControls']['dragdrop'] = '0';
            $element['TCEforms']['config']['appearance']['enabledControls']['sort'] = '1';
            $element['TCEforms']['config']['appearance']['enabledControls']['hide'] = '0';
            $element['TCEforms']['config']['appearance']['enabledControls']['delete'] = '1';
            $element['TCEforms']['config']['appearance']['enabledControls']['localize'] = '1';
            $element['TCEforms']['config']['appearance']['createNewRelationLinkTitle'] = 'LLL:EXT:frontend/Resources/Private/Language/locallang_ttc.xlf:images.addFileReference';

            $element['TCEforms']['config']['behaviour'] = [];
            $element['TCEforms']['config']['behaviour']['allowLanguageSynchronization'] = '1';

            $element['TCEforms']['config']['overrideChildTca'] = [];
            $element['TCEforms']['config']['overrideChildTca']['columns'] = [];
            $element['TCEforms']['config']['overrideChildTca']['columns']['uid_local'] = [];
            $element['TCEforms']['config']['overrideChildTca']['columns']['uid_local']['config'] = [];
            $element['TCEforms']['config']['overrideChildTca']['columns']['uid_local']['config']['appearance'] = [];
            $element['TCEforms']['config']['overrideChildTca']['columns']['uid_local']['config']['appearance']['elementBrowserType'] = 'file';
            $element['TCEforms']['config']['overrideChildTca']['columns']['uid_local']['config']['appearance']['elementBrowserAllowed'] = $allowed;
            $element['TCEforms']['config']['overrideChildTca']['types'] = [];
            $element['TCEforms']['config']['overrideChildTca']['types'][2]['showitem'] = '--palette--;LLL:EXT:lang/locallang_tca.xlf:sys_file_reference.imageoverlayPalette;imageoverlayPalette,--palette--;;filePalette';

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

    /**
     * Find Typoscript IMAGE with file.import and migrate to FAL
     */
    public function fixTyposcriptImageElement(string $name, array &$element): bool
    {
        $changed = false;
        $search[0] = '10 = IMAGE';
        $search[1] = '10.file.import';

        if (
            !empty($element['valueProcessing']) && trim($element['valueProcessing']) === 'typoScript'
            && isset($element['valueProcessing.typoScript'])
            && substr(trim($element['valueProcessing.typoScript']), 0, strlen($search[0])) === $search[0]
            && strpos(trim($element['valueProcessing.typoScript']), $search[1]) !== false
        ) {         
            $params = [];

            preg_match("/^10.file.width = (.*)/m", $element['valueProcessing.typoScript'], $found);
            $params[] = isset($found[1]) ? 'width = ' . $found[1] : '';

            preg_match("/^10.file.height = (.*)/m", $element['valueProcessing.typoScript'], $found);
            $params[] = isset($found[1]) ? 'height = ' . $found[1] : '';

            preg_match("/^10.file.maxW = (.*)/m", $element['valueProcessing.typoScript'], $found);
            $params[] = isset($found[1]) ? 'maxW = ' . $found[1] : '';

            preg_match("/^10.file.params = (.*)/m", $element['valueProcessing.typoScript'], $found);
            $params[] = isset($found[1]) ? 'params = ' . $found[1] : '';

            $params = implode("\n", array_filter($params));

            unset($element['valueProcessing.typoScript']);
            $element['valueProcessing.typoScript'] = $this->cleanTypoScript(
                "10 = FILES
                10 {
                    references {
                        table = tt_content
                        uid.data = TSFE:register|tx_templavoilaplus_pi1.parentRec.uid
                        fieldName = $name
                    }
                    renderObj = IMAGE
                    renderObj {
                        file {
                            import.data = file:current:uid
                            treatIdAsReference = 1
                            $params
                        }
                    }
                }");

            $changed = true;
        }
        return $changed;
    }   

    protected function cleanTypoScript(string $typoScript): string
    {
        // Convert from different line breaks to system line breaks and trim whitespaces
        $typoScriptSplit = preg_split('/\r\n|\r|\n/', $typoScript);
        $typoScriptSplit = array_map('trim', $typoScriptSplit);

        return implode(PHP_EOL, $typoScriptSplit);
    }
}
