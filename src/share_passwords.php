<?php

function poznoteSharePasswordEncryptionKey(): string {
    $keyFile = __DIR__ . '/data/.app_secret';
    $envSecret = getenv('POZNOTE_APP_SECRET');

    if ($envSecret) {
        if (!file_exists($keyFile)) {
            $dir = dirname($keyFile);
            if (!is_dir($dir)) {
                mkdir($dir, 0755, true);
            }
            file_put_contents($keyFile, $envSecret);
            chmod($keyFile, 0600);
        }
        return hash('sha256', $envSecret, true);
    }

    if (file_exists($keyFile)) {
        $secret = trim((string)file_get_contents($keyFile));
    } else {
        $dir = dirname($keyFile);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        $secret = bin2hex(random_bytes(32));
        file_put_contents($keyFile, $secret);
        chmod($keyFile, 0600);
    }

    return hash('sha256', $secret, true);
}

function poznoteEncryptSharePassword(?string $plainPassword): ?string {
    $plainPassword = trim((string)$plainPassword);
    if ($plainPassword === '') {
        return null;
    }

    if (!function_exists('openssl_encrypt')) {
        throw new RuntimeException('OpenSSL encryption support is required to store recoverable share passwords.');
    }

    $iv = random_bytes(12);
    $tag = '';
    $cipher = openssl_encrypt($plainPassword, 'aes-256-gcm', poznoteSharePasswordEncryptionKey(), OPENSSL_RAW_DATA, $iv, $tag);
    if ($cipher === false) {
        throw new RuntimeException('Could not encrypt share password.');
    }

    return 'enc1:' . base64_encode($iv . $tag . $cipher);
}

function poznoteDecryptSharePassword(?string $storedPassword): string {
    $storedPassword = trim((string)$storedPassword);
    if ($storedPassword === '') {
        return '';
    }

    if (strncmp($storedPassword, 'enc1:', 5) !== 0) {
        return $storedPassword;
    }

    if (!function_exists('openssl_decrypt')) {
        return '';
    }

    $data = base64_decode(substr($storedPassword, 5), true);
    if ($data === false || strlen($data) < 29) {
        return '';
    }

    $iv = substr($data, 0, 12);
    $tag = substr($data, 12, 16);
    $cipher = substr($data, 28);
    $plain = openssl_decrypt($cipher, 'aes-256-gcm', poznoteSharePasswordEncryptionKey(), OPENSSL_RAW_DATA, $iv, $tag);

    return $plain !== false ? $plain : '';
}
