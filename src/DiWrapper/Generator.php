<?php
/**
 * DiWrapper
 *
 * This source file is part of the DiWrapper package
 *
 * @package    DiWrapper
 * @license    New BSD License
 * @copyright  Copyright (c) 2013, aimfeld
 */

namespace DiWrapper;

use Zend\Code\Generator\DocBlockGenerator;
use Zend\Di\Di;
use Zend\Di\InstanceManager;
use Zend\Di\ServiceLocator\GeneratorInstance;
use Zend\Code\Generator\MethodGenerator;
use Zend\Code\Generator\ParameterGenerator;
use Zend\Code\Generator\ClassGenerator;
use Zend\Code\Generator\FileGenerator;
use Zend\Config\Config;
use DiWrapper\Exception\RuntimeException;
use DateTime;

/**
 * @package    DiWrapper
 */
class Generator extends \Zend\Di\ServiceLocator\Generator
{
    /**
     * Support for passing a $params array for instance creation
     */
    const PARAMS_ARRAY = '__paramsArray__';
    const INDENT = '    ';

    /**
     * @var Config
     */
    protected $config;

    /**
     * @param Di $injector
     * @param Config $config
     */
    public function __construct(Di $injector, Config $config)
    {
        $this->config = $config;

        parent::__construct($injector);
    }

    /**
     * Construct, configure, and return a PHP class file code generation object
     *
     * Creates a Zend\Code\Generator\FileGenerator object that has
     * created the specified class and service locator methods.
     *
     * @param  null|string                         $filename
     * @throws Exception\RuntimeException
     * @return FileGenerator
     */
    public function getCodeGenerator($filename = null)
    {
        $im = $this->injector->instanceManager();
        $definitions = $this->injector->definitions();
        $classesOrAliases = array_unique(array_merge($definitions->getClasses(), $im->getAliases()));

        $generatorInstances = $this->getGeneratorInstances($classesOrAliases);

        $getterMethods = $this->getGetterMethods($generatorInstances);
        $aliasMethods = $this->getAliasMethods($im);
        $caseStatements = $this->createCaseStatements($generatorInstances);

        // Build get() method body
        $body = "if (!\$newInstance && isset(\$this->services[\$name])) {\n";
        $body .= sprintf("%sreturn \$this->services[\$name];\n}\n\n", self::INDENT);

        // Build switch statement
        $body .= sprintf("switch (%s) {\n%s\n", '$name', implode("\n", $caseStatements));
        $body .= sprintf("%sdefault:\n%sreturn parent::get(%s, %s);\n", self::INDENT, str_repeat(self::INDENT, 2), '$name', '$params');
        $body .= "}\n\n";

        // Build get() method
        $nameParam = new ParameterGenerator('name');
        $paramsParam = new ParameterGenerator('params', 'array', array());
        $newInstanceParam = new ParameterGenerator('newInstance', 'bool', false);

        $get = new MethodGenerator();
        $get->setName('get');
        $get->setParameters(array($nameParam, $paramsParam, $newInstanceParam));
        $get->setDocBlock("@param string \$name\n@param array \$params\n@param bool \$newInstance\n@return mixed");
        $get->setBody($body);

        // Create class code generation object
        $container = new ClassGenerator();
        $classDocBlockGenerator = new DocBlockGenerator();
        $now = (new DateTime('now'))->format('Y-m-d H:i:s');
        $classDocBlockGenerator->setShortDescription(
            sprintf("Generated by %s (%s)", get_class($this), $now));
        $container->setName($this->containerClass)
            ->setExtendedClass('ServiceLocator')
            ->addMethodFromGenerator($get)
            ->addMethods($getterMethods)
            ->addMethods($aliasMethods)
            ->setDocBlock($classDocBlockGenerator);

        // Create PHP file code generation object
        $classFile = new FileGenerator();
        $classFile->setUse('Zend\Di\ServiceLocator')->setClass($container);

        if (null !== $this->namespace) {
            $classFile->setNamespace($this->namespace);
        }

        if (null !== $filename) {
            $classFile->setFilename($filename);
        }

        return $classFile;
    }

