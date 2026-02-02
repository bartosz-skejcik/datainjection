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

use Glpi\Asset\AssetModel;
use Glpi\Asset\AssetDefinition;
use Glpi\Asset\AssetDefinitionManager;

/**
 * Custom Asset Model injection class for GLPI v11 custom asset definitions.
 * This class dynamically handles injection for custom asset model dropdowns.
 */
class PluginDatainjectionCustomAssetModelInjection extends AssetModel implements PluginDatainjectionInjectionInterface
{
    /**
     * The system name of the asset definition
     */
    protected static string $definition_system_name = '';

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
            throw new \RuntimeException('Asset definition is expected to be defined for custom asset model injection.');
        }
        return $definition;
    }

    public static function getTable($classname = null)
    {
        return AssetModel::getTable();
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
        $model_class = $definition->getAssetModelClassName();

        // Get search options from the concrete model class
        $tab = Search::getOptions($model_class);

        // Remove blacklisted options
        $blacklist = PluginDatainjectionCommonInjectionLib::getBlacklistedOptions($model_class);

        $options['ignore_fields'] = $blacklist;

        $options['displaytype'] = [
            "multiline_text" => [16],
            "integer"        => [],
        ];

        return PluginDatainjectionCommonInjectionLib::addToSearchOptions($tab, $options, $this);
    }

    /**
     * @see plugins/datainjection/inc/PluginDatainjectionInjectionInterface::addOrUpdateObject()
     */
    public function addOrUpdateObject($values = [], $options = [])
    {
        $definition = static::getDefinition();
        $model_class = $definition->getAssetModelClassName();

        // Create an instance of the concrete model class
        $model = new $model_class();

        // Ensure asset definition ID is set
        $itemtype = $model_class;
        if (isset($values[$itemtype])) {
            $values[$itemtype]['assets_assetdefinitions_id'] = $definition->getID();
        }

        $lib = new PluginDatainjectionCommonInjectionLib($model, $values, $options);
        $lib->processAddOrUpdate();
        return $lib->getInjectionResults();
    }

    /**
     * Add specific needed fields
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
 * Factory class to create model injection instances for specific custom asset definitions.
 */
class PluginDatainjectionCustomAssetModelInjectionFactory
{
    /**
     * Cache for created injection classes
     */
    private static array $injection_classes = [];

    /**
     * Create or get an injection class for a specific asset definition's model
     */
    public static function getInjectionClass(AssetDefinition $definition): string
    {
        $system_name = $definition->fields['system_name'];
        $cache_key = $system_name . '_model';

        if (!isset(self::$injection_classes[$cache_key])) {
            self::createInjectionClass($definition);
        }

        return self::$injection_classes[$cache_key];
    }

    /**
     * Create a dynamic injection class for a custom asset model
     */
    private static function createInjectionClass(AssetDefinition $definition): void
    {
        $system_name = $definition->fields['system_name'];
        $class_name = 'PluginDatainjection' . $system_name . 'AssetModelInjection';
        $model_class = $definition->getAssetModelClassName();
        $cache_key = $system_name . '_model';

        // Create the class dynamically
        $class_code = <<<PHP
class {$class_name} extends {$model_class} implements PluginDatainjectionInjectionInterface
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
        \$model_class = \$definition->getAssetModelClassName();

        \$tab = Search::getOptions(\$model_class);

        \$blacklist = PluginDatainjectionCommonInjectionLib::getBlacklistedOptions(\$model_class);

        \$options['ignore_fields'] = \$blacklist;

        \$options['displaytype'] = [
            "multiline_text" => [16],
            "integer"        => [],
        ];

        return PluginDatainjectionCommonInjectionLib::addToSearchOptions(\$tab, \$options, \$this);
    }

    public function addOrUpdateObject(\$values = [], \$options = [])
    {
        \$definition = static::getDefinition();
        \$itemtype = \$definition->getAssetModelClassName();

        if (isset(\$values[\$itemtype])) {
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

        self::$injection_classes[$cache_key] = $class_name;
    }

    /**
     * Get all injectable custom asset model types
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
}
