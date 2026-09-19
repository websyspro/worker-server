<?php

namespace Websyspro\WorkerServer;

use function json_encode;
use function strlen;

class Response
{
  public function __construct(
    private int $status = 200, 
    private string $body = "", 
    private array $headers = []
  ){}

  private static function jsonRequest(
    mixed $content, 
    bool $success = true
  ): string {
    return json_encode([
      "success" => $success,
      "content" => $content
    ]);
  }

  public static function json(
    mixed $content, 
    int $status = 200
  ): Response {
    return new Response( 
      $status, Response::jsonRequest( $content, $status < 200 ), [
        "Content-Type" => "application/json",
      ]
    );
  }

  public static function text(
    string $text, 
    int $status = 200
  ): Response {
    return new Response( $status, $text, [
      "Content-Type" => "text/plain",
    ]);
  }

  public static function html(
    string $html, 
    int $status = 200
  ): Response {
    return new Response( $status, $html, [
      "Content-Type" => "text/html; charset=utf-8",
    ]);
  }

  public function withHeader(
    string $key, string $value
  ): Response {
    $this->headers[ $key ] = $value;
    return $this;
  }

  public function build(
    bool $keepAlive
  ): string {
    $status = $this->status;
    $connection = $keepAlive ? 'keep-alive' : 'close';
    $headers = "HTTP/1.1 $status " . $this->statusText() . "\r\n";
    $headers .= "Connection: $connection\r\n";
    $headers .= "Content-Length: " . strlen( $this->body ) . "\r\n";

    foreach( $this->headers as $key => $value ){
      $headers .= "$key: $value\r\n";
    }

    return "$headers\r\n{$this->body}";
  }

  private function statusText(
  ): string {
    return match( $this->status ){
      200 => 'OK',
      201 => 'Created',
      204 => 'No Content',
      400 => 'Bad Request',
      401 => 'Unauthorized',
      403 => 'Forbidden',
      404 => 'Not Found',
      500 => 'Internal Server Error',
        default => 'OK',
    };
  }
}
