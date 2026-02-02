<?php

/**
 * -------------------------------------------------------------------------
 * DataInjection plugin for GLPI
 * -------------------------------------------------------------------------
 *
 * LICENSE
 *
 * This file is part of DataInjection.
 *
 * DataInjection is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 *
 * DataInjection is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with DataInjection. If not, see <http://www.gnu.org/licenses/>.
 * -------------------------------------------------------------------------
 * @copyright Copyright (C) 2007-2023 by DataInjection plugin team.
 * @license   GPLv2 https://www.gnu.org/licenses/gpl-2.0.html
 * @link      https://github.com/pluginsGLPI/datainjection
 * -------------------------------------------------------------------------
 */

use Glpi\Asset\Asset;
use Glpi\Asset\AssetDefinition;
use Glpi\Asset\AssetDefinitionManager;
use Glpi\Asset\CustomFieldDefinition;

/**
 * Custom Asset injection class for GLPI v11 custom asset definitions.
 * This class dynamically handles injection for any custom asset definition.
 */
class PluginDatainjectionCustomAssetInjection extends Asset implements PluginDatainjectionInjectionInterface
{
    /**
     * The system name of the asset definition
     */
    protected static string $definition_system_name = '';

    /**
     * Cache for the asset definition
     */
    protected ?AssetDefinition $definition = null;

    /**
     * Set the definition system name for this injection instance
     */
    public static function setDefinitionSystemName(string $system_name): void
    {
        static::$definition_system_name = $system_name;
    }

    /**
     * Get the asset definition for this injection
     */
    public static function getDefinition(): AssetDefinition
    {
        $definition = AssetDefinitionManager::getInstance()->getDefinition(static::$definition_system_name);
        if (!($definition instanceof AssetDefinition)) {
            throw new \RuntimeException('Asset definition is expected to be defined for custom asset injection.');
        }
        return $definition;
    }

    public static function getTable($classname = null)
    {
        return Asset::getTable();
    }

    public function isPrimaryType()
    {
        return true;
    }

    public function connectedTo()
    {
        return [];
    }

    public function isNullable($field)
    {
        return true;
    }

    /**
     * @see plugins/datainjection/inc/PluginDatainjectionInjectionInterface::getOptions()
     */
    public function getOptions($primary_type = '')
    {
        $definition = static::getDefinition();
        $asset_class = $definition->getAssetClassName();

        // Get search options from the concrete asset class
        $tab = Search::getOptions($asset_class);

        // Remove blacklisted options
        $blacklist = PluginDatainjectionCommonInjectionLib::getBlacklistedOptions($asset_class);

        // Fields that cannot be imported for custom assets
        $notimportable = [
            91, 92, 93, // Related items
            250, // Asset definition ID (internal)
        ];

        $options['ignore_fields'] = array_merge($blacklist, $notimportable);

        // Define display types for standard fields
        $options['displaytype'] = [
            "dropdown"       => [3, 4, 5, 23, 31, 40, 49, 71],
            "user"           => [24, 70],
            "multiline_text" => [16, 90],
        ];

        // Add custom fields to display types
        $this->addCustomFieldsDisplayTypes($options);

        return PluginDatainjectionCommonInjectionLib::addToSearchOptions($tab, $options, $this);
    }

    /**
     * Add display types for custom fields
     */
    protected function addCustomFieldsDisplayTypes(array &$options): void
    {
        $definition = static::getDefinition();
        $custom_fields = $definition->getCustomFieldDefinitions();

        foreach ($custom_fields as $custom_field) {
            $search_option_id = $custom_field->getSearchOptionID();
            $field_type = $custom_field->fields['type'];

            // Map custom field types to display types
            switch ($field_type) {
                case 'Glpi\\Asset\\CustomFieldType\\StringType':
                    $options['displaytype']['text'][] = $search_option_id;
                    break;
                case 'Glpi\\Asset\\CustomFieldType\\TextType':
                    $options['displaytype']['multiline_text'][] = $search_option_id;
                    break;
                case 'Glpi\\Asset\\CustomFieldType\\NumberType':
                    $options['displaytype']['integer'][] = $search_option_id;
                    break;
                case 'Glpi\\Asset\\CustomFieldType\\BooleanType':
                    $options['displaytype']['bool'][] = $search_option_id;
                    break;
                case 'Glpi\\Asset\\CustomFieldType\\DateType':
                    $options['displaytype']['date'][] = $search_option_id;
                    break;
                case 'Glpi\\Asset\\CustomFieldType\\DateTimeType':
                    $options['displaytype']['datetime'][] = $search_option_id;
                    break;
                case 'Glpi\\Asset\\CustomFieldType\\DropdownType':
                    $options['displaytype']['dropdown'][] = $search_option_id;
                    break;
                case 'Glpi\\Asset\\CustomFieldType\\URLType':
                    $options['displaytype']['text'][] = $search_option_id;
                    break;
            }
        }
    }

