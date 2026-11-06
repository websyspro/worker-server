<?php

namespace Websyspro\WorkerServer;

use Websyspro\WorkerServer\Enums\LogContext;
use RuntimeException;

class RsaKeysGenerator
{
  private const string KEYS_DIR = __DIR__ . DIRECTORY_SEPARATOR . 'Keys';
  private const string PRIVATE_KEY_FILE = 'private.pem';
  private const string PUBLIC_KEY_FILE  = 'public.pem';

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
    $config = [
      'private_key_bits' => 2048,
      'private_key_type' => OPENSSL_KEYTYPE_RSA,
      'config' => $this->findOpensslConf(),
    ];

    $keypair = openssl_pkey_new( $config );

    if( $keypair === false ){
      throw new RuntimeException(
        "openssl_pkey_new() failed. Check that the openssl extension is enabled and openssl.cnf is accessible.\n"
        . implode( "\n", array_map( fn($e) => $e, openssl_error_string() ? [ openssl_error_string() ] : [] ) )
      );
    }

    openssl_pkey_export( $keypair, $privateKey, null, $config );
    $details = openssl_pkey_get_details( $keypair );
    $publicKey = $details['key'];

    file_put_contents( $this->privateKeyPath, $privateKey );
    file_put_contents( $this->publicKeyPath,  $publicKey  );

    $this->privateKey = $privateKey;
    $this->publicKey  = $publicKey;
  }

  // Localiza o openssl.cnf automaticamente (Windows e Linux)
  private function findOpensslConf(
  ): string|null {
    // Variável de ambiente definida pelo usuário
    if( getenv('OPENSSL_CONF') ){
      return getenv('OPENSSL_CONF');
    }

    // Caminhos comuns no Windows
    $windowsPaths = [
      'D:\\01-Servidor\\php\\extras\\ssl\\openssl.cnf',
      'C:\\Program Files\\Common Files\\SSL\\openssl.cnf',
      'C:\\Program Files (x86)\\Common Files\\SSL\\openssl.cnf',
      'C:\\xampp\\apache\\conf\\openssl.cnf',
      'C:\\laragon\\bin\\apache\\httpd-2.4.54-win64-VS16\\conf\\openssl.cnf',
    ];

    foreach( $windowsPaths as $path ){
      if( file_exists( $path ) ){
        return $path;
      }
    }

    // No Linux o openssl geralmente encontra sozinho
    return null;
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
