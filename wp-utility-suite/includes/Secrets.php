<?php
namespace Wpus;

defined('ABSPATH') || exit;

final class Secrets
{
    private static function key(string $id): ?string
    {
        if (!function_exists('sodium_crypto_aead_xchacha20poly1305_ietf_encrypt')
            || !defined('WPUS_KEYRING') || !is_array(WPUS_KEYRING) || !is_string(WPUS_KEYRING[$id] ?? null)) {
            return null;
        }
        $key = base64_decode(WPUS_KEYRING[$id], true);
        return $key !== false && strlen($key) === 32 ? $key : null;
    }

    public static function active(): ?string
    {
        $id = defined('WPUS_ACTIVE_KEY_ID') ? WPUS_ACTIVE_KEY_ID : '';
        return is_string($id) && preg_match('/\A[A-Za-z0-9_-]{1,32}\z/D', $id) && self::key($id) !== null ? $id : null;
    }

    private static function binding(string $purpose, string $record): string
    {
        return implode('|', ['wpus:v1', get_current_blog_id(), get_option('wpus_site_uuid'), $purpose, $record]);
    }

    public static function seal(string $purpose, string $record, string $plain): ?array
    {
        $id = self::active();
        if ($id === null || strlen($plain) > 4096) {
            return null;
        }
        $nonce = random_bytes(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES);
        $encrypted = sodium_crypto_aead_xchacha20poly1305_ietf_encrypt(
            $plain, self::binding($purpose, $record), $nonce, self::key($id)
        );
        return ['key_id' => $id, 'encrypted' => base64_encode($nonce . $encrypted)];
    }

    public static function open(string $purpose, string $record, string $keyId, string $encrypted): ?string
    {
        $key = self::key($keyId);
        $bytes = base64_decode($encrypted, true);
        if ($key === null || $bytes === false || strlen($bytes) < 40 || strlen($bytes) > 4136) {
            return null;
        }
        $length = SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES;
        $plain = sodium_crypto_aead_xchacha20poly1305_ietf_decrypt(
            substr($bytes, $length), self::binding($purpose, $record), substr($bytes, 0, $length), $key
        );
        return $plain === false ? null : $plain;
    }

    public static function store(int $owner, string $purpose, string $value): bool
    {
        $secret = self::seal($purpose, (string) $owner, $value);
        if (!$secret) {
            return false;
        }
        return Db::query(
            'INSERT INTO %i (owner_id, purpose, key_id, encrypted) VALUES (%d, %s, %s, %s)
             ON DUPLICATE KEY UPDATE key_id = VALUES(key_id), encrypted = VALUES(encrypted)',
            Db::table('secrets'), $owner, $purpose, $secret['key_id'], $secret['encrypted']
        ) !== false;
    }

    public static function resolve(int $owner, string $purpose): ?string
    {
        $row = Db::row('SELECT key_id, encrypted FROM %i WHERE owner_id = %d AND purpose = %s', Db::table('secrets'), $owner, $purpose);
        return $row ? self::open($purpose, (string) $owner, $row['key_id'], $row['encrypted']) : null;
    }
}
