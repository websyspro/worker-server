<?php

namespace Websyspro\WorkerServer;

use Closure;
use ErrorException;
use ReflectionClass;
use ReflectionFunction;
use ReflectionMethod;
use ReflectionNamedType;
use Throwable;
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
use Websyspro\WorkerServer\Decorators\Body;
use Websyspro\WorkerServer\Decorators\Query;
use Websyspro\WorkerServer\Decorators\Param;
use Websyspro\WorkerServer\Decorators\File;
use Websyspro\WorkerServer\Decorators\Authenticate;
use Websyspro\WorkerServer\Decorators\AllowAnonymous;
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

class WorkerServer 
extends AbstractWorkerServer
{
  private array $routers = [];
  private string $prefix;
  private IAuthenticationHandler|null $authHandler;

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

      // Extrai nomes dos params da rota (:id, :postId, ...)
      preg_match_all( "#:([a-zA-Z_]+)#", $routePath, $matches );
      $paramNames = $matches[1];

      // Converte a rota em regex: /users/:id => /users/([^/]+)
      $pattern = preg_replace( "#:([a-zA-Z_]+)#", '([^/]+)', $routePath);
      $pattern = '#^' . $pattern . '$#';

      if( !preg_match( $pattern, $requestPath, $values )){
        continue;
      }

      // $values[0] e o match completo, params comecam em [1]
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
    array $modules
  ): WorkerServer {
    foreach( $modules as $moduleClass ){
      $reflection = new ReflectionClass($moduleClass);
      $moduleAttr = $reflection->getAttributes( Module::class )[0] ?? null;

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

      $this->registerControllers(
        $module->controllers, 
        $modulePrefix
      );
    }

    return $this;
  }

  public function registerControllers(
    array $controllers,
    string $modulePrefix = ""
  ): WorkerServer {
    foreach( $controllers as $controllerClass ){
      $this->register( $controllerClass, $modulePrefix );
    }

    return $this;
  }

  public function register(
    string $controllerClass, 
    string $modulePrefix = ""
  ): WorkerServer {
    $reflection = new ReflectionClass($controllerClass);
    $controllerAttr = $reflection->getAttributes(Controller::class)[0] ?? null;

    if( $controllerAttr === null ){
      return $this;
    }

    // Coleta middlewares da classe
    $classMiddlewares = [];
    foreach ($reflection->getAttributes() as $attribute) {
      $attrClass = $attribute->getName();
      if (is_subclass_of($attrClass, Middleware::class)) {
        $classMiddlewares[] = $attrClass;
      }
    }

    // Verifica se o controller tem o atributo Authenticate
    $authenticateAttr = $reflection->getAttributes(Authenticate::class)[0] ?? null;
    $requiresAuth = $authenticateAttr !== null;

    $controllerPath = '/' . trim($controllerAttr->newInstance()->prefix, '/');
    $basePath = $modulePrefix . $controllerPath;
    $instance = Container::make($controllerClass);
    $httpAttrs = [Get::class, Post::class, Put::class, Patch::class, Delete::class];

    Logger::info("Controller -> {$reflection->getShortName()}" . ($requiresAuth ? " [Protected]" : ""));
    
    foreach ($reflection->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
      // Verifica se o metodo tem AllowAnonymous
      $allowAnonymousAttr = $method->getAttributes(AllowAnonymous::class)[0] ?? null;
      $allowAnonymous = $allowAnonymousAttr !== null;

      // Coleta middlewares do método
      $methodMiddlewares = [];
      foreach ($method->getAttributes() as $attribute) {
        $attrClass = $attribute->getName();
        if (is_subclass_of($attrClass, Middleware::class)) {
          $methodMiddlewares[] = $attrClass;
        }
      }

      // Combina middlewares: classe primeiro, depois método
      $middlewares = array_merge($classMiddlewares, $methodMiddlewares);

      // Se AllowAnonymous está presente, remove Authenticate da pilha
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
          'middlewares' => array_values($middlewares), // Re-indexa o array
        ];

        $this->registerRouter($httpMethod, $fullPath, $handler, $metadata);
        
        $authStatus = $requiresAuth 
          ? ($allowAnonymous ? " [Public]" : " [Protected]") 
          : "";
        
        Logger::info("  {$httpMethod} {$fullPath}{$authStatus}");
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

      // Request puro — injeta direto
      if( $type instanceof ReflectionNamedType && $type->getName() === Request::class ){
        $args[] = $request;
        continue;
      }

      // Response — injeta uma instância nova (o handler pode populá-la e retorná-la)
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
          // key informada — extrai campo especifico da fonte
          $value = is_array($source) ? ($source[$key] ?? null) : (is_object($source) ? ($source->$key ?? null) : null);
          $args[] = $value;
        } elseif ( $modelClass && is_subclass_of( $modelClass, Model::class )){
          // sem key + Model — hidrata o model com toda a fonte
          $args[] = $modelClass::from($source);
        } else {
          // sem key + tipo primitivo — passa a fonte inteira
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
    // Se não há middlewares, executa direto o handler
    if (empty($middlewares)) {
      return $finalHandler($request);
    }

    // Cria a pilha de execução de trás para frente
    $next = $finalHandler;
    
    foreach (array_reverse($middlewares) as $middlewareClass) {
      $currentNext = $next;
      $middlewareInstance = Container::make($middlewareClass);
      
      $next = function(Request $req) use ($middlewareInstance, $currentNext) {
        $result = $middlewareInstance->execute($req, $currentNext);
        // Se retornou Response, retorna. Se null, continua
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

      [ $handler, $params, $requiresAuth, $allowAnonymous, $middlewares ] = $match;

      // Cria o handler final que será executado após todos os middlewares
      $finalHandler = function(Request $req) use ($handler, $params) {
        $result = $handler( 
          ...$this->resolveArgs( $handler, $req->withParams( $params ))
        );

        if( $result instanceof Response ){
          return $result;
        }

        return Response::json( $result );
      };

      // Executa a pilha de middlewares
      return $this->executeMiddlewareStack($middlewares, $request, $finalHandler);

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
