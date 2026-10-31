<?php

namespace Websyspro\WorkerServer;

use InvalidArgumentException;
use Websyspro\WorkerServer\Enums\RequestMethod;
use Websyspro\WorkerServer\Request;
use Websyspro\WorkerServer\Container;
use Websyspro\WorkerServer\Model;
use Websyspro\WorkerServer\Decorators\Controller;
use Websyspro\WorkerServer\Decorators\Module;
use Websyspro\WorkerServer\Decorators\Get;
use Websyspro\WorkerServer\Decorators\Post;
use Websyspro\WorkerServer\Decorators\Put;
use Websyspro\WorkerServer\Decorators\Patch;
use Websyspro\WorkerServer\Decorators\Delete;
use Websyspro\WorkerServer\Decorators\Requests\Body;
use Websyspro\WorkerServer\Decorators\Requests\Query;
use Websyspro\WorkerServer\Decorators\Requests\Param;
use Websyspro\WorkerServer\Decorators\Requests\File;
use Websyspro\WorkerServer\Decorators\Middlewares\Authenticate;
use Websyspro\WorkerServer\Decorators\Middlewares\AllowAnonymous;
use Websyspro\WorkerServer\Interfaces\IAuthenticationHandler;
use Websyspro\WorkerServer\Middleware;
use function strtoupper;
use function explode;
use function preg_match;
use function preg_replace;
use function array_keys;
use function array_combine;
use function array_slice;
use function sprintf;
use function is_array;
use function is_string;
use ErrorException;
use ReflectionClass;
use ReflectionFunction;
use ReflectionMethod;
use ReflectionNamedType;
use Throwable;
use Closure;

