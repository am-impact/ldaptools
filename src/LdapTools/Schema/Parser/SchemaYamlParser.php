<?php
/**
 * This file is part of the LdapTools package.
 *
 * (c) Chad Sikorra <Chad.Sikorra@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace LdapTools\Schema\Parser;

use LdapTools\Exception\SchemaParserException;
use LdapTools\Schema\LdapObjectSchema;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/**
 * Parses a schema definition from a YAML file.
 *
 * @author Chad Sikorra <Chad.Sikorra@gmail.com>
 */
class SchemaYamlParser implements SchemaParserInterface
{
    /**
     * @var array Schema names to their YAML mappings (ie. ['ad' => [ <yaml array> ]])
     */
    protected $schemas = [];

    /**
     * @var string The folder where the schema files are located.
     */
    protected $schemaFolder = '';

    /**
     * @var string The folder where the default schema files reside.
     */
    protected $defaultSchemaFolder = '';

    /**
     * @var array
     */
    protected $optionMap = [
        'class' => 'setObjectClass',
        'category' => 'setObjectCategory',
        'attributes_to_select' => 'setAttributesToSelect',
        'repository' => 'setRepository',
        'default_values' => 'setDefaultValues',
        'required_attributes' => 'setRequiredAttributes',
        'default_container' => 'setDefaultContainer',
        'converter_options' => 'setConverterOptions',
        'multivalued_attributes' => 'setMultivaluedAttributes',
        'base_dn' => 'setBaseDn',
    ];

    /**
     * @param string $schemaFolder
     */
    public function __construct($schemaFolder)
    {
        $this->schemaFolder = $schemaFolder;
        $this->defaultSchemaFolder = __DIR__.'/../../../../resources/schema';
    }

    /**
     * Given the schema name, return the last time the schema was modified in DateTime format.
     *
     * @param string $schemaName
     * @return \DateTime
     * @throws SchemaParserException
     */
    public function getSchemaModificationTime($schemaName)
    {
        $file = $this->schemaFolder.'/'.$schemaName.'.yml';
        $this->validateFileCanBeRead($file);

        return new \DateTime('@'.filemtime($file));
    }

    /**
     * {@inheritdoc}
     */
    public function parse($schemaName, $objectType)
    {
        $this->parseSchemaNameToArray($schemaName);

        return $this->parseYamlForObject($this->schemas[$this->schemaFolder][$schemaName], $schemaName, $objectType);
    }

    /**
     * {@inheritdoc}
     */
    public function parseAll($schemaName)
    {
        $this->parseSchemaNameToArray($schemaName);
        $types = [];
        $ldapObjectSchemas = [];

        if (isset($this->schemas[$this->schemaFolder][$schemaName]['objects'])) {
            $types = array_column($this->schemas[$this->schemaFolder][$schemaName]['objects'], 'type');
        }

        foreach ($types as $type) {
            $ldapObjectSchemas[] = $this->parseYamlForObject(
                $this->schemas[$this->schemaFolder][$schemaName],
                $schemaName,
                $type
            );
        }

        return $ldapObjectSchemas;
    }

    /**
     * Make sure a file is readable.
     *
     * @param string $file
     * @throws SchemaParserException
     */
    protected function validateFileCanBeRead($file)
    {
        if (!is_readable($file)) {
            throw new SchemaParserException(sprintf("Cannot read schema file: %s", $file));
        }
    }

