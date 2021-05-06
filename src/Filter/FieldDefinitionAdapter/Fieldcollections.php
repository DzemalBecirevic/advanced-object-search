<?php

/**
 * Pimcore
 *
 * This source file is available under two different licenses:
 * - GNU General Public License version 3 (GPLv3)
 * - Pimcore Commercial License (PCL)
 * Full copyright and license information is available in
 * LICENSE.md which is distributed with this source code.
 *
 *  @copyright  Copyright (c) Pimcore GmbH (http://www.pimcore.org)
 *  @license    http://www.pimcore.org/license     GPLv3 and PCL
 */

namespace AdvancedObjectSearchBundle\Filter\FieldDefinitionAdapter;

use AdvancedObjectSearchBundle\Filter\FieldSelectionInformation;
use AdvancedObjectSearchBundle\Filter\FilterEntry;
use ONGR\ElasticsearchDSL\BuilderInterface;
use ONGR\ElasticsearchDSL\Query\Compound\BoolQuery;
use ONGR\ElasticsearchDSL\Query\Joining\NestedQuery;
use Pimcore\Model\DataObject\AbstractObject;
use Pimcore\Model\DataObject\ClassDefinition\Data;
use Pimcore\Model\DataObject\Concrete;
use Pimcore\Model\DataObject\Fieldcollection;

class Fieldcollections extends DefaultAdapter implements FieldDefinitionAdapterInterface
{
    /**
     * field type for search frontend
     *
     * @var string
     */
    protected $fieldType = 'fieldcollections';

    /**
     * @var Data\Fieldcollections
     */
    protected $fieldDefinition;

    /**
     * @return array
     */
    public function getESMapping()
    {
        $allowedTypes = $this->fieldDefinition->getAllowedTypes();
        if (empty($allowedTypes)) {
            $allFieldCollectionTypes = new Fieldcollection\Definition\Listing();
            foreach ($allFieldCollectionTypes->load() as $type) {
                $allowedTypes[] = $type->getKey();
            }
        }

        $mappingProperties = [];

        foreach ($allowedTypes as $fieldCollectionKey) {
            /**
             * @var $fieldCollectionDefinition Fieldcollection\Definition
             */
            $fieldCollectionDefinition = Fieldcollection\Definition::getByKey($fieldCollectionKey);

            $childMappingProperties = [];
            foreach ($fieldCollectionDefinition->getFieldDefinitions() as $field) {
                $fieldDefinitionAdapter = $this->service->getFieldDefinitionAdapter($field, false);
                list($key, $mappingEntry) = $fieldDefinitionAdapter->getESMapping();
                $childMappingProperties[$key] = $mappingEntry;
            }

            $mappingProperties[$fieldCollectionKey] = [
                'type' => 'nested',
                'properties' => $childMappingProperties
            ];
        }

        return [
            $this->fieldDefinition->getName(),
            [
                'type' => 'nested',
                'properties' => $mappingProperties
            ]
        ];
    }

    /**
     * @param $fieldFilter
     *
     * filter field format as follows:
     *      [
     *          'type' => 'FIELD_COLLECTION_TYPE'
     *          'filterCondition' => FilterEntry[]  - FULL FEATURES FILTER ENTRY ARRAY
     *      ]
     * @param bool $ignoreInheritance
     * @param string $path
     *
     * @return BuilderInterface
     */
    public function getQueryPart($fieldFilter, $ignoreInheritance = false, $path = '')
    {
        $filterEntryObject = $this->service->buildFilterEntryObject($fieldFilter['filterCondition']);
        $fieldCollectionType = $fieldFilter['type'];

        $innerBoolQuery = new BoolQuery();

        $innerPath = $path . $this->fieldDefinition->getName() . '.' . $fieldCollectionType;

        if ($filterEntryObject->getFilterEntryData() instanceof BuilderInterface) {

            // add given builder interface without any further processing
            $innerBoolQuery->add($filterEntryObject->getFilterEntryData(), $filterEntryObject->getOuterOperator());
        } else {
            $definition = Fieldcollection\Definition::getByKey($fieldCollectionType);
            $fieldDefinition = $definition->getFielddefinition($filterEntryObject->getFieldname());
            $fieldDefinitionAdapter = $this->service->getFieldDefinitionAdapter($fieldDefinition, false);

            if ($filterEntryObject->getOperator() == FilterEntry::EXISTS || $filterEntryObject->getOperator() == FilterEntry::NOT_EXISTS) {

                //add exists filter generated by filter definition adapter
                $innerBoolQuery->add(
                    $fieldDefinitionAdapter->getExistsFilter($filterEntryObject->getFilterEntryData(), true, $innerPath . '.'),
                    $filterEntryObject->getOuterOperator()
                );
            } else {

                //add query part generated by filter definition adapter
                $innerBoolQuery->add(
                    $fieldDefinitionAdapter->getQueryPart($filterEntryObject->getFilterEntryData(), true, $innerPath . '.'),
                    $filterEntryObject->getOuterOperator()
                );
            }
        }

        return new NestedQuery(
            $path . $this->fieldDefinition->getName(),
            new NestedQuery($innerPath, $innerBoolQuery)
        );
    }

    /**
     * @param Concrete $object
     *
     * @return array
     */
    public function getIndexData($object)
    {
        $data = [];

        $getter = 'get' . ucfirst($this->fieldDefinition->getName());
        $fieldCollectionItems = $object->$getter();

        if ($fieldCollectionItems) {

            //deactivate inheritance since within field collections there is no inheritance
            $inheritanceBackup = AbstractObject::getGetInheritedValues();
            AbstractObject::setGetInheritedValues(false);

            foreach ($fieldCollectionItems->getItems() as $item) {
                /**
                 * @var $item \Pimcore\Model\DataObject\Fieldcollection\Data\AbstractData
                 */
                $definition = Fieldcollection\Definition::getByKey($item->getType());

                $fieldCollectionData = [];

                foreach ($definition->getFieldDefinitions() as $key => $field) {
                    $fieldDefinitionAdapter = $this->service->getFieldDefinitionAdapter($field, false);
                    $fieldCollectionData[$key] = $fieldDefinitionAdapter->getIndexData($item);
                }

                $data[$item->getType()][] = $fieldCollectionData;
            }

            //reset inheritance
            AbstractObject::setGetInheritedValues($inheritanceBackup);
        }

        return $data;
    }

    /**
     * @inheritdoc
     */
    public function getFieldSelectionInformation()
    {
        $allowedTypes = [];
        foreach ($this->fieldDefinition->getAllowedTypes() as $allowedType) {
            $allowedTypes[] = [$allowedType];
        }

        return [new FieldSelectionInformation(
            $this->fieldDefinition->getName(),
            $this->fieldDefinition->getTitle(),
            $this->fieldType,
            [
                'allowedTypes' => $allowedTypes,
            ]
        )];
    }
}