    /**
     * @see plugins/datainjection/inc/PluginDatainjectionInjectionInterface::addOrUpdateObject()
     */
    public function addOrUpdateObject($values = [], $options = [])
    {
        // Get the concrete asset class for this definition
        $definition = static::getDefinition();
        $asset_class = $definition->getAssetClassName();

        // Create an instance of the concrete asset class
        $asset = new $asset_class();

        // Process custom fields - convert custom_* fields to the JSON format expected by GLPI
        $this->processCustomFields($values, $definition);

        // Use the common injection library with the concrete asset
        $lib = new PluginDatainjectionCommonInjectionLib($asset, $values, $options);
        $lib->processAddOrUpdate();
        return $lib->getInjectionResults();
    }

    /**
     * Process custom fields and convert them to the JSON format expected by GLPI
     */
    protected function processCustomFields(array &$values, AssetDefinition $definition): void
    {
        $custom_fields = $definition->getCustomFieldDefinitions();
        $custom_fields_data = [];
        $itemtype = $definition->getAssetClassName();

        if (!isset($values[$itemtype])) {
            return;
        }

        foreach ($custom_fields as $custom_field) {
            $field_name = 'custom_' . $custom_field->fields['system_name'];

            if (isset($values[$itemtype][$field_name])) {
                // Store with the custom field ID as key
                $custom_fields_data[$custom_field->getID()] = $values[$itemtype][$field_name];
                // Keep the field for the standard processing
            }
        }

        // Add the custom_fields JSON if we have custom data
        if (!empty($custom_fields_data)) {
            $values[$itemtype]['custom_fields'] = json_encode($custom_fields_data);
        }
    }

    /**
     * Reformat custom field values before injection
     */
    public function reformat(&$values = [])
    {
        $definition = static::getDefinition();
        $custom_fields = $definition->getCustomFieldDefinitions();
        $itemtype = $definition->getAssetClassName();

        if (!isset($values[$itemtype])) {
            return;
        }

        foreach ($custom_fields as $custom_field) {
            $field_name = 'custom_' . $custom_field->fields['system_name'];

            if (isset($values[$itemtype][$field_name])) {
                // Format based on field type
                $field_type = $custom_field->fields['type'];

                switch ($field_type) {
                    case 'Glpi\\Asset\\CustomFieldType\\BooleanType':
                        $values[$itemtype][$field_name] = $this->formatBooleanValue($values[$itemtype][$field_name]);
                        break;
                    case 'Glpi\\Asset\\CustomFieldType\\NumberType':
                        $values[$itemtype][$field_name] = (float) $values[$itemtype][$field_name];
                        break;
                }
            }
        }
    }

    /**
     * Format a boolean value from CSV
     */
    protected function formatBooleanValue($value): int
    {
        if (is_string($value)) {
            $value = strtolower(trim($value));
            if (in_array($value, ['1', 'yes', 'true', 'oui', 'y'])) {
                return 1;
            }
        }
        return (int) $value;
    }

    /**
     * Add specific mandatory fields for the asset definition
     */
    public function addSpecificMandatoryFields()
    {
        $definition = static::getDefinition();
        return [
            'assets_assetdefinitions_id' => $definition->getID(),
        ];
    }

    /**
     * Add additional needed fields
     */
    public function addSpecificNeededFields($primary_type, $values)
    {
        $definition = static::getDefinition();
        return [
            'assets_assetdefinitions_id' => $definition->getID(),
        ];
    }
}


/**
 * Factory class to create injection instances for specific custom asset definitions.
 * This creates dynamically named classes for each custom asset definition.
 */
class PluginDatainjectionCustomAssetInjectionFactory
{
    /**
     * Cache for created injection classes
     */
    private static array $injection_classes = [];

    /**
     * Create or get an injection class for a specific asset definition
     */
    public static function getInjectionClass(AssetDefinition $definition): string
    {
        $system_name = $definition->fields['system_name'];

        if (!isset(self::$injection_classes[$system_name])) {
            self::createInjectionClass($definition);
        }

        return self::$injection_classes[$system_name];
    }

