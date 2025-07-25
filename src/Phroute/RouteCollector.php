<?php namespace Phroute\Phroute;

use ReflectionClass;
use ReflectionMethod;

use Phroute\Phroute\Exception\BadRouteException;

/**
 * Class RouteCollector
 * @package Phroute\Phroute
 */
class RouteCollector implements RouteDataProviderInterface {

    /**
     *
     */
    const DEFAULT_CONTROLLER_ROUTE = 'index';

    /**
     *
     */
    const APPROX_CHUNK_SIZE = 10;

    /**
     * @var RouteParser
     */
    private $routeParser;
    /**
     * @var array
     */
    private $filters = [];
    /**
     * @var array
     */
    private $staticRoutes = [];
    /**
     * @var array
     */
    private $regexToRoutesMap = [];
    /**
     * @var array
     */
    private $reverse = [];

    /**
     * @var array
     */
    private $globalFilters = [];

    /**
     * @var
     */
    private $globalRoutePrefix;

    private array $additionalInfo = [];

    /**
     * @param RouteParser $routeParser
     */
    public function __construct(RouteParser $routeParser = null) {
        $this->routeParser = $routeParser ?: new RouteParser();
    }

    /**
     * @param $name
     * @return bool
     */
    public function hasRoute($name) {
        return isset($this->reverse[$name]);
    }

    /**
     * @param $name
     * @param array $args
     * @return string
     */
    public function route($name, array $args = null)
    {
        $url = [];

        $replacements = is_null($args) ? [] : array_values($args);

        $variable = 0;

        foreach($this->reverse[$name] as $part)
        {
            if(!$part['variable'])
            {
                $url[] = $part['value'];
            }
            elseif(isset($replacements[$variable]))
            {
                if($part['optional'])
                {
                    $url[] = '/';
                }

                $url[] = $replacements[$variable++];
            }
            elseif(!$part['optional'])
            {
                throw new BadRouteException("Expecting route variable '{$part['name']}'");
            }
        }

        return implode('', $url);
    }

    /**
     * @param $httpMethod
     * @param $route
     * @param $handler
     * @param array $filters
     * @param array $additionalInfo
     * @return $this
     */
    public function addRoute($httpMethod, $route, $handler, array $filters = [], array $additionalInfo = []) {
        
        if(is_array($route))
        {
            list($route, $name) = $route;
        }

        $route = $this->addPrefix($this->trim($route));

        list($routeData, $reverseData) = $this->routeParser->parse($route);
        
        if(isset($name))
        {
            $this->reverse[$name] = $reverseData;
        }

        if(array_key_exists(Route::BEFORE, $filters)){
            $filters = [Route::BEFORE => [['name' => $filters[Route::BEFORE], 'params' =>  $filters['params']]]];
        }
        if(array_key_exists(Route::AFTER, $filters)){
            $filters = [Route::AFTER => [['name' => $filters[Route::AFTER], 'params' =>  $filters['params']]]];
        }
        
        $filters = array_merge_recursive($this->globalFilters, $filters);

        isset($routeData[1]) ? 
            $this->addVariableRoute($httpMethod, $routeData, $handler, $filters, $additionalInfo) :
            $this->addStaticRoute($httpMethod, $routeData, $handler, $filters, $additionalInfo);
        
        return $this;
    }

    /**
     * @param $httpMethod
     * @param $routeData
     * @param $handler
     * @param $filters
     * @param $additionalInfo
     */
    private function addStaticRoute($httpMethod, $routeData, $handler, $filters, $additionalInfo)
    {
        $routeStr = $routeData[0];

        if (isset($this->staticRoutes[$routeStr][$httpMethod]))
        {
            throw new BadRouteException("Cannot register two routes matching '$routeStr' for method '$httpMethod'");
        }

        foreach ($this->regexToRoutesMap as $regex => $routes) {
            if (isset($routes[$httpMethod]) && preg_match('~^' . $regex . '$~', $routeStr))
            {
                throw new BadRouteException("Static route '$routeStr' is shadowed by previously defined variable route '$regex' for method '$httpMethod'");
            }
        }

        $this->staticRoutes[$routeStr][$httpMethod] = array($handler, $filters, [], $additionalInfo);
    }

