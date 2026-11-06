<?php

namespace Websyspro\WorkerServer;

use Websyspro\WorkerServer\Enums\LogContext;

class JwtKeyManager
{
  private const KEYS_DIR         = __DIR__ . DIRECTORY_SEPARATOR . 'keys';
  private const PRIVATE_KEY_FILE = 'private.pem';
  private const PUBLIC_KEY_FILE  = 'public.pem';

  private string $privateKeyPath;
  private string $publicKeyPath;

  private string $privateKey;
  private string $publicKey;

  public function __construct(
  ){
    $dir = static::KEYS_DIR;

    if( is_dir( $dir ) === false ){
      mkdir( $dir, 0700, true );
    }

    $this->privateKeyPath = $dir . DIRECTORY_SEPARATOR . self::PRIVATE_KEY_FILE;
    $this->publicKeyPath  = $dir . DIRECTORY_SEPARATOR . self::PUBLIC_KEY_FILE;
  }

  public function boot(
  ): static {
    if( $this->keysExist() ){
      $this->loadKeys();
      Logger::info( "RSA keys loaded from disk", LogContext::Auth );
    } else {
      $this->generateKeys();
      Logger::info( "RSA keys generated (2048-bit)", LogContext::Auth );
      Logger::info( "Public key: {$this->publicKeyPath}", LogContext::Auth );
    }

    return $this;
  }

  private function keysExist(
  ): bool {
    return file_exists( $this->privateKeyPath )
      && file_exists( $this->publicKeyPath );
  }

  private function generateKeys(
  ): void {
    $keypair = openssl_pkey_new([
      'private_key_bits' => 2048,
      'private_key_type' => OPENSSL_KEYTYPE_RSA,
    ]);

    openssl_pkey_export( $keypair, $privateKey );
    $details   = openssl_pkey_get_details( $keypair );
    $publicKey = $details['key'];

    file_put_contents( $this->privateKeyPath, $privateKey );
    file_put_contents( $this->publicKeyPath,  $publicKey  );

    $this->privateKey = $privateKey;
    $this->publicKey  = $publicKey;
  }

  private function loadKeys(
  ): void {
    $this->privateKey = file_get_contents( $this->privateKeyPath );
    $this->publicKey  = file_get_contents( $this->publicKeyPath );
  }

  public function getPrivateKey(
  ): string {
    return $this->privateKey;
  }

  public function getPublicKey(
  ): string {
    return $this->publicKey;
  }
}
