<?php

namespace Websyspro\WorkerServer;

class JwtManager
{
  private const ALGORITHM = 'RS256';
  private const HEADER    = [ 'alg' => self::ALGORITHM, 'typ' => 'JWT' ];

  private string $privateKey;
  private string $publicKey;
  private int    $ttl;
  private string $issuer;

  public function __construct(
    RsaKeysGenerator $rsaKeysGenerator,
    int    $ttl    = 3600,   // 1 hora por padrão
    string $issuer = 'worker-server'
  ){
    $this->privateKey = $rsaKeysGenerator->getPrivateKey();
    $this->publicKey  = $rsaKeysGenerator->getPublicKey();
    $this->ttl        = $ttl;
    $this->issuer     = $issuer;
  }

  // Assina e retorna um JWT com as claims fornecidas
  // Claims automáticas: iss, iat, exp
  public function sign(
    array $payload
  ): string {
    $now = time();

    $payload = array_merge( $payload, [
      'iss' => $this->issuer,
      'iat' => $now,
      'exp' => $payload['exp'] ?? $now + $this->ttl,
    ]);

    $header    = $this->base64url( json_encode( self::HEADER ) );
    $body      = $this->base64url( json_encode( $payload ) );
    $signature = $this->signRsa( "{$header}.{$body}" );

    return "{$header}.{$body}.{$signature}";
  }

  // Valida o token e retorna o payload, ou null se inválido/expirado
  public function verify(
    string $token
  ): array|null {
    $parts = explode( '.', $token );

    if( count( $parts ) !== 3 ){
      return null;
    }

    [ $header, $body, $signature ] = $parts;

    // Valida a assinatura
    if( $this->verifyRsa( "{$header}.{$body}", $signature ) === false ){
      return null;
    }

    $payload = json_decode(
      base64_decode( strtr( $body, '-_', '+/' ) ), true
    );

    if( $payload === null ){
      return null;
    }

    // Verifica expiração
    if( isset( $payload['exp'] ) && $payload['exp'] < time() ){
      return null;
    }

    // Verifica issuer
    if( isset( $payload['iss'] ) && $payload['iss'] !== $this->issuer ){
      return null;
    }

    return $payload;
  }

  // Decodifica o payload sem validar assinatura (útil para debug)
  public function decode(
    string $token
  ): array|null {
    $parts = explode( '.', $token );

    if( count( $parts ) !== 3 ){
      return null;
    }

    return json_decode(
      base64_decode( strtr( $parts[1], '-_', '+/' ) ), true
    );
  }

  // Extrai o token do header Authorization: Bearer <token>
  public function extractFromBearer(
    string|null $authorizationHeader
  ): string|null {
    if( $authorizationHeader === null ){
      return null;
    }

    if( str_starts_with( $authorizationHeader, 'Bearer ' ) === false ){
      return null;
    }

    return trim( substr( $authorizationHeader, 7 ) );
  }

  // ─── Internals ───────────────────────────────────────────────

  private function base64url(
    string $data
  ): string {
    return rtrim( strtr( base64_encode( $data ), '+/', '-_' ), '=' );
  }

  private function signRsa(
    string $data
  ): string {
    $privateKey = openssl_pkey_get_private( $this->privateKey );
    openssl_sign( $data, $signature, $privateKey, OPENSSL_ALGO_SHA256 );
    return $this->base64url( $signature );
  }

  private function verifyRsa(
    string $data,
    string $signature
  ): bool {
    $publicKey = openssl_pkey_get_public( $this->publicKey );
    $decoded   = base64_decode( strtr( $signature, '-_', '+/' ) );

    return openssl_verify( $data, $decoded, $publicKey, OPENSSL_ALGO_SHA256 ) === 1;
  }
}