    /**
     * @param string[] $classesOrAliases
     * @return GeneratorInstance[]
     */
    protected function getGeneratorInstances(array &$classesOrAliases)
    {
        $paramArrayNames = $this->config->diWrapper->paramArrayNames;
        $newInstanceParams = array();
        foreach ($paramArrayNames as $paramArrayName) {
            $newInstanceParams[$paramArrayName] = self::PARAMS_ARRAY;
        }

        $generatorInstances = array();
        foreach ($classesOrAliases as $classOrAlias) {
            // Filter out abstract classes and interfaces
            $class = new \ReflectionClass($classOrAlias);
            if ($class->isAbstract() || $class->isInterface()) {
                continue;
            }

            try {
                // Support for passing a $params array for instance creation
                $generatorInstance = $this->injector->newInstance($classOrAlias, $newInstanceParams);
            } catch (\Exception $e) {
                continue;
            }

            if ($generatorInstance instanceof GeneratorInstance) {
                $generatorInstances[$classOrAlias] = $generatorInstance;
            }
        }

        return $generatorInstances;
    }

    /**
     * @param GeneratorInstance[] $generatorInstances
     * @throws Exception\RuntimeException
     * @return MethodGenerator[]
     */
    protected function getGetterMethods(array $generatorInstances)
    {
        $im = $this->injector->instanceManager();
        $getters = array();

        foreach ($generatorInstances as $classOrAlias => $generatorInstance) {
            // Parameter list for instantiation
            $params = $this->getParams($classOrAlias, $generatorInstance, $im);

            // Create instantiation code
            $creationCode = $this->getCreationCode($classOrAlias, $generatorInstance, $params);

            // Create method call code
            $methodCallCode = $this->getMethodCallCode($classOrAlias, $generatorInstance);

            // Generate caching statement
            $storage = '';
            $storage .= "if (!\$newInstance) {\n";
            $storage .= sprintf("%s\$this->services['%s'] = \$object;\n}\n\n", self::INDENT, $classOrAlias);

            // Start creating getter
            $getterBody = '';

            // Create fetch of stored service
            $getterBody .= sprintf("if (!\$newInstance && isset(\$this->services['%s'])) {\n", $classOrAlias);
            $getterBody .= sprintf("%sreturn \$this->services['%s'];\n}\n\n", self::INDENT, $classOrAlias);


            // Creation and method calls
            $getterBody .= sprintf("%s\n", $creationCode);
            $getterBody .= $methodCallCode;

            // Stored service
            $getterBody .= $storage;

            // End getter body
            $getterBody .= "return \$object;\n";

            $getterDef = new MethodGenerator();
            $getterDef->setName($this->normalizeAlias($classOrAlias));

            $paramParam = new ParameterGenerator('params', 'array', array());
            $newInstanceParam = new ParameterGenerator('newInstance', 'bool', false);
            $getterDef->setParameters(array($paramParam, $newInstanceParam));
            $getterDef->setBody($getterBody);
            $getterDef->setDocBlock("@param array \$params\n@param bool \$newInstance\n@return \\$classOrAlias");
            $getters[] = $getterDef;
        }

        return $getters;
    }

    /**
     * @param InstanceManager $im
     * @return MethodGenerator[]
     */
    protected function getAliasMethods(InstanceManager $im)
    {
        $aliasMethods = array();
        $aliases = $this->reduceAliases($im->getAliases());
        foreach ($aliases as $class => $classAliases) {
            foreach ($classAliases as $alias) {
                $aliasMethods[] = $this->getCodeGenMethodFromAlias($alias, $class);
            }
        }
        return $aliasMethods;
    }

    /**
     * @param GeneratorInstance[] $generatorInstances
     * @return string[]
     */
    protected function createCaseStatements(array $generatorInstances)
    {
        $caseStatements = array();

        foreach (array_keys($generatorInstances) as $classOrAlias) {
            // Get cases for case statements
            $cases = array($classOrAlias);
            if (isset($aliases[$classOrAlias])) {
                $cases = array_merge($aliases[$classOrAlias], $cases);
            }

            // Build case statement and store
            $getter = $this->normalizeAlias($classOrAlias);
            $statement = '';
            foreach ($cases as $value) {
                $statement .= sprintf("%scase '%s':\n", self::INDENT, $value);
            }
            $statement .= sprintf("%sreturn \$this->%s(\$params, \$newInstance);\n", str_repeat(self::INDENT, 2), $getter);

            $caseStatements[] = $statement;
        }

        return $caseStatements;
    }