    /**
     * @param $httpMethod
     * @param $routeData
     * @param $handler
     * @param $filters
     * @param $additionalInfo
     */
    private function addVariableRoute($httpMethod, $routeData, $handler, $filters, $additionalInfo)
    {
        list($regex, $variables) = $routeData;

        if (isset($this->regexToRoutesMap[$regex][$httpMethod]))
        {
            throw new BadRouteException("Cannot register two routes matching '$regex' for method '$httpMethod'");
        }

        $this->regexToRoutesMap[$regex][$httpMethod] = [$handler, $filters, $variables, $additionalInfo];
    }

    /**
     * @param array $filters
     * @param \Closure $callback
     */
    public function group(array $filters, \Closure $callback)
    {
        if(array_key_exists(Route::BEFORE, $filters)){
            $filters = [Route::BEFORE => [['name' => $filters[Route::BEFORE], 'params' =>  isset($filters['params']) ? $filters['params'] : []]]];
        }
        if(array_key_exists(Route::AFTER, $filters)){
            $filters = [Route::AFTER => [['name' => $filters[Route::AFTER], 'params' =>  isset($filters['params']) ? $filters['params'] : []]]];
        }
        $oldGlobalFilters = $this->globalFilters;

        $oldGlobalPrefix = $this->globalRoutePrefix;

        $this->globalFilters = array_merge_recursive($this->globalFilters, array_intersect_key($filters, [Route::AFTER => 1, Route::BEFORE => 1]));

        $newPrefix = isset($filters[Route::PREFIX]) ? $this->trim($filters[Route::PREFIX]) : null;

        $this->globalRoutePrefix = $this->addPrefix($newPrefix);

        $callback($this);

        $this->globalFilters = $oldGlobalFilters;

        $this->globalRoutePrefix = $oldGlobalPrefix;
    }

    private function addPrefix($route)
    {
        return $this->trim($this->trim($this->globalRoutePrefix) . '/' . $route);
    }

    /**
     * @param $name
     * @param $handler
     */
    public function filter($name, $handler)
    {
        $this->filters[$name] = $handler;
    }

    /**
     * @param $route
     * @param $handler
     * @param array $filters
     * @param array $additionalInfo
     * @return RouteCollector
     */
    public function get($route, $handler, array $filters = [], array $additionalInfo = [])
    {
        return $this->addRoute(Route::GET, $route, $handler, $filters, $additionalInfo);
    }

    /**
     * @param $route
     * @param $handler
     * @param array $filters
     * @param array $additionalInfo
     * @return RouteCollector
     */
    public function head($route, $handler, array $filters = [], array $additionalInfo = [])
    {
        return $this->addRoute(Route::HEAD, $route, $handler, $filters, $additionalInfo);
    }

    /**
     * @param $route
     * @param $handler
     * @param array $filters
     * @param array $additionalInfo
     * @return RouteCollector
     */
    public function post($route, $handler, array $filters = [], array $additionalInfo = [])
    {
        return $this->addRoute(Route::POST, $route, $handler, $filters, $additionalInfo);
    }

    /**
     * @param $route
     * @param $handler
     * @param array $filters
     * @param array $additionalInfo
     * @return RouteCollector
     */
    public function put($route, $handler, array $filters = [], array $additionalInfo = [])
    {
        return $this->addRoute(Route::PUT, $route, $handler, $filters, $additionalInfo);
    }

    /**
     * @param $route
     * @param $handler
     * @param array $filters
     * @param array $additionalInfo
     * @return RouteCollector
     */
    public function patch($route, $handler, array $filters = [], array $additionalInfo = [])
    {
        return $this->addRoute(Route::PATCH, $route, $handler, $filters, $additionalInfo);
    }

    /**
     * @param $route
     * @param $handler
     * @param array $filters
     * @param array $additionalInfo
     * @return RouteCollector
     */
    public function delete($route, $handler, array $filters = [], array $additionalInfo = [])
    {
        return $this->addRoute(Route::DELETE, $route, $handler, $filters, $additionalInfo);
    }

    /**
     * @param $route
     * @param $handler
     * @param array $filters
     * @param array $additionalInfo
     * @return RouteCollector
     */
    public function options($route, $handler, array $filters = [], array $additionalInfo = [])
    {
        return $this->addRoute(Route::OPTIONS, $route, $handler, $filters, $additionalInfo);
    }

