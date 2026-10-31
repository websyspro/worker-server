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
    $key = sprintf( "%s %s/%s", 
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
    foreach( $modules as $strClass ){
      $refClass = new ReflectionClass( $strClass );
      // $refClassAttrs = $refClass->getAttributes( 
      //   Module::class
      // )[0] ?? null;

      // if( $moduleAttr === null ){
      //   continue;
      // }

      $module = Module::getInstance( $refClass );
      $prefix = Module::getPrefix( $refClass );

      if( $module instanceof Module ){
        // Passagem 1 — cria/sincroniza tabelas sem FKs
        // $schema = SchemaManager::create();
        foreach( $module->entities as $entityClass ){
          // $schema->syncTable($entityClass);
        }

        // Passagem 2 — aplica FKs após todas as tabelas existirem
        foreach( $module->entities as $entityClass ){
          // $schema->applyForeignKeys($entityClass);
        }

        foreach( $module->controllers as $class ){
          $this->register( $class, $prefix );
        }        
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
    string $class, 
    string|null $prefix = null,
    array $controllerPaths = [],
    array $controllerMiddlewares = []
  ): WorkerServer {
    $refClass = new ReflectionClass( $class );
    
    $controller = Controller::getInstance( $refClass );
    $controllerPrefix = Controller::getPrefix( $controller );
    $controllerMiddlewares = Controller::getMiddlewaresFromController( $refClass );
    $controllerMiddlewaresIsAuthenticate = Controller::getMiddlewaresIsAuthenticate( $refClass );

    // add paths in controllerPaths
    $controllerPaths[] = $prefix;
    $controllerPaths[] = $controllerPrefix;

    $instance = Container::make($class);
    $httpAttrs = [Get::class, Post::class, Put::class, Patch::class, Delete::class];

    Logger::info("{$refClass->getShortName()}");
    
    foreach ( Controller::getMethodsPublics( $refClass ) as $methodPublic ) {
      $middlewareIsAllowAnonymous = Controller::getMiddlewaresIsAllowAnonymous( $methodPublic );

      $controllerMiddlewares = [
        ...$controllerMiddlewares,
        ...Controller::getMiddlewaresFromMethod( $methodPublic )
      ];

      // $methodMiddlewares = [];
      // foreach ($methodPublic->getAttributes() as $attribute) {
      //   $attrClass = $attribute->getName();
      //   if (is_subclass_of($attrClass, Middleware::class)) {
      //     $methodMiddlewares[] = $attrClass;
      //   }
      // }

      // $controllerMiddlewares = [
      //   ...$controllerMiddlewares, 
      //   ...$methodMiddlewares
      // ];

      if( $middlewareIsAllowAnonymous ){
        $controllerMiddlewares = array_filter($controllerMiddlewares, function($mw) {
          return $mw !== Authenticate::class;
        });
      }

      foreach( Controller::getRequestTypes() as $requestTypes ){
        $attrs = $methodPublic->getAttributes( $requestTypes );
        if( empty( $attrs )){
          continue;
        }



        $subPath = $attrs[0]->newInstance()->path;
        $httpMethod = strtoupper((new ReflectionClass($requestTypes))->getShortName());
        $controllerPaths[] = trim( $subPath, "/" );

        $handler = Closure::fromCallable([$instance, $methodPublic->getName()]);

        $metadata = [
          'requiresAuth' => $controllerMiddlewaresIsAuthenticate,
          'allowAnonymous' => $middlewareIsAllowAnonymous,
          'middlewares' => array_values( $controllerMiddlewares )
        ];

        $this->registerRouter( 
          $httpMethod, 
          Controller::getJoinPahs( $controllerPaths ), 
          $handler, 
          $metadata
        );
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