class WorkerServer 
extends AbstractWorkerServer
{
  private array $routers = [];
  private string $prefix;
  private IAuthenticationHandler|null $authHandler;
  private array $globalMiddlewares = [];

  public function __construct(
    IAuthenticationHandler|null $authHandler = null
  ){
    parent::__construct();
    $this->prefix = sprintf(
      "/v%s", $this->apiVersion
    );
    $this->authHandler = $authHandler;
  }

  public function setAuthenticationHandler(
    IAuthenticationHandler $handler
  ): WorkerServer {
    $this->authHandler = $handler;
    return $this;
  }

  public function use(
    mixed ...$middlewares
  ): WorkerServer {
    foreach ( $middlewares as $middlewareClass ) {
      if (!is_string( $middlewareClass ) || !is_subclass_of( $middlewareClass, Middleware::class )) {
        throw new InvalidArgumentException(
          "Cada middleware deve ser uma string de classe que estende Middleware"
        );
      }

      $this->globalMiddlewares[] = $middlewareClass;
      Logger::info( "Global Middleware -> " . (new ReflectionClass($middlewareClass))->getShortName());
    }
    
    return $this;
  }

  private function registerRouter(
    string $method, string $path, Closure $handler, array $metadata = []
  ): WorkerServer {
    $key = sprintf( "%s %s%s", 
      strtoupper( $method ), $this->prefix, $path
    );
    
    $this->routers[ $key ] = [
      'handler' => $handler,
      'requiresAuth' => $metadata['requiresAuth'] ?? false,
      'allowAnonymous' => $metadata['allowAnonymous'] ?? false,
      'middlewares' => $metadata['middlewares'] ?? [],
    ];
    
    return $this;
  }

  private function matchRoute(
    string $method, 
    string $requestPath,
    array $paramNames = []
  ): array|null {
    foreach ($this->routers as $key => $routeData) {
      [ $routeMethod, $routePath ] = explode(
        " ", $key, 2
      );

      if( $routeMethod !== $method ){
        continue;
      }

      preg_match_all( "#:([a-zA-Z_]+)#", $routePath, $matches );
      $paramNames = $matches[1];

      $pattern = preg_replace( "#:([a-zA-Z_]+)#", '([^/]+)', $routePath);
      $pattern = '#^' . $pattern . '$#';

      if( !preg_match( $pattern, $requestPath, $values )){
        continue;
      }

      $params = array_combine( $paramNames, array_slice( $values, 1 )) ?: [];
      
      return [ 
        $routeData['handler'], 
        $params,
        $routeData['requiresAuth'],
        $routeData['allowAnonymous'],
        $routeData['middlewares']
      ];
    }

    return null;
  }

  public function get(
    string $path, 
    Closure $handler
  ): WorkerServer {
    return $this->registerRouter(
      RequestMethod::GET->name, $path, $handler
    );
  }

  public function post(
    string $path, 
    Closure $handler
  ): WorkerServer {
    return $this->registerRouter(
      RequestMethod::POST->name, $path, $handler
    );
  }

  public function put(
    string $path, 
    Closure $handler
  ): WorkerServer {
    return $this->registerRouter(
      RequestMethod::PUT->name, $path, $handler
    );
  }

  public function patch(
    string $path,
    Closure $handler
  ): WorkerServer {
    return $this->registerRouter(
      RequestMethod::PATCH->name, $path, $handler
    );
  }

  public function delete(
    string $path,
    Closure $handler
  ): WorkerServer {
    return $this->registerRouter(
      RequestMethod::DELETE->name, $path, $handler
    );
  }

  public function registerModules(
    mixed ...$modules
  ): WorkerServer {
    foreach( $modules as $moduleClass ){
      $reflection = new ReflectionClass($moduleClass);
      $moduleAttr = $reflection->getAttributes( 
        Module::class
      )[0] ?? null;

      if( $moduleAttr === null ){
        continue;
      }

      $module = $moduleAttr->newInstance();
      $modulePrefix = $module->name !== "" 
        ? "/" . trim( $module->name, "/" ) 
        : "";

      // Passagem 1 — cria/sincroniza tabelas sem FKs
      // $schema = SchemaManager::create();
      foreach( $module->entities as $entityClass ){
        // $schema->syncTable($entityClass);
      }

      // Passagem 2 — aplica FKs após todas as tabelas existirem
      foreach( $module->entities as $entityClass ){
        // $schema->applyForeignKeys($entityClass);
      }

      foreach( $module->controllers as $controller ){
        $this->register( $controller, $modulePrefix );
      }      
    }

    return $this;
  }

  public function registerControllers(
    mixed ...$controllers
  ): WorkerServer {
    foreach( $controllers as $controllerClass ){
      $this->register( $controllerClass );
    }

    return $this;
  }

  public function register(
    string $controllerClass, 
    string|null $modulePrefix = null
  ): WorkerServer {
    $reflection = new ReflectionClass( $controllerClass );
    $controllerAttr = $reflection->getAttributes(
      Controller::class
    )[0] ?? null;

    if( $controllerAttr === null ){
      return $this;
    }

    $classMiddlewares = [];
    foreach ($reflection->getAttributes() as $attribute) {
      $attrClass = $attribute->getName();
      if (is_subclass_of($attrClass, Middleware::class)) {
        $classMiddlewares[] = $attrClass;
      }
    }

    $authenticateAttr = $reflection->getAttributes(Authenticate::class)[0] ?? null;
    $requiresAuth = $authenticateAttr !== null;

    $controllerPath = '/' . trim($controllerAttr->newInstance()->prefix, '/');
    $basePath = $modulePrefix . $controllerPath;
    $instance = Container::make($controllerClass);
    $httpAttrs = [Get::class, Post::class, Put::class, Patch::class, Delete::class];

    Logger::info("{$reflection->getShortName()}");
    
    foreach ( $reflection->getMethods( ReflectionMethod::IS_PUBLIC ) as $method ) {
      $allowAnonymousAttr = $method->getAttributes(AllowAnonymous::class)[0] ?? null;
      $allowAnonymous = $allowAnonymousAttr !== null;

      $methodMiddlewares = [];
      foreach ($method->getAttributes() as $attribute) {
        $attrClass = $attribute->getName();
        if (is_subclass_of($attrClass, Middleware::class)) {
          $methodMiddlewares[] = $attrClass;
        }
      }

      $middlewares = [
        ...$classMiddlewares, 
        ...$methodMiddlewares
      ];

      if ($allowAnonymous) {
        $middlewares = array_filter($middlewares, function($mw) {
          return $mw !== Authenticate::class;
        });
      }

      foreach ($httpAttrs as $attrClass) {
        $attrs = $method->getAttributes($attrClass);
        if( empty( $attrs )){
          continue;
        }

        $subPath = $attrs[0]->newInstance()->path;
        $httpMethod = strtoupper((new ReflectionClass($attrClass))->getShortName());
        $fullPath = $basePath . ($subPath === '/' ? '' : $subPath);
        $handler = Closure::fromCallable([$instance, $method->getName()]);

        $metadata = [
          'requiresAuth' => $requiresAuth,
          'allowAnonymous' => $allowAnonymous,
          'middlewares' => array_values( $middlewares )
        ];

        $this->registerRouter( 
          $httpMethod, 
          $fullPath, 
          $handler, 
          $metadata
        );
        
        Logger::info("Mapper {{$httpMethod} {$fullPath}}");
      }
    }

    return $this;
  }


  public function getRoutes(
  ): array {
    return array_keys(
      $this->routers
    );
  }

  private function resolveArgs(
    Closure $handler, 
    Request $request,
    array $args = []
  ): array {
    $reflection = new ReflectionFunction( $handler );

    foreach( $reflection->getParameters() as $param ){
      $type = $param->getType();

      if( $type instanceof ReflectionNamedType && $type->getName() === Request::class ){
        $args[] = $request;
        continue;
      }

      if( $type instanceof ReflectionNamedType && $type->getName() === Response::class ){
        $args[] = new Response();
        continue;
      }

      $sourceMap = [
        Query::class => $request->query,
        Param::class => $request->params,
        Body::class => $request->body,
        File::class => $request->files,
      ];

      $resolved = false;
      foreach( $sourceMap as $attrClass => $source ){
        $attrs = $param->getAttributes($attrClass);
        if( empty( $attrs )){
          continue;
        }

        $key = $attrs[0]->newInstance()->key;
        $modelClass = $type instanceof ReflectionNamedType ? $type->getName() : null;

        if( $key !== "" ){
          $value = is_array($source) ? ($source[$key] ?? null) : (is_object($source) ? ($source->$key ?? null) : null);
          $args[] = $value;
        } elseif ( $modelClass && is_subclass_of( $modelClass, Model::class )){
          $args[] = $modelClass::from($source);
        } else {
          $args[] = $source;
        }

        $resolved = true;
        break;
      }

      if( !$resolved ){
        $args[] = null;
      }
    }

    return $args;
  }

  private function executeMiddlewareStack(
    array $middlewares,
    Request $request,
    Closure $finalHandler
  ): Response {
    if (empty($middlewares)) {
      return $finalHandler($request);
    }

    $next = $finalHandler;
    
    foreach (array_reverse($middlewares) as $middlewareClass) {
      $currentNext = $next;
      $middlewareInstance = Container::make($middlewareClass);
      
      $next = function(Request $req) use ($middlewareInstance, $currentNext) {
        $result = $middlewareInstance->execute($req, $currentNext);
        return $result ?? $currentNext($req);
      };
    }

    return $next($request);
  }

  protected function handleRequest(
    Request $request
  ): Response {
    try {
      $match = $this->matchRoute(
        $request->method,
        $request->path
      );

      if( $match === null ){
        return Response::json(
          "404 - {$request->method} {$request->path} nao encontrado", 404
        );
      }

      [ $handler, $params, $_, $_, $middlewares ] = $match;

      $allMiddlewares = array_merge($this->globalMiddlewares, $middlewares);

      $finalHandler = function(Request $req) use ($handler, $params) {
        $result = $handler( 
          ...$this->resolveArgs( $handler, $req->withParams( $params ))
        );

        if( $result instanceof Response ){
          return $result;
        }

        return Response::json( $result );
      };

      return $this->executeMiddlewareStack($allMiddlewares, $request, $finalHandler);
    } catch( ErrorException $error) {
      Logger::error( sprintf(
        "%s in %s:%d",
        $error->getMessage(),
        $error->getFile(),
        $error->getLine()
      ));

      return Response::json([ 
        "message" => "Internal Server Error"
      ], 500 );
    } catch( Throwable $error ) {
      Logger::error( sprintf(
        "[%s] %s in %s:%d",
        get_class( $error ),
        $error->getMessage(),
        $error->getFile(),
        $error->getLine()
      ));

      return Response::json([ 
        "message" => "Internal Server Error"
      ], 500 );
    }
  }
}
