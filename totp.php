<?php
/**
 * totp.php - time-based one-time codes (RFC 6238) for authenticator apps. No external library.
 */
const B32_ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

function b32_encode(string $bin): string
{
    $bits = '';
    for ($i = 0; $i < strlen($bin); $i++) {
        $bits .= str_pad(decbin(ord($bin[$i])), 8, '0', STR_PAD_LEFT);
    }
    $out = '';
    for ($i = 0; $i < strlen($bits); $i += 5) {
        $out .= B32_ALPHABET[bindec(str_pad(substr($bits, $i, 5), 5, '0'))];
    }
    return $out;
}

function b32_decode(string $s): string
{
    $s = strtoupper(preg_replace('/[^A-Za-z2-7]/', '', $s));
    $bits = '';
    for ($i = 0; $i < strlen($s); $i++) {
        $bits .= str_pad(decbin(strpos(B32_ALPHABET, $s[$i])), 5, '0', STR_PAD_LEFT);
    }
    $out = '';
    for ($i = 0; $i + 8 <= strlen($bits); $i += 8) {
        $out .= chr(bindec(substr($bits, $i, 8)));
    }
    return $out;
}

function new_totp_secret(): string
{
    return b32_encode(random_bytes(20));
}

function totp_code(string $secret, int $counter): string
{
    $hash = hash_hmac('sha1', pack('J', $counter), b32_decode($secret), true);
    $offset = ord($hash[19]) & 0x0f;
    $code = ((ord($hash[$offset]) & 0x7f) << 24)
          | (ord($hash[$offset + 1]) << 16)
          | (ord($hash[$offset + 2]) << 8)
          | ord($hash[$offset + 3]);
    return str_pad((string) ($code % 1000000), 6, '0', STR_PAD_LEFT);
}

/** Accepts the current code and one step either side (clock drift). */
function totp_verify(string $secret, string $code): bool
{
    if ($secret === '' || !preg_match('/^\d{6}$/', $code)) {
        return false;
    }
    $now = intdiv(time(), 30);
    foreach ([-1, 0, 1] as $drift) {
        if (hash_equals(totp_code($secret, $now + $drift), $code)) {
            return true;
        }
    }
    return false;
}
