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

use Tvp\TemplaVoilaPlus\Domain\Model\Configuration\MappingConfiguration;
use Tvp\TemplaVoilaPlus\Domain\Model\Place;
use Tvp\TemplaVoilaPlus\Service\ConfigurationService;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Handles Updates in MappingConfiguration via Callbacks
 *
 * @author Alexander Opitz <opitz.alexander@pluspol-interactive.de>
 */
class MappingConfigurationUpdateHandler
{
    public function checkAllMc(array $rootCallbacks, array $elementCallbacks)
    {
        $count = 0;

        /** @var ConfigurationService */
        $configurationService = GeneralUtility::makeInstance(ConfigurationService::class);
        $placesService = $configurationService->getPlacesService();

        $mappingPlaces = $placesService->getAvailablePlacesUsingConfigurationHandlerIdentifier(
            \Tvp\TemplaVoilaPlus\Handler\Configuration\MappingConfigurationHandler::$identifier
        );
        $placesService->loadConfigurationsByPlaces($mappingPlaces);        

        foreach ($mappingPlaces as $identifier => $mappingPlace) {
            foreach ($mappingPlace->getConfigurations() as $mappingConfiguration) {
                if ($this->checkMc($mappingConfiguration, $mappingPlace, $rootCallbacks, $elementCallbacks)) {
                    $count++;
                }
            }
        }
        return $count;
    }
    
    public function updateAllMc(array $rootCallbacks, array $elementCallbacks)
    {
        $count = 0;

        /** @var ConfigurationService */
        $configurationService = GeneralUtility::makeInstance(ConfigurationService::class);
        $placesService = $configurationService->getPlacesService();

        $mappingPlaces = $placesService->getAvailablePlacesUsingConfigurationHandlerIdentifier(
            \Tvp\TemplaVoilaPlus\Handler\Configuration\MappingConfigurationHandler::$identifier
        );
        $placesService->loadConfigurationsByPlaces($mappingPlaces);        

        foreach ($mappingPlaces as $identifier => $mappingPlace) {
            foreach ($mappingPlace->getConfigurations() as $mappingConfiguration) {
                if ($this->updateMc($mappingConfiguration, $mappingPlace, $rootCallbacks, $elementCallbacks)) {
                    $count++;
                }
            }
        }
        return $count;
    }

    public function updateMc(MappingConfiguration $mappingConfiguration, Place $mappingPlace, array $rootCallbacks, array $elementCallbacks): bool
    {
        /** @var MappingConfiguration */
        $mappingToTemplate = $mappingConfiguration->getMappingToTemplate();

        $changed = $this->processUpdate($mappingToTemplate, $rootCallbacks, $elementCallbacks);

        if ($changed) {
            $mappingConfiguration->setMappingToTemplate($mappingToTemplate);
            $mappingPlace->setConfiguration($mappingConfiguration->getIdentifier(), $mappingConfiguration);

            return true;
        }
        return false;
    }
    
    public function checkMc(MappingConfiguration $mappingConfiguration, Place $mappingPlace, array $rootCallbacks, array $elementCallbacks): bool
    {
        /** @var MappingConfiguration */
        $mappingToTemplate = $mappingConfiguration->getMappingToTemplate();

        $changed = $this->processUpdate($mappingToTemplate, $rootCallbacks, $elementCallbacks);

        if ($changed) {
            return true;
        }
        return false;
    }

    public function processUpdate(
        array &$data,
        array $rootCallbacks,
        array $elementCallbacks
    ) {
        $changed = false;

        if (empty($data)) {
            return false;
        }

        foreach ($rootCallbacks as $callback) {
            if (is_callable($callback)) {
                $changed = $callback($data) || $changed;
            } else {
                throw new \Exception('Callback function "' . $callback[1] . '" not available. Cann\'t update MappingConfiguration.');
            }
        }

        foreach ($data as $name => &$element) {
            $changed = $this->fixPerElement($name, $element, $elementCallbacks) || $changed;
        }

        return $changed;
    }

    protected function fixPerElement(string $name, array &$element, array $elementCallbacks)
    {
        $changed = false;

        foreach ($elementCallbacks as $callback) {
            if (is_callable($callback)) {
                $changed = $callback($name, $element) || $changed;
            } else {
                throw new \Exception('Callback function "' . $callback[1] . '" not available. Can\'t update MappingConfiguration.');
            }
        }

        if (isset($element['container']) && is_array($element['container'])) {
            foreach ($element['container'] as $subElementName => &$subElement) {
                $changed = $this->fixPerElement($subElementName, $subElement, $elementCallbacks) || $changed;
            }
        }

        return $changed;
    }
}