    /**
     * Attempt to find the object type definition in the schema and create its object representation.
     *
     * @param array $schema
     * @param string $schemaName
     * @param string $objectType
     * @return LdapObjectSchema
     * @throws SchemaParserException
     */
    protected function parseYamlForObject(array $schema, $schemaName, $objectType)
    {
        if (!array_key_exists('objects', $schema)) {
            throw new SchemaParserException('Cannot find the "objects" section in the schema file.');
        }
        $objectSchema = $this->getObjectFromSchema($schema, $objectType);
        $objectSchema = $this->mergeAnyExtendedSchemas($objectSchema, $schemaName);
        $this->validateObjectSchema($objectSchema, $objectType);
        $ldapObjectSchema = new LdapObjectSchema($schemaName, $objectType);

        foreach ($this->optionMap as $option => $setter) {
            if (array_key_exists($option, $objectSchema)) {
                $ldapObjectSchema->$setter($objectSchema[$option]);
            }
        }

        if (!((bool)count(array_filter(array_keys($objectSchema['attributes']), 'is_string')))) {
            throw new SchemaParserException('The attributes for a schema should be an associative array.');
        }
        $ldapObjectSchema->setAttributeMap($objectSchema['attributes']);
        $ldapObjectSchema->setConverterMap($this->parseConverterMap($objectSchema));

        return $ldapObjectSchema;
    }

    /**
     * Check for a specific object type in the schema and validate it.
     *
     * @param array $schema
     * @param string $objectType
     * @return array
     * @throws SchemaParserException
     */
    protected function getObjectFromSchema(array $schema, $objectType)
    {
        $objectSchema = null;
        foreach ($schema['objects'] as $ldapObject) {
            if (array_key_exists('type', $ldapObject) && $ldapObject['type'] == $objectType) {
                $objectSchema = $ldapObject;
            }
        }
        if (is_null($objectSchema)) {
            throw new SchemaParserException(sprintf('Cannot find object type "%s" in schema.', $objectType));
        }

        return $objectSchema;
    }

    /**
     * Parse the converters section of an object schema definition to generate the attribute converter map.
     *
     * @param array $objectSchema
     * @return array
     */
    protected function parseConverterMap(array $objectSchema)
    {
        $converterMap = [];

        if (array_key_exists('converters', $objectSchema)) {
            foreach ($objectSchema['converters'] as $converter => $attributes) {
                if (is_array($attributes)) {
                    foreach ($attributes as $attribute) {
                        $converterMap[$attribute] = $converter;
                    }
                } elseif (is_string($attributes)) {
                    $converterMap[$attributes] = $converter;
                }
            }
        }

        return $converterMap;
    }

    /**
     * Validate that an object schema meets the minimum requirements.
     *
     * @param array $objectSchema
     * @param string $objectType
     * @throws SchemaParserException
     */
    protected function validateObjectSchema($objectSchema, $objectType)
    {
        if (!array_key_exists('class', $objectSchema) && !array_key_exists('category', $objectSchema)) {
            throw new SchemaParserException(sprintf('Object type "%s" has no class or category defined.', $objectType));
        }
        if (!array_key_exists('attributes', $objectSchema) || empty($objectSchema['attributes'])) {
            throw new SchemaParserException(sprintf('Object type "%s" has no attributes defined.', $objectType));
        }
    }

    /**
     * Given a schema name, parse it into the array.
     *
     * @param string $schemaName
     * @throws SchemaParserException
     */
    protected function parseSchemaNameToArray($schemaName)
    {
        if (!isset($this->schemas[$this->schemaFolder][$schemaName])) {
            $file = $this->schemaFolder . '/' . $schemaName . '.yml';
            $this->validateFileCanBeRead($file);

            try {
                $this->schemas[$this->schemaFolder][$schemaName] = Yaml::parse(file_get_contents($file));
            } catch (ParseException $e) {
                throw new SchemaParserException(sprintf('Error in configuration file: %s', $e->getMessage()));
            }
            $this->mergeDefaultSchemaFile($schemaName);
            $this->mergeIncludedSchemas($schemaName);
        }
    }

    /**
     * If the 'include' directive is used, then merge the specified schemas into the current one.
     *
     * @param string $schemaName
     * @throws SchemaParserException
     */
    protected function mergeIncludedSchemas($schemaName)
    {
        if (!isset($this->schemas[$this->schemaFolder][$schemaName]['include'])) {
            return;
        }
        $includes = $this->schemas[$this->schemaFolder][$schemaName]['include'];

        if (!is_array($includes)) {
            $includes = [$includes];
        }

        foreach ($includes as $schema) {
            $this->parseSchemaNameToArray($schema);
            $this->schemas[$this->schemaFolder][$schemaName]['objects'] = array_merge(
                $this->schemas[$this->schemaFolder][$schemaName]['objects'],
                $this->schemas[$this->schemaFolder][$schema]['objects']
            );
        }
    }

