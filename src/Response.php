<?php

namespace Websyspro\WorkerServer;

use function json_encode;
use function strlen;

class Response
{
    private int    $status;
    private array  $headers;
    private string $body;

    public function __construct(int $status = 200, string $body = '', array $headers = [])
    {
        $this->status  = $status;
        $this->body    = $body;
        $this->headers = $headers;
    }

    /**
     * Resposta JSON — Content-Type: application/json
     */
    public static function json(mixed $data, int $status = 200): static
    {
        return new static($status, json_encode($data), [
            'Content-Type' => 'application/json',
        ]);
    }

    /**
     * Resposta texto plano — Content-Type: text/plain
     */
    public static function text(string $text, int $status = 200): static
    {
        return new static($status, $text, [
            'Content-Type' => 'text/plain',
        ]);
    }

    /**
     * Resposta HTML — Content-Type: text/html
     */
    public static function html(string $html, int $status = 200): static
    {
        return new static($status, $html, [
            'Content-Type' => 'text/html; charset=utf-8',
        ]);
    }

    /**
     * Adiciona ou sobrescreve um header na resposta.
     */
    public function withHeader(string $key, string $value): static
    {
        $this->headers[$key] = $value;
        return $this;
    }

    /**
     * Serializa o Response para string HTTP raw, pronto para enviar pelo socket.
     */
    public function build(bool $keepAlive): string
    {
        $status     = $this->status;
        $connection = $keepAlive ? 'keep-alive' : 'close';
        $headers    = "HTTP/1.1 $status " . $this->statusText() . "\r\n";
        $headers   .= "Connection: $connection\r\n";
        $headers   .= "Content-Length: " . strlen($this->body) . "\r\n";

        foreach ($this->headers as $key => $value) {
            $headers .= "$key: $value\r\n";
        }

        return "$headers\r\n{$this->body}";
    }

    private function statusText(): string
    {
        return match($this->status) {
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
