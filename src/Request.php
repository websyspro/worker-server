<?php

namespace Websyspro\WorkerServer;

use function explode;
use function parse_str;
use function json_decode;
use function str_contains;
use function strtolower;
use function strtoupper;
use function trim;
use function parse_url;
use function preg_split;
use function preg_match;
use function preg_quote;
use function preg_replace;
use function substr;
use function strpos;
use function strlen;
use function rtrim;
use function strip_tags;
use function filter_var;
use function is_string;
use function is_array;
use function is_object;
use function array_map;
use const PHP_URL_PATH;
use const PHP_URL_QUERY;
use const FILTER_SANITIZE_SPECIAL_CHARS;

class Request
{
    public readonly string $method;
    public readonly string $path;
    public readonly string $rawBody;
    public readonly array  $headers;
    public readonly array  $query;   // /?userId=123       => $request->query['userId']
    public readonly array  $params;  // /:userId           => $request->params['userId']
    public readonly mixed  $body;    // JSON obj           => $request->body->userId
                                     // JSON array         => $request->body[0]
                                     // form-encoded       => $request->body['userId']
    public readonly array  $files;   // multipart upload   => $request->files['avatar']

    public function __construct(array $parsed, array $params = [])
    {
        $parts         = explode(' ', trim($parsed['firstLine']));
        $this->method  = strtoupper($parts[0] ?? 'GET');
        $fullPath      = $parsed['firstLine'] !== '' ? (explode(' ', $parsed['firstLine'])[1] ?? '/') : '/';
        $this->path    = parse_url($fullPath, PHP_URL_PATH) ?? '/';
        $this->headers = $this->sanitizeHeaders($parsed['headers']);
        $this->rawBody = $parsed['body'];
        $this->params  = $this->sanitizeArray($params);

        $queryString = parse_url($fullPath, PHP_URL_QUERY) ?? '';
        $query = [];
        parse_str($queryString, $query);
        $this->query = $this->sanitizeArray($query);

        $contentType = strtolower($this->headers['content-type'] ?? '');

        if (str_contains($contentType, 'application/json')) {
            $decoded     = json_decode($this->rawBody);
            $this->body  = $this->sanitizeValue($decoded);
            $this->files = [];
        } elseif (str_contains($contentType, 'application/x-www-form-urlencoded')) {
            $body = [];
            parse_str($this->rawBody, $body);
            $this->body  = $this->sanitizeArray($body);
            $this->files = [];
        } elseif (str_contains($contentType, 'multipart/form-data')) {
            [$body, $files] = $this->parseMultipart($this->rawBody, $contentType);
            $this->body  = $this->sanitizeArray($body);
            $this->files = $files; // arquivos nao sao sanitizados — binario
        } else {
            $this->body  = null;
            $this->files = [];
        }
    }

    /**
     * Sanitiza recursivamente todos os valores de um array.
     * Remove tags HTML, caracteres especiais e null bytes.
     */
    private function sanitizeArray(array $data): array
    {
        return array_map(fn($value) => $this->sanitizeValue($value), $data);
    }

    /**
     * Sanitiza headers — remove null bytes e caracteres de controle
     * que poderiam ser usados para header injection.
     */
    private function sanitizeHeaders(array $headers): array
    {
        $clean = [];
        foreach ($headers as $key => $value) {
            $cleanKey        = preg_replace('/[^\w\-]/', '', $key);
            $clean[$cleanKey] = $this->sanitizeString($value);
        }
        return $clean;
    }

    /**
     * Sanitiza um valor de qualquer tipo recursivamente.
     *
     * - string  : remove tags HTML, null bytes e caracteres especiais perigosos
     * - array   : sanitiza cada elemento
     * - object  : sanitiza cada propriedade
     * - outros  : retorna como esta (int, float, bool, null)
     */
    private function sanitizeValue(mixed $value): mixed
    {
        if (is_string($value)) {
            return $this->sanitizeString($value);
        }

        if (is_array($value)) {
            return $this->sanitizeArray($value);
        }

        if (is_object($value)) {
            foreach ($value as $key => $val) {
                $value->$key = $this->sanitizeValue($val);
            }
            return $value;
        }

        // int, float, bool, null — seguros por natureza
        return $value;
    }

    /**
     * Sanitiza uma string aplicando multiplas camadas:
     *
     * 1. Remove null bytes (\0) — usados para bypass de validacoes
     * 2. strip_tags — remove qualquer tag HTML/PHP (previne XSS)
     * 3. FILTER_SANITIZE_SPECIAL_CHARS — escapa caracteres especiais HTML
     * 4. trim — remove espacos desnecessarios
     */
    private function sanitizeString(string $value): string
    {
        // 1. Remove null bytes
        $value = preg_replace('/\0/', '', $value);

        // 2. Remove tags HTML e PHP
        $value = strip_tags($value);

        // 3. Escapa caracteres especiais HTML (<, >, ", &)
        $value = filter_var($value, FILTER_SANITIZE_SPECIAL_CHARS);

        // 4. Remove espacos extras
        return trim($value);
    }

    /**
     * Retorna uma nova instancia com os params de rota preenchidos.
     */
    public function withParams(array $params): static
    {
        return new static($this->toParsed(), $params);
    }

    private function toParsed(): array
    {
        return [
            'firstLine' => "{$this->method} {$this->path} HTTP/1.1",
            'headers'   => $this->headers,
            'body'      => $this->rawBody,
            'remaining' => '',
        ];
    }

    /**
     * Faz o parse do body multipart/form-data.
     */
    private function parseMultipart(string $body, string $contentType): array
    {
        $fields = [];
        $files  = [];

        preg_match('/boundary=(.+)$/', $contentType, $matches);
        if (empty($matches[1])) {
            return [$fields, $files];
        }

        $boundary = '--' . trim($matches[1]);
        $parts    = preg_split('/' . preg_quote($boundary, '/') . '/', $body);

        array_shift($parts);
        array_pop($parts);

        foreach ($parts as $part) {
            if (empty(trim($part))) {
                continue;
            }

            $headerEnd   = strpos($part, "\r\n\r\n");
            $partHeaders = substr($part, 0, $headerEnd);
            $partBody    = rtrim(substr($part, $headerEnd + 4), "\r\n");

            preg_match('/name="([^"]+)"/', $partHeaders, $nameMatch);
            preg_match('/filename="([^"]*)"/', $partHeaders, $fileMatch);
            preg_match('/Content-Type:\s*([^\r\n]+)/i', $partHeaders, $typeMatch);

            $fieldName = $nameMatch[1] ?? null;
            if ($fieldName === null) {
                continue;
            }

            if (!empty($fileMatch[1])) {
                $files[$fieldName] = [
                    'name'    => $fileMatch[1],
                    'type'    => trim($typeMatch[1] ?? 'application/octet-stream'),
                    'content' => $partBody,
                    'size'    => strlen($partBody),
                ];
            } else {
                $fields[$fieldName] = $partBody;
            }
        }

        return [$fields, $files];
    }
}
