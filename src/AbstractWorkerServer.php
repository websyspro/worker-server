<?php

namespace Websyspro\WorkerServer;

use Websyspro\WorkerServer\Interfaces\ServerTools;
use Websyspro\WorkerServer\Request;
use Websyspro\WorkerServer\Response;
use Websyspro\WorkerServer\Logger;
use function defined;
use function in_array;
use function array_search;
use function array_column;
use function stream_select;
use function stream_socket_accept;
use function stream_socket_get_name;
use function stream_set_blocking;
use function stream_socket_server;
use function fread;
use function fwrite;
use function fclose;
use function strpos;
use function substr;
use function strlen;
use function explode;
use function strtolower;
use function trim;
use function time;
use function getmypid;
use function shell_exec;
use function pcntl_fork;
use function pcntl_wait;
use function sleep;
use const WNOHANG;
use const PHP_OS_FAMILY;

abstract class AbstractWorkerServer
{
  public ServerTools $serverTools;
  public string $host;
  public int $port;
  public int $apiVersion;
  public int $workers;
  public int $keepAliveTimeout;
  public int $maxRequests;
  private mixed $server = null;
  private array $pids = [];

  public function __construct(
  ){
    /* define Server Tools */
    $this->serverTools = $this->getServerTools();

    /* define Servers */
    $this->keepAliveTimeout = $this->serverTools->keepAliveTimeout;
    $this->maxRequests = $this->serverTools->maxRequests;
    $this->apiVersion = $this->serverTools->apiVersion;
    $this->port = $this->serverTools->port;
    $this->host = $this->serverTools->host;

    /* define Workers 1 */
    $this->workers = max( 1, (int) shell_exec( "nproc" ));
  }

  private function getServerTools(
    ServerTools|null $serverTools = null
  ): ServerTools|null {
    if( defined( "BASE_DIR" ) === false ){
      return null;
    }

    if( file_exists( BASE_DIR . "server-config.php" )){
      $serverTools = require_once BASE_DIR . "server-config.php";
      if( $serverTools instanceof ServerTools ){
        return $serverTools;
      }
    }

    return null;
  }

  private function parseRequest(
    string $buffer
  ): array|null {
    $headerEnd = strpos( $buffer, "\r\n\r\n" );
    if( $headerEnd === false ){
      return null;
    }

    $headerSection = substr( $buffer, 0, $headerEnd );
    $body = substr( $buffer, $headerEnd + 4 );
    $lines = explode( "\r\n", $headerSection );
    $firstLine = array_shift( $lines );
    $parsedHeaders = [];

    foreach( $lines as $line ){
      if (strpos( $line, ":" ) !== false ){
        [ $name, $value ] = explode( ":", $line, 2 );
        $parsedHeaders[ strtolower( trim( $name ))] = trim( $value );
      }
    }

    $contentLength = (int)( $parsedHeaders[ "content-length" ] ?? 0 );
    if( strlen( $body ) < $contentLength ){
      return null; // body incompleto, aguarda mais dados
    }

    return [
      "firstLine" => $firstLine,
      "headers" => $parsedHeaders,
      "body" => substr( $body, 0, $contentLength ),
      "remaining" => substr( $body, $contentLength )
    ];
  }

  abstract protected function handleRequest( Request $request ): Response;
  abstract public function getRoutes(): array;