    /**
     * If the 'extends_default' directive is used, then merge the specified default schema.
     *
     * @param string $schemaName
     * @throws SchemaParserException
     */
    protected function mergeDefaultSchemaFile($schemaName)
    {
        if (!isset($this->schemas[$this->schemaFolder][$schemaName]['extends_default'])) {
            return;
        }
        $defaultSchemaName = $this->schemas[$this->schemaFolder][$schemaName]['extends_default'];
        $folder = $this->schemaFolder;

        $this->schemaFolder = $this->defaultSchemaFolder;
        $this->parseSchemaNameToArray($defaultSchemaName);
        // Perhaps an option at some point to specify the merge action/type? ie. replace vs merge.
        $this->schemas[$folder][$schemaName] = array_merge_recursive(
            $this->schemas[$this->schemaFolder][$defaultSchemaName],
            $this->schemas[$folder][$schemaName]
        );

        $this->schemaFolder = $folder;
    }

    /**
     * If the 'extends' option is given, then merge this schema object with the requested schema object.
     *
     * @param array $objectSchema
     * @param string $schemaName
     * @return array
     * @throws SchemaParserException
     */
    protected function mergeAnyExtendedSchemas(array $objectSchema, $schemaName)
    {
        if (!(isset($objectSchema['extends']) || isset($objectSchema['extends_default']))) {
            return $objectSchema;
        }

        return array_merge_recursive($this->getParentSchemaObject($objectSchema, $schemaName), $objectSchema);
    }

    /**
     * If we need to retrieve one of the default schemas, then it's probably the case that the schema folder path was
     * manually defined. So retrieve the default schema object by parsing the name from the default folder path and then
     * reset the schema folder back to what it originally was.
     *
     * @param array $objectSchema
     * @return array
     * @throws SchemaParserException
     */
    protected function getExtendedDefaultSchemaObject(array $objectSchema)
    {
        if (!(is_array($objectSchema['extends_default']) && 2 == count($objectSchema['extends_default']))) {
            throw new SchemaParserException('The "extends_default" directive should be an array with exactly 2 values.');
        }
        $folder = $this->schemaFolder;
        $this->schemaFolder = $this->defaultSchemaFolder;

        $this->parseSchemaNameToArray(reset($objectSchema['extends_default']));
        $parent = $this->getObjectFromSchema(
            $this->schemas[$this->defaultSchemaFolder][reset($objectSchema['extends_default'])],
            $objectSchema['extends_default'][1]
        );

        $this->schemaFolder = $folder;

        return $parent;
    }

    /**
     * Determines what parent array object to get based on the directive used.
     *
     * @param array $objectSchema
     * @param string $schemaName
     * @return array
     * @throws SchemaParserException
     */
    protected function getParentSchemaObject(array $objectSchema, $schemaName)
    {
        if (isset($objectSchema['extends_default'])) {
            $parent = $this->getExtendedDefaultSchemaObject($objectSchema);
        } elseif (isset($objectSchema['extends']) && is_string($objectSchema['extends'])) {
            $parent = $this->getObjectFromSchema($this->schemas[$this->schemaFolder][$schemaName], $objectSchema['extends']);
        } elseif (isset($objectSchema['extends']) && is_array($objectSchema['extends']) && 2 == count($objectSchema['extends'])) {
            $name = reset($objectSchema['extends']);
            $type = $objectSchema[1];
            $this->parseSchemaNameToArray($name);
            $parent = $this->getObjectFromSchema($this->schemas[$this->schemaFolder][$name], $type);
        } else {
            throw new SchemaParserException('The directive "extends" must be a string or array with exactly 2 values.');
        }

        return $parent;
    }
}