    /**
     * E.g. setter injection
     *
     * @param string $classOrAlias
     * @param GeneratorInstance $generatorInstance
     * @return string
     * @throws Exception\RuntimeException
     */
    protected function getMethodCallCode($classOrAlias, GeneratorInstance $generatorInstance)
    {
        $methods = '';
        foreach ($generatorInstance->getMethods() as $methodData) {
            if (!isset($methodData['name']) && !isset($methodData['method'])) {
                continue;
            }
            $methodName = isset($methodData['name']) ? $methodData['name'] : $methodData['method'];
            $methodParams = $methodData['params'];

            // Create method parameter representation
            foreach ($methodParams as $key => $param) {
                if (null === $param || is_scalar($param) || is_array($param)) {
                    $string = var_export($param, 1);
                    if (strstr($string, '::__set_state(')) {
                        throw new Exception\RuntimeException('Arguments in definitions may not contain objects');
                    }
                    $methodParams[$key] = $string;
                } elseif ($param instanceof GeneratorInstance) {
                    $methodParams[$key] = sprintf("\$this->%s(\$params)", $this->normalizeAlias($param->getName()));
                } else {
                    $message = sprintf('Unable to use object arguments when generating method calls. Encountered with class "%s", method "%s", parameter of type "%s"', $classOrAlias, $methodName, get_class($param));
                    throw new Exception\RuntimeException($message);
                }
            }

            // Strip null arguments from the end of the params list
            $reverseParams = array_reverse($methodParams, true);
            foreach ($reverseParams as $key => $param) {
                if ('NULL' === $param) {
                    unset($methodParams[$key]);
                    continue;
                }
                break;
            }

            $methods .= sprintf("\$object->%s(%s);\n", $methodName, implode(', ', $methodParams));
        }
        return $methods;
    }

    /**
     * Create instantiation code
     *
     * @param $classOrAlias
     * @param GeneratorInstance $generatorInstance
     * @param string[] $params
     * @return string
     * @throws RuntimeException
     */
    protected function getCreationCode($classOrAlias, GeneratorInstance $generatorInstance, array &$params)
    {
        $constructor = $generatorInstance->getConstructor();
        if ('__construct' != $constructor) {
            // Constructor callback
            $callback = var_export($constructor, 1);
            if (strstr($callback, '::__set_state(')) {
                throw new RuntimeException('Unable to build containers that use callbacks requiring object instances');
            }
            if (count($params)) {
                $creation = sprintf('$object = call_user_func(%s, %s);', $callback, implode(', ', $params));
            } else {
                $creation = sprintf('$object = call_user_func(%s);', $callback);
            }
        } else {
            // Normal instantiation
            $className = '\\' . ltrim($classOrAlias, '\\');
            $creation = sprintf('$object = new %s(%s);', $className, implode(', ', $params));
        }

        return $creation;
    }

    /**
     * Build parameter list for instantiation
     *
     * @param string $classOrAlias
     * @param GeneratorInstance $generatorInstance
     * @param InstanceManager $im
     * @throws Exception\RuntimeException
     * @return string
     */
    protected function getParams($classOrAlias, GeneratorInstance $generatorInstance, InstanceManager $im)
    {
        $params = $generatorInstance->getParams();

        foreach ($params as $key => $param) {
            if ($param === self::PARAMS_ARRAY) {
                // Support for passing a $params array for instance creation
                $params[$key] = "\$params";
            } elseif (null === $param || is_scalar($param) || is_array($param)) {
                $string = var_export($param, 1);
                if (strstr($string, '::__set_state(')) {
                    throw new Exception\RuntimeException('Arguments in definitions may not contain objects');
                }
                $params[$key] = $string;
            } elseif ($param instanceof GeneratorInstance) {
                $params[$key] = sprintf("\$this->%s()", $this->normalizeAlias($param->getName()));
            } elseif (is_object($param)) {
                $objectClass = get_class($param);
                if ($im->hasSharedInstance($objectClass)) {
                    $params[$key] = sprintf("\$this->get('%s')", $objectClass);
                } else {
                    $message = sprintf('Unable to use object arguments when building containers. Encountered with "%s", parameter of type "%s"', $classOrAlias, get_class($param));
                    throw new RuntimeException($message);
                }
            }
        }

        // Strip null arguments from the end of the params list
        $reverseParams = array_reverse($params, true);
        foreach ($reverseParams as $key => $param) {
            if ('NULL' === $param) {
                unset($params[$key]);
                continue;
            }
            break;
        }

        return $params;
    }
}