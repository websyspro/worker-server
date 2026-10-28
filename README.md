# Worker Server

Set of features for hot reloads during development and production-ready HTTP server with built-in middleware system.

## Features

- 🚀 High-performance worker-based HTTP server
- 🔐 Extensible middleware system with PHP attributes
- 🔄 Hot reload support for development
- 📝 Automatic route registration from controllers
- 🎯 Dependency injection and parameter resolution
- 🛡️ Request sanitization and security
- 📊 Built-in logging system
- ⚡ Keep-alive connection support
- 🔌 Multi-process worker model (Linux/macOS) or single-process (Windows)

## Middleware System

O Worker Server possui um sistema de middlewares extensível baseado em atributos PHP 8.

### Como Funciona

1. **Crie um middleware** estendendo a classe `Middleware`
2. **Implemente o método `execute()`** com sua lógica
3. **Use como atributo** em classes ou métodos de controller
4. **Middlewares são executados** automaticamente na ordem: classe → método

### Exemplo de Middleware Customizado

```php
<?php

use Websyspro\WorkerServer\Middleware;
use Websyspro\WorkerServer\Request;
use Websyspro\WorkerServer\Response;
use Attribute;

#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD)]
class RateLimitMiddleware extends Middleware
{
    public function execute(Request $request, callable $next): Response|null
    {
        // Sua lógica de rate limiting aqui
        if ($this->isRateLimited($request)) {
            return Response::json([
                "error" => "Too many requests"
            ], 429);
        }

        // Continua para o próximo middleware ou handler
        return $next($request);
    }
}
```

### Uso nos Controllers

```php
#[Controller(prefix: "/api")]
#[Authenticate]  // Middleware de autenticação na classe
class ApiController
{
    #[Get("/users")]
    public function listUsers() {
        // Requer autenticação
    }

    #[Post("/login")]
    #[AllowAnonymous]  // Sobrepõe o Authenticate
    public function login() {
        // Público - sem autenticação
    }
}
```

### Middleware `Authenticate`

O middleware `Authenticate` está incluído e valida Bearer tokens:

```php
#[Controller(prefix: "/protected")]
#[Authenticate]
class ProtectedController {
    // Todos os endpoints requerem autenticação
}
```

### Middleware `AllowAnonymous`

Use `AllowAnonymous` para tornar endpoints públicos em controllers protegidos:

```php
#[Authenticate]
class AuthController {
    #[Post("/login")]
    #[AllowAnonymous]  // Este endpoint é público
    public function login() {}
}
```

## Configuration

Configuration via `.env` file:

```env
PORT=8080
API_VERSION=v1
KEEP_ALIVE_TIMEOUT=30
MAX_REQUESTS=1000
```

## License

MIT