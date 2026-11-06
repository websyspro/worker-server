<?php

namespace Websyspro\WorkerServer;

use InvalidArgumentException;
use stdClass;
use Websyspro\Connection\Database;
use Websyspro\Connection\Enums\DriverType;
use Websyspro\Entity\Interfaces\CommandScript;
use Websyspro\Entity\Schemas\AbstractSchemaManager;
use Websyspro\Entity\Schemas\MySqlEntityStructure;
use Websyspro\Entity\Schemas\MySqlEntityStructurePersisteds;
use Websyspro\Entity\Schemas\MySqlSchemaManager;
use Websyspro\Entity\Schemas\PostgresEntityStructure;
use Websyspro\Entity\Schemas\PostgresEntityStructurePersisteds;
use Websyspro\Entity\Schemas\PostgresSchemaManager;
use Websyspro\Entity\Schemas\SqLiteEntityStructure;
use Websyspro\Entity\Schemas\SqLiteEntityStructurePersisteds;
use Websyspro\Entity\Schemas\SqLiteSchemaManager;
use Websyspro\Entity\Schemas\SqlServerEntityStructure;
use Websyspro\Entity\Schemas\SqlServerEntityStructurePersisteds;
use Websyspro\Entity\Schemas\SqlServerSchemaManager;
use Websyspro\Utils\Collection;
use Websyspro\WorkerServer\Enums\LogContext;
use Websyspro\WorkerServer\Enums\RequestMethod;
use Websyspro\WorkerServer\Request;
use Websyspro\WorkerServer\Container;
use Websyspro\WorkerServer\Model;
use Websyspro\WorkerServer\Decorators\Controller;
use Websyspro\WorkerServer\Decorators\Module;
use Websyspro\WorkerServer\Decorators\Requests\Body;
use Websyspro\WorkerServer\Decorators\Requests\Query;
use Websyspro\WorkerServer\Decorators\Requests\Param;
use Websyspro\WorkerServer\Decorators\Requests\File;
use Websyspro\WorkerServer\Middleware;
use function explode;
use function preg_match;
use function preg_replace;
use function array_keys;
use function array_combine;
use function array_slice;
use function sprintf;
use function is_array;
use function is_string;
use function sizeof;
use ErrorException;
use ReflectionClass;
use ReflectionFunction;
use ReflectionNamedType;
use Throwable;
use Closure;

