<?php

namespace CrazyGoat\Router\Dispatcher;

use CrazyGoat\Router\BadRouteException;
use CrazyGoat\Router\Dispatcher;
use CrazyGoat\Router\Route;
use CrazyGoat\Router\RouteGenerator;
use CrazyGoat\Router\RouteParser\Std;

abstract class RegexBasedAbstract implements Dispatcher, RouteGenerator
{
    /** @var mixed[][] */
    protected $staticRouteMap = [];

    /** @var mixed[] */
    protected $variableRouteData = [];

    /**
     * @var array
     */
    protected $namedRoutes = [];

    /**
     * @return mixed[]
     */
    abstract protected function dispatchVariableRoute($routeData, $uri);

    public function dispatch($httpMethod, $uri)
    {
        if (isset($this->staticRouteMap[$httpMethod][$uri])) {
            list($handler, $middleware) = $this->staticRouteMap[$httpMethod][$uri];
            return [self::FOUND, $handler, [], $middleware];
        }

        $varRouteData = $this->variableRouteData;
        if (isset($varRouteData[$httpMethod])) {
            $result = $this->dispatchVariableRoute($varRouteData[$httpMethod], $uri);
            if ($result[0] === self::FOUND) {
                return $result;
            }
        }

        // For HEAD requests, attempt fallback to GET
        if ($httpMethod === 'HEAD') {
            if (isset($this->staticRouteMap['GET'][$uri])) {
                list($handler, $middleware) = $this->staticRouteMap['GET'][$uri];
                return [self::FOUND, $handler, [], $middleware];
            }
            if (isset($varRouteData['GET'])) {
                $result = $this->dispatchVariableRoute($varRouteData['GET'], $uri);
                if ($result[0] === self::FOUND) {
                    return $result;
                }
            }
        }

        // If nothing else matches, try fallback routes
        if (isset($this->staticRouteMap['*'][$uri])) {
            list($handler, $middleware) = $this->staticRouteMap['*'][$uri];
            return [self::FOUND, $handler, [], $middleware];
        }
        if (isset($varRouteData['*'])) {
            $result = $this->dispatchVariableRoute($varRouteData['*'], $uri);
            if ($result[0] === self::FOUND) {
                return $result;
            }
        }

        // Find allowed methods for this URI by matching against all other HTTP methods as well
        $allowedMethods = [];

        foreach ($this->staticRouteMap as $method => $uriMap) {
            if ($method !== $httpMethod && isset($uriMap[$uri])) {
                $allowedMethods[] = $method;
            }
        }

        foreach ($varRouteData as $method => $routeData) {
            if ($method === $httpMethod) {
                continue;
            }

            $result = $this->dispatchVariableRoute($routeData, $uri);
            if ($result[0] === self::FOUND) {
                $allowedMethods[] = $method;
            }
        }

        // If there are no allowed methods the route simply does not exist
        if ($allowedMethods) {
            return [self::METHOD_NOT_ALLOWED, $allowedMethods];
        }

        return [self::NOT_FOUND];
    }

    /**
     * @param string $name
     * @param array $params
     * @return mixed|string
     * @throws \Exception
     */
    public function pathFor($name, $params = [])
    {
        if (isset($this->namedRoutes[$name])) {
            $route = $this->namedRoutes[$name];
            if (is_array($route)) {
                $lastException = null;
                foreach (array_reverse($route) as $routeOption) {
                    try {
                        return $this->produceVariable($routeOption, $params);
                    } catch (BadRouteException $exception) {
                        $lastException = $exception;
                    }
                }

                if ($lastException) {
                    throw $lastException;
                }
            } else if (is_string($route)) {
                return $route;
            }
        }

        throw new BadRouteException('No route found with name:'.$name);
    }

    /**
     * @param array $route
     * @param array $params
     * @return string
     */
    private function produceVariable($route, $params)
    {
        $path = [];

        foreach ($route as $segment) {
            if (is_string($segment)) {
                $path[] = $segment;
            } else if (is_array($segment)) {
                if (array_key_exists($segment[0], $params)) {
                    $path[] = $params[$segment[0]];
                } else {
                    throw new BadRouteException('Missing route parameter "'.$segment[0].'"');
                }
            }
        }
        return implode('',$path);
    }
}