  private function runWorker(
    int $workerId,
    int $requestCount = 0,
    array $clients = []
  ): void {
    Logger::info( "Worker $workerId iniciado (PID: " . getmypid() . ")" );

    while( true ){
      $sockets = array_column( $clients, "socket" );
      $read = [ ...[ $this->server ], ...$sockets];
      $write = null;
      $except = null;

      // stream_select aguarda atividade em qualquer socket (timeout 1s)
      // o timeout de 1s permite verificar conexoes idle periodicamente
      if( stream_select( $read, $write, $except, 1, 0 ) === false ){
        break;
      }

      // Nova conexao chegando no socket servidor
      if( in_array( $this->server, $read )){
        $client = @stream_socket_accept( 
          $this->server, 0
        );

        if( $client ){
          stream_set_blocking(
            $client, false
          );

          $clients[ (int)$client ] = [
            "buffer" => "",
            "socket" => $client,
            "lastActivity" => time()
          ];
            
          $addr = stream_socket_get_name( $client, true );
          Logger::info("[Worker $workerId] Conexao de $addr (total: " . count($clients) . ")");
        }

        unset( $read[ array_search( $this->server, $read )]);
      }

      // Processa clientes com dados prontos para leitura
      foreach( $read as $socket ){
        $id = (int) $socket;
        if( !isset( $clients[ $id ])){
          continue;
        }

        $chunk = fread(
          $socket,
          8192
        );

        if( $chunk === false || $chunk === "" ){
          fclose($socket);
          unset($clients[$id]);
          
          Logger::info("[Worker $workerId] Desconectou (restam: " . count($clients) . ")");
          continue;
        }

        $clients[ $id ][ "buffer" ] .= $chunk;
        $clients[ $id ][ "lastActivity" ] = time();

        // Processa todas as requisicoes completas no buffer (HTTP pipelining)
        while( true ){
          $request = $this->parseRequest(
            $clients[$id]['buffer']
          );

          if( $request === null ){
            break;
          }

          $connection = $request['headers']['connection'] ?? 'keep-alive';
          $keepAlive = strtolower($connection) !== 'close';
          $response = $this->handleRequest(new Request($request));

          fwrite( $socket, $response->build( $keepAlive ));

          Logger::info("[Worker $workerId] {$request['firstLine']} (keep-alive: " . ($keepAlive ? 'sim' : 'nao') . ")");

          $clients[$id]['buffer'] = $request['remaining'];

          $requestCount++;
          if( $requestCount >= $this->maxRequests ){
            Logger::warn("[Worker $workerId] Limite de {$this->maxRequests} requisicoes atingido, encerrando...");
            foreach( $clients as $info ){
              fclose($info['socket']);
            }

            exit(0);
          }

          if( $keepAlive === false ){
            fclose($socket);
            unset($clients[$id]);
            break;
          }
        }
      }

      // Fecha conexoes ociosas que ultrapassaram o keep-alive timeout
      $now = time();
      foreach( $clients as $id => $info ){
        if(($now - $info['lastActivity']) >= $this->keepAliveTimeout ){
          fclose($info['socket']);
          unset($clients[$id]);
          
          Logger::warn("[Worker $workerId] Conexao idle fechada por timeout");
        }
      }
    }
  }

  public function start(
  ): void {
    $this->server = stream_socket_server(
      "tcp://{$this->host}:{$this->port}", $errno, $errstr
    );

    if( !$this->server ){
      die( "Erro ao criar servidor: $errstr ($errno)\n" );
    }

    stream_set_blocking(
      $this->server,
      false
    );

    foreach ($this->getRoutes() as $route) {
      [ $method, $path ] = explode(
        " ", $route, 2
      );

      Logger::info( "$method $path" );
    }

    if( PHP_OS_FAMILY === "Windows" ){
      $this->startSingleProcess();
    } else {
      $this->startMultiProcess();
    }
  }

  private function startSingleProcess(): void
  {
    Logger::info( "Master PID: " . getmypid() );
    Logger::info( "Porta: {$this->port}" );
    Logger::info( "Modo: single-process (Windows)" );
    Logger::info( "Keep-Alive: {$this->keepAliveTimeout}s" );
    Logger::info( "Server running on http://{$this->host}:{$this->port}" );

    $this->runWorker(1);
  }

  private function startMultiProcess(
  ): void {
    Logger::info( "Master PID: " . getmypid());
    Logger::info( "Porta: {$this->port}" );
    Logger::info( "Workers: {$this->workers}" );
    Logger::info( "Keep-Alive: {$this->keepAliveTimeout}s" );
    Logger::info( "Max Requests: {$this->maxRequests}" );
    Logger::info( "Server running on http://{$this->host}:{$this->port}" );

    for($i = 0; $i < $this->workers; $i++){
      $pid = pcntl_fork();
      
      if( $pid === -1 ){
        die("Falha ao criar worker $i\n");
      }

      if( $pid === 0 ){
        $this->runWorker($i + 1);
        exit(0);
      }
      
      $this->pids[] = $pid;
    }

    // Loop do master — reinicia workers mortos automaticamente
    while( true ){
      $status = 0;
      $pid = pcntl_wait(
        $status, WNOHANG
      );

      if( $pid > 0 ){
        $idx = array_search(
          $pid, $this->pids
        );

        Logger::warn( "Worker PID $pid morreu, reiniciando..." );
        $newPid = pcntl_fork();
        
        if( $newPid === 0 ){
          $this->runWorker($idx + 1);
          exit(0);
        }
        
        $this->pids[$idx] = $newPid;
      }

      sleep(1);
    }
  }
}