class WorkerServer 
extends AbstractWorkerServer
{
  private string $prefix;
  private array $routers = [];
  private array $middlewares = [];

  public function __construct(
  ){
    parent::__construct();
    $this->prefix = sprintf(
      "/api/v%s", $this->apiVersion
    );
  }

  public function use(
    mixed ...$middlewares
  ): WorkerServer {
    foreach ( $middlewares as $middleware ) {
      if (!is_string( $middleware ) || !is_subclass_of( $middleware, Middleware::class )) {
        throw new InvalidArgumentException(
          "Cada middleware deve ser uma string de classe que estende Middleware"
        );
      }

      $this->middlewares[] = $middleware;
      Logger::info( "Global Middleware -> " . (new ReflectionClass($middleware))->getShortName(), LogContext::Middleware);
    }
    
    return $this;
  }

  private function registerRouter(
    string $method, 
    string $path, 
    array $handler, 
    array $middlewares = []
  ): WorkerServer {
    $key = empty( $path ) || $path === "/"
      ? sprintf( "%s %s", $method, $this->prefix )
      : sprintf( "%s %s/%s", $method, $this->prefix, trim( $path, "/" ) );

    $this->routers[ $key ] = [
      $handler, $middlewares,
    ];

    Logger::info( "Mapper {{$this->prefix}/{$path}, {$method}} route", LogContext::Route );
    return $this;
  }

  private function matchRoute(
    string $method, 
    string $requestPath,
    array $paramNames = []
  ): array|null {
    foreach( $this->routers as $key => $router ){
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

      $params = array_combine(
        $paramNames, array_slice( $values, 1 )
      ) ?: [];

      [ $handler, $middlewares ] = $router;      
      return [ $handler, $params, $middlewares ];
    }

    return null;
  }

  public function get(
    string $path, 
    Closure $handler
  ): WorkerServer {
    return $this->registerRouter(
      RequestMethod::GET->name, $path, [ $handler ]
    );
  }

  public function post(
    string $path, 
    Closure $handler
  ): WorkerServer {
    return $this->registerRouter(
      RequestMethod::POST->name, $path, [ $handler ]
    );
  }

  public function put(
    string $path, 
    Closure $handler
  ): WorkerServer {
    return $this->registerRouter(
      RequestMethod::PUT->name, $path, [ $handler ]
    );
  }

  public function patch(
    string $path,
    Closure $handler
  ): WorkerServer {
    return $this->registerRouter(
      RequestMethod::PATCH->name, $path, [ $handler ]
    );
  }

  public function delete(
    string $path,
    Closure $handler
  ): WorkerServer {
    return $this->registerRouter(
      RequestMethod::DELETE->name, $path, [ $handler ]
    );
  }

  private function schemaManager(
  ReflectionClass $rf
): AbstractSchemaManager {
  return match( Database::driver() ){
    DriverType::MySql => new MySqlSchemaManager( 
      new MySqlEntityStructure($rf), new MySqlEntityStructurePersisteds($rf)
    ),
    DriverType::Postgres => new PostgresSchemaManager(
      new PostgresEntityStructure($rf), new PostgresEntityStructurePersisteds($rf)
    ),
    DriverType::SqlServer => new SqlServerSchemaManager(
      new SqlServerEntityStructure($rf), new SqlServerEntityStructurePersisteds($rf)
    ),
    DriverType::SqLite => new SqLiteSchemaManager( 
      new SqLiteEntityStructure($rf), new SqLiteEntityStructurePersisteds($rf)
    )
  };
}

  public function registerModules(
    mixed ...$modules
  ): WorkerServer {
    foreach( $modules as $strClass ){
      $refClass = new ReflectionClass( $strClass );
      $module = Module::getInstance( $refClass );
      $prefix = Module::getPrefix( $refClass );

      if( $module instanceof Module ){
        $schemaManageScripts = [];

        foreach( $module->entities as $entityClass ){
          $schemaManage = $this->schemaManager( 
            new ReflectionClass( 
              $entityClass
            )
          );

          $schemaManage->asyncEntity();
          $schemaManageScripts = [
            ...$schemaManageScripts,
            ...$schemaManage->commandScritps
          ];
        }

        foreach( $module->entities as $schemaManage ){
          $schemaManage = $this->schemaManager( 
            new ReflectionClass( 
              $entityClass
            )
          );

          $schemaManage->asyncConstraint();
          $schemaManageScripts = [
            ...$schemaManageScripts,
            ...$schemaManage->commandScritps
          ];
        }

        foreach( $schemaManageScripts as $commandScript ){
          if( $commandScript instanceof CommandScript ){
            Logger::info( $commandScript->message, LogContext::Database );
          }
        }        

        Logger::info( "Mapper Module {$refClass->getShortName()}", LogContext::Module );
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



    Logger::info("{$refClass->getShortName()} {{$this->prefix}/{$prefix}/{$controllerPrefix}}", LogContext::Controller);
    
    foreach( Controller::getMethodsPublics( $refClass ) as $refMethod ) {
      $controllerPaths[] = $prefix;
      $controllerPaths[] = $controllerPrefix;

      foreach( Controller::getMiddlewaresFromMethod( $refMethod ) as $methodMiddleware ){
        $controllerMiddlewares[] = $methodMiddleware;  
      }

      foreach( Controller::getRequestTypes() as $requestType ){
        $methdoPublicInstance = Controller::getMethodInstance(
          $refMethod, $requestType
        );

        if( $methdoPublicInstance ){
          $controllerPaths[] = Controller::getMethodPath(
            $methdoPublicInstance
          );

          $this->registerRouter( 
            Controller::getRequestTypeName( $requestType ), 
            Controller::getJoinPahs( $controllerPaths ), 
            Controller::getHandler( $refClass, $refMethod ), 
            Controller::getMiddlewares( $refMethod, $controllerMiddlewares )
          );
          
        }
      }

      // clear ControllerPaths after processing all request types for this method
      $controllerPaths = [];
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

  private function addMiddlewares(
    array $middlewares
  ): array {
    return [ ...$this->middlewares, ...$middlewares ];
  }

  private function executeMiddlewareStack(
    array $middlewares,
    Request $request,
    Closure $nextHandler
  ): Response {
    if( empty( $middlewares )){
      return $nextHandler( $request );
    }

    $next = $nextHandler;
    foreach( $this->addMiddlewares( $middlewares ) as $middleware ){
      $currentNext = $next;
      $middlewareInstance = Container::make( $middleware );

      $next = fn( Request $request ) => (
        $middlewareInstance->execute( $request, $currentNext )
          ?? $currentNext( $request )
      );
    }

    return $next( $request );
  }

  private function resultNormalized(
    mixed $value
  ): mixed {
    if( $value instanceof Collection ){
      return array_map(
        fn( $item ) => (
          $this->resultNormalized(
            $item
          )
        ), $value->items
      );
    }

    if( $value instanceof stdClass ){
      $normalized = new stdClass();
      foreach( $value as $key => $prop ){
        $normalized->{ $key } = $this->resultNormalized( $prop );
      }
      return $normalized;
    }

    if( is_array( $value ) ){
      return array_map(
        fn( $item ) => (
          $this->resultNormalized( 
            $item
          )
        ),
        $value
      );
    }

    return $value;
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

      [ $handler, $params, $middlewares 
      ] = $match;

      $nextHandler = function( 
        Request $request
      ) use ( $handler, $params ){
        if( sizeof( $handler ) === 2 ){
          [ $refClass, $refMethod 
          ] = $handler;

          $handler = [
            Closure::fromCallable([ 
            Container::make( 
                $refClass
              ), $refMethod 
            ])
          ];
        };

        [ $callableHandler ] = $handler;        
        $result = $callableHandler( 
          ...$this->resolveArgs( 
            $callableHandler, 
            $request->withParams(
              $params
            )
          )
        );

        if( $result instanceof Response ){
          return $this->resultNormalized( $result );
        }

        return Response::json( 
          $this->resultNormalized( $result )
        );
      };

      return $this->executeMiddlewareStack( $middlewares, $request, $nextHandler );
    } catch( ErrorException $error) {
      Logger::error( sprintf(
        "%s in %s:%d",
        $error->getMessage(),
        $error->getFile(),
        $error->getLine()
      ), LogContext::Controller);

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
      ), LogContext::Controller);

      return Response::json([ 
        "message" => "Internal Server Error"
      ], 500 );
    }
  }
}