    /**
     * @param $route
     * @param $handler
     * @param array $filters
     * @param array $additionalInfo
     * @return RouteCollector
     */
    public function any($route, $handler, array $filters = [], array $additionalInfo = [])
    {
        return $this->addRoute(Route::ANY, $route, $handler, $filters, $additionalInfo);
    }

    /**
     * @param $route
     * @param $classname
     * @param array $filters
     * @param array $additionalInfo
     * @return $this
     */
    public function controller($route, $classname, array $filters = [], array $additionalInfo = [])
    {
        $reflection = new ReflectionClass($classname);

        $validMethods = $this->getValidMethods();

        $sep = $route === '/' ? '' : '/';

        foreach($reflection->getMethods(ReflectionMethod::IS_PUBLIC) as $method)
        {
            foreach($validMethods as $valid)
            {
                if(stripos($method->name, $valid) === 0)
                {
                    $methodName = $this->camelCaseToDashed(substr($method->name, strlen($valid)));

                    $params = $this->buildControllerParameters($method);

                    if($methodName === self::DEFAULT_CONTROLLER_ROUTE)
                    {
                        $this->addRoute($valid, $route . $params, [$classname, $method->name], $filters, $additionalInfo);
                    }

                    $this->addRoute($valid, $route . $sep . $methodName . $params, [$classname, $method->name], $filters, $additionalInfo);
                    
                    break;
                }
            }
        }
        
        return $this;
    }

    /**
     * @param ReflectionMethod $method
     * @return string
     */
    private function buildControllerParameters(ReflectionMethod $method)
    {
        $params = '';

        foreach($method->getParameters() as $param)
        {
            $params .= "/{" . $param->getName() . "}" . ($param->isOptional() ? '?' : '');
        }

        return $params;
    }

    /**
     * @param $string
     * @return string
     */
    private function camelCaseToDashed($string)
    {
        return strtolower(preg_replace('/([A-Z])/', '-$1', lcfirst($string)));
    }

    /**
     * @return array
     */
    public function getValidMethods()
    {
        return [
            Route::ANY,
            Route::GET,
            Route::POST,
            Route::PUT,
            Route::PATCH,
            Route::DELETE,
            Route::HEAD,
            Route::OPTIONS,
        ];
    }

    /**
     * @return RouteDataArray
     */
    public function getData()
    {
        if (empty($this->regexToRoutesMap))
        {
            return new RouteDataArray($this->staticRoutes, [], $this->filters);
        }

        return new RouteDataArray($this->staticRoutes, $this->generateVariableRouteData(), $this->filters);
    }

    /**
     * @param $route
     * @return string
     */
    private function trim($route)
    {
        if(is_null($route)) {
            return null;
        }
        return trim($route, '/');
    }

    /**
     * @return array
     */
    private function generateVariableRouteData()
    {
        $chunkSize = $this->computeChunkSize(count($this->regexToRoutesMap));
        $chunks = array_chunk($this->regexToRoutesMap, $chunkSize, true);
        return array_map([$this, 'processChunk'], $chunks);
    }

    /**
     * @param $count
     * @return float
     */
    private function computeChunkSize($count)
    {
        $numParts = max(1, round($count / self::APPROX_CHUNK_SIZE));
        return ceil($count / $numParts);
    }

    /**
     * @param $regexToRoutesMap
     * @return array
     */
    private function processChunk($regexToRoutesMap)
    {
        $routeMap = [];
        $regexes = [];
        $numGroups = 0;
        foreach ($regexToRoutesMap as $regex => $routes) {
            $firstRoute = reset($routes);
            $numVariables = count($firstRoute[2]);
            $numGroups = max($numGroups, $numVariables);

            $regexes[] = $regex . str_repeat('()', $numGroups - $numVariables);

            foreach ($routes as $httpMethod => $route) {
                $routeMap[$numGroups + 1][$httpMethod] = $route;
            }

            $numGroups++;
        }

        $regex = '~^(?|' . implode('|', $regexes) . ')$~';
        return ['regex' => $regex, 'routeMap' => $routeMap];
    }
}