    /**
     * Create a dynamic injection class for a custom asset definition
     */
    private static function createInjectionClass(AssetDefinition $definition): void
    {
        $system_name = $definition->fields['system_name'];
        $class_name = 'PluginDatainjection' . $system_name . 'AssetInjection';
        $asset_class = $definition->getAssetClassName();

        // Create the class dynamically
        $class_code = <<<PHP
class {$class_name} extends {$asset_class} implements PluginDatainjectionInjectionInterface
{
    public static function getTable(\$classname = null)
    {
        return parent::getTable();
    }

    public function isPrimaryType()
    {
        return true;
    }

    public function connectedTo()
    {
        return [];
    }

    public function isNullable(\$field)
    {
        return true;
    }

    public function getOptions(\$primary_type = '')
    {
        \$definition = static::getDefinition();
        \$asset_class = \$definition->getAssetClassName();
        
        \$tab = Search::getOptions(\$asset_class);
        
        \$blacklist = PluginDatainjectionCommonInjectionLib::getBlacklistedOptions(\$asset_class);
        \$notimportable = [250];
        
        \$options['ignore_fields'] = array_merge(\$blacklist, \$notimportable);
        
        \$options['displaytype'] = [
            "dropdown"       => [3, 4, 5, 23, 31, 40, 49, 71],
            "user"           => [24, 70],
            "multiline_text" => [16, 90],
        ];
        
        // Add custom fields display types
        \$custom_fields = \$definition->getCustomFieldDefinitions();
        foreach (\$custom_fields as \$custom_field) {
            \$search_option_id = \$custom_field->getSearchOptionID();
            \$field_type = \$custom_field->fields['type'];
            
            switch (\$field_type) {
                case 'Glpi\\\\Asset\\\\CustomFieldType\\\\StringType':
                case 'Glpi\\\\Asset\\\\CustomFieldType\\\\URLType':
                    \$options['displaytype']['text'][] = \$search_option_id;
                    break;
                case 'Glpi\\\\Asset\\\\CustomFieldType\\\\TextType':
                    \$options['displaytype']['multiline_text'][] = \$search_option_id;
                    break;
                case 'Glpi\\\\Asset\\\\CustomFieldType\\\\NumberType':
                    \$options['displaytype']['integer'][] = \$search_option_id;
                    break;
                case 'Glpi\\\\Asset\\\\CustomFieldType\\\\BooleanType':
                    \$options['displaytype']['bool'][] = \$search_option_id;
                    break;
                case 'Glpi\\\\Asset\\\\CustomFieldType\\\\DateType':
                    \$options['displaytype']['date'][] = \$search_option_id;
                    break;
                case 'Glpi\\\\Asset\\\\CustomFieldType\\\\DateTimeType':
                    \$options['displaytype']['datetime'][] = \$search_option_id;
                    break;
                case 'Glpi\\\\Asset\\\\CustomFieldType\\\\DropdownType':
                    \$options['displaytype']['dropdown'][] = \$search_option_id;
                    break;
            }
        }
        
        return PluginDatainjectionCommonInjectionLib::addToSearchOptions(\$tab, \$options, \$this);
    }

    public function addOrUpdateObject(\$values = [], \$options = [])
    {
        \$definition = static::getDefinition();
        
        // Process custom fields
        \$custom_fields = \$definition->getCustomFieldDefinitions();
        \$custom_fields_data = [];
        \$itemtype = \$definition->getAssetClassName();
        
        if (isset(\$values[\$itemtype])) {
            foreach (\$custom_fields as \$custom_field) {
                \$field_name = 'custom_' . \$custom_field->fields['system_name'];
                if (isset(\$values[\$itemtype][\$field_name])) {
                    \$custom_fields_data[\$custom_field->getID()] = \$values[\$itemtype][\$field_name];
                }
            }
            
            if (!empty(\$custom_fields_data)) {
                \$values[\$itemtype]['custom_fields'] = json_encode(\$custom_fields_data);
            }
            
            // Ensure asset definition ID is set
            \$values[\$itemtype]['assets_assetdefinitions_id'] = \$definition->getID();
        }
        
        \$lib = new PluginDatainjectionCommonInjectionLib(\$this, \$values, \$options);
        \$lib->processAddOrUpdate();
        return \$lib->getInjectionResults();
    }

    public function addSpecificNeededFields(\$primary_type, \$values)
    {
        \$definition = static::getDefinition();
        return [
            'assets_assetdefinitions_id' => \$definition->getID(),
        ];
    }
}
PHP;

        eval($class_code);

        self::$injection_classes[$system_name] = $class_name;
    }

    /**
     * Get all injectable custom asset types
     */
    public static function getInjectableTypes(): array
    {
        $types = [];

        $definitions = AssetDefinitionManager::getInstance()->getDefinitions(true);

        foreach ($definitions as $definition) {
            $class_name = self::getInjectionClass($definition);
            $types[$class_name] = 'datainjection';
        }

        return $types;
    }

    /**
     * Check if custom asset definitions are available
     */
    public static function hasCustomAssetDefinitions(): bool
    {
        if (!class_exists('Glpi\\Asset\\AssetDefinitionManager')) {
            return false;
        }

        $definitions = AssetDefinitionManager::getInstance()->getDefinitions(true);
        return count($definitions) > 0;
    }
}
