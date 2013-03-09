_!!!Warning: this module is still in alpha stage, use at your own risk!!!_

# DiWrapper

Are you tired of writing tons of factory code (closures) for the ServiceManager in your Zend Framework 2 application? 
Are outdated factory methods causing bugs? This can all be avoided by using DiWrapper!

DiWrapper is a Zend Framework 2 module that uses auto-generated factory code for dependency-injection. 
It saves you a lot of work, since there's no need anymore for writing 
[Zend\ServiceManager](http://framework.zend.com/manual/2.1/en/modules/zend.service-manager.intro.html) 
factory closures and keeping them up-to-date manually.

DiWrapper scans your code using `Zend\Di` and creates factory methods automatically. If the factory methods are outdated, DiWrapper
updates them in the background. Therefore, you _develop faster_, _avoid bugs_ due to outdated factory methods, and 
experience _great performance_ in production!

## Features

- DI definition scanning and factory code generation
- Can deal with shared instances and type preferences
- Allows for custom code introspection strategies (by default, only constructors are scanned)
- Is automatically used as a fallback abstract factory for Zend\ServiceManager
- Can also be used as a full replacement for Zend\ServiceManager
- Detection of outdated generated code and automatic rescanning (great for development)
- Can create new instances or reuse instances created before
- Can be used as a factory for runtime objects combining DI and passing of runtime parameters. 

# Installation

This module is available on [Packagist](https://packagist.org/packages/aimfeld/di-wrapper).
In your project's `composer.json` use:

```
{   
    "require": {
        "aimfeld/di-wrapper": "0.1.*"
}
```
    
Make sure you have a _writable_ `data` folder in your application root directory, see 
[ZendSkeletonApplication](https://github.com/zendframework/ZendSkeletonApplication). Put a `.gitignore` file in it with 
the following content (you may want to replace `*` with `GeneratedServiceLocator.php`):

```
*
!.gitignore
```

Add 'DiWrapper' to the modules array in your `application.config.php`. DiWrapper must be the loaded _after_ the
modules where it is used:

```
'modules' => array(    	
    'SomeModule',
    'Application',
    'DiWrapper',
),
```

# Usage

DiWrapper uses standard [Zend\Di configuration](http://framework.zend.com/manual/2.1/en/modules/zend.di.configuration.html)
(which is not well documented yet). To make things easier, see [module.config.php](https://github.com/aimfeld/di-wrapper/blob/master/config/module.config.php) for 
examples of how to specify:

- Directories for the code scanner
- Instance configuration
- Type preferences

DiWrapper creates a `GeneratedServiceLocator` class in the `data` directory and automatically refreshes it when changed constructors cause
an exception. However, if you e.g. change parameters in the [di instance configuration](https://github.com/aimfeld/di-wrapper/blob/master/config/module.config.php),
you have to manually delete `data/GeneratedServiceLocator.php` to force a refresh. In your staging and production
deployment/update process, make sure that `data/GeneratedServiceLocator.php` is deleted!

## Shared instances

You need to provide shared instances to [DiWrapper::addSharedInstances()](https://github.com/aimfeld/di-wrapper/blob/master/src/DiWrapper/DiWrapper.php) in
your application module's onBootstrap() method in the following cases (also see example below):

- The object to be injected is an instance of a class outside of the [scanned directories](https://github.com/aimfeld/di-wrapper/blob/master/config/module.config.php)
- The object to be injected requires some special bootstrapping (e.g. a session object).

Note that DiWrapper by default provides some commonly used shared instances in ZF2 
(see [DiWrapper::getDefaultSharedInstances()](https://github.com/aimfeld/di-wrapper/blob/master/src/DiWrapper/DiWrapper.php)). 
Thee following default shared instances can be constructor-injected without explicitly adding them:

- DiWrapper\DiWrapper
- DiWrapper\DiFactory
- Zend\Config\Config
- Zend\Mvc\Router\Http\TreeRouteStack
- Zend\View\Renderer\PhpRenderer

# Examples

All examples sources listed here are included as [source code](https://github.com/aimfeld/di-wrapper/tree/master/src/DiWrapper/Example).

## Using DiWrapper to create a controller

Let's say we want to use DiWrapper to create a controller class and inject some 
dependencies. We also want to inject the DiWrapper itself into the controller, so we can use it to get 
dependencies from within the controller (it is a moot topic whether this is a good idea or not). 
We have the following classes:

ExampleController

```
use Zend\Mvc\Controller\AbstractActionController;
use DiWrapper\DiWrapper;
use Zend\Config\Config;

class ExampleController extends AbstractActionController
{
    public function __construct(DiWrapper $diWrapper, ServiceA $serviceA,
                                ServiceC $serviceC, Config $config)
    {
        $this->diWrapper = $diWrapper;
        $this->serviceA = $serviceA;
        $this->serviceC = $serviceC;
        $this->config = $config;
    }

    public function indexAction()
    {
        // Of course we could also constructor-inject ServiceD
        $serviceD = $this->diWrapper->get('DiWrapper\Example\ServiceD');
        $serviceD->serviceMethod();
    }
}

```

ServiceA with a dependency on ServiceB

```
class ServiceA
{
    public function __construct(ServiceB $serviceB)
    {
        $this->serviceB = $serviceB;
    }
}
```

ServiceB with a constructor parameter of unspecified type:

```
class ServiceB
{
    public function __construct($diParam)
    {
        $this->diParam = $diParam;
    }
}
```

ServiceC which requires complicated initialization and will be added as shared instance.

```
class ServiceC
{
    public function init(array $options = array())
    {
        // Some complicated bootstrapping here
    }
}
```
    
We add the example source directory as a scan directory for DiWrapper. Since `ServiceB` has a parameter of unspecified type, we
have to specify a value to inject. A better approach for `ServiceB` would be to require the `Config` in its constructor 
and retrieve the parameter from there, so we wouldn't need to specify a di instance configuration. The configuration for our example
looks like this
(also see [module.config.php](https://github.com/aimfeld/di-wrapper/blob/master/config/module.config.php)).

```
'di' => array(
    'scan_directories' => array(
        __DIR__ . '/../src/DiWrapper/Example',
    ),
    'instance' => array(
        'DiWrapper\Example\ServiceB' => array(
            'parameters' => array(
                'diParam' => 'Hello',
            ),
        ),
    ),            
),
```

Now we can create the `ExampleController` in our application's module class. Since the `ServiceC`
dependency requires some complicated initialization, we need to initialize it and add it as a shared instance to
DiWrapper.

```
namespace Application;

class Module
{    
    protected $diWrapper;
    
    public function getControllerConfig()
    {
        return array(
            'factories' => array(
                'Application\Controller\Example' => function() {
                    return $this->diWrapper->get('DiWrapper\Example\ExampleController');
                },                
            ),
        );
    }    

    public function onBootstrap(MvcEvent $mvcEvent)
    {
        $sm = $mvcEvent->getApplication()->getServiceManager();

        // Provide DiWrapper as a local variable for convience
        $this->diWrapper = $sm->get('di-wrapper');
        
        // Set up shared instance
        $serviceC = new ServiceC;
        $serviceC->init(array('some', 'crazy', 'options'));
        
        // Provide shared instance
        $this->diWrapper->addSharedInstances(array(
            'DiWrapper\Example\ServiceC' => $serviceC,
        ));
    }
}
```

DiWrapper will automatically generate a service locator in the `data` directory and update it if constructors are changed
during development. Services can be created/retrieved using `DiWrapper::get()`. If you need a new dependency in one of your
classes, you can just put it in the constructor and DiWrapper will inject it for you.

# The generated factory code behind the scenes

Just for illustration, this is the generated service locator created by DiWrapper and used in `DiWrapper::get()`. 

```
namespace DiWrapper;

use Zend\Di\ServiceLocator;

/**
 * Generated by DiWrapper\Generator (2013-03-07 21:11:39)
 */
class GeneratedServiceLocator extends ServiceLocator
{
    /**
     * @param string $name
     * @param array $params
     * @param bool $newInstance
     * @return mixed
     */
    public function get($name, array $params = array(), $newInstance = false)
    {
        if (!$newInstance && isset($this->services[$name])) {
            return $this->services[$name];
        }

        switch ($name) {
            case 'DiWrapper\Example\ExampleController':
                return $this->getDiWrapperExampleExampleController($params, $newInstance);

            case 'DiWrapper\Example\ExampleDiFactory':
                return $this->getDiWrapperExampleExampleDiFactory($params, $newInstance);

            case 'DiWrapper\Example\RuntimeA':
                return $this->getDiWrapperExampleRuntimeA($params, $newInstance);

            case 'DiWrapper\Example\ServiceA':
                return $this->getDiWrapperExampleServiceA($params, $newInstance);

            case 'DiWrapper\Example\ServiceB':
                return $this->getDiWrapperExampleServiceB($params, $newInstance);

            case 'DiWrapper\Example\ServiceC':
                return $this->getDiWrapperExampleServiceC($params, $newInstance);

            case 'DiWrapper\Example\ServiceD':
                return $this->getDiWrapperExampleServiceD($params, $newInstance);

            default:
                return parent::get($name, $params);
        }
    }

    /**
     * @param array $params
     * @param bool $newInstance
     * @return \DiWrapper\Example\ExampleController
     */
    public function getDiWrapperExampleExampleController(array $params = array(), $newInstance = false)
    {
        if (!$newInstance && isset($this->services['DiWrapper\Example\ExampleController'])) {
            return $this->services['DiWrapper\Example\ExampleController'];
        }

        $object = new \DiWrapper\Example\ExampleController($this->get('DiWrapper\DiWrapper'), $this->getDiWrapperExampleServiceA(), $this->getDiWrapperExampleServiceC(), $this->get('Zend\Config\Config'));
        if (!$newInstance) {
            $this->services['DiWrapper\Example\ExampleController'] = $object;
        }

        return $object;
    }

    /**
     * @param array $params
     * @param bool $newInstance
     * @return \DiWrapper\Example\ExampleDiFactory
     */
    public function getDiWrapperExampleExampleDiFactory(array $params = array(), $newInstance = false)
    {
        if (!$newInstance && isset($this->services['DiWrapper\Example\ExampleDiFactory'])) {
            return $this->services['DiWrapper\Example\ExampleDiFactory'];
        }

        $object = new \DiWrapper\Example\ExampleDiFactory($this->get('DiWrapper\DiWrapper'));
        if (!$newInstance) {
            $this->services['DiWrapper\Example\ExampleDiFactory'] = $object;
        }

        return $object;
    }

    /**
     * @param array $params
     * @param bool $newInstance
     * @return \DiWrapper\Example\RuntimeA
     */
    public function getDiWrapperExampleRuntimeA(array $params = array(), $newInstance = false)
    {
        if (!$newInstance && isset($this->services['DiWrapper\Example\RuntimeA'])) {
            return $this->services['DiWrapper\Example\RuntimeA'];
        }

        $object = new \DiWrapper\Example\RuntimeA($this->get('Zend\Config\Config'), $params);
        if (!$newInstance) {
            $this->services['DiWrapper\Example\RuntimeA'] = $object;
        }

        return $object;
    }

    /**
     * @param array $params
     * @param bool $newInstance
     * @return \DiWrapper\Example\ServiceA
     */
    public function getDiWrapperExampleServiceA(array $params = array(), $newInstance = false)
    {
        if (!$newInstance && isset($this->services['DiWrapper\Example\ServiceA'])) {
            return $this->services['DiWrapper\Example\ServiceA'];
        }

        $object = new \DiWrapper\Example\ServiceA($this->getDiWrapperExampleServiceB());
        if (!$newInstance) {
            $this->services['DiWrapper\Example\ServiceA'] = $object;
        }

        return $object;
    }

    /**
     * @param array $params
     * @param bool $newInstance
     * @return \DiWrapper\Example\ServiceB
     */
    public function getDiWrapperExampleServiceB(array $params = array(), $newInstance = false)
    {
        if (!$newInstance && isset($this->services['DiWrapper\Example\ServiceB'])) {
            return $this->services['DiWrapper\Example\ServiceB'];
        }

        $object = new \DiWrapper\Example\ServiceB('Hello');
        if (!$newInstance) {
            $this->services['DiWrapper\Example\ServiceB'] = $object;
        }

        return $object;
    }

    /**
     * @param array $params
     * @param bool $newInstance
     * @return \DiWrapper\Example\ServiceC
     */
    public function getDiWrapperExampleServiceC(array $params = array(), $newInstance = false)
    {
        if (!$newInstance && isset($this->services['DiWrapper\Example\ServiceC'])) {
            return $this->services['DiWrapper\Example\ServiceC'];
        }

        $object = new \DiWrapper\Example\ServiceC();
        if (!$newInstance) {
            $this->services['DiWrapper\Example\ServiceC'] = $object;
        }

        return $object;
    }

    /**
     * @param array $params
     * @param bool $newInstance
     * @return \DiWrapper\Example\ServiceD
     */
    public function getDiWrapperExampleServiceD(array $params = array(), $newInstance = false)
    {
        if (!$newInstance && isset($this->services['DiWrapper\Example\ServiceD'])) {
            return $this->services['DiWrapper\Example\ServiceD'];
        }

        $object = new \DiWrapper\Example\ServiceD($this->getDiWrapperExampleExampleDiFactory());
        if (!$newInstance) {
            $this->services['DiWrapper\Example\ServiceD'] = $object;
        }

        return $object;
    }
}
```

