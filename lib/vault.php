<?php
declare(strict_types=1);

/*
 * Šifrování soukromých deníků (libsodium, XChaCha20-Poly1305).
 *
 * Každý šifrovaný uživatel má náhodný datový klíč (DEK). Na disku leží jen
 * zabalený: zašifrovaný klíčem odvozeným z přístupového klíče, který zná pouze
 * uživatel. Po přihlášení se DEK zabalí ještě jednou tokenem relace, který má
 * jen prohlížeč v cookie. Server tak bez aktivní relace uživatele nemá čím
 * deník otevřít, ani když má kdokoli přístup k disku nebo k zálohám.
 *
 * Co to nechrání: správce serveru, který by upravil kód aplikace a zachytil
 * klíč při přihlášení. Proti tomu by pomohlo jen šifrování v prohlížeči.
 */

const VAULT_MAGIC = "TDSEAL1\0";
const ACCESS_KEY_ALPHABET = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';

function vault_available(): bool
{
    return function_exists('sodium_crypto_aead_xchacha20poly1305_ietf_encrypt');
}

function vault_require(): void
{
    if (!vault_available()) {
        throw new RuntimeException('Šifrování potřebuje PHP rozšíření sodium.');
    }
}

/** Přístupový klíč: 160 bitů náhody v Crockford base32, osm skupin po čtyřech znacích. */
function generate_access_key(): string
{
    $bytes = random_bytes(20);
    $bits = '';
    foreach (str_split($bytes) as $byte) {
        $bits .= str_pad(decbin(ord($byte)), 8, '0', STR_PAD_LEFT);
    }
    $chars = '';
    foreach (str_split($bits, 5) as $chunk) {
        $chars .= ACCESS_KEY_ALPHABET[bindec($chunk)];
    }
    return implode('-', str_split($chars, 4));
}

/** Sjednotí zápis klíče: velká písmena, bez mezer a pomlček, zaměnitelné znaky podle Crockforda. */
function normalize_access_key(string $value): string
{
    $value = strtoupper(preg_replace('/[\s\-_]+/', '', $value) ?? '');
    return strtr($value, ['O' => '0', 'I' => '1', 'L' => '1']);
}

function looks_like_access_key(string $value): bool
{
    return preg_match('/^[0-9A-HJKMNP-TV-Z]{32}$/', normalize_access_key($value)) === 1;
}

function vault_seal(string $plain, string $key, string $context): string
{
    vault_require();
    $nonce = random_bytes(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES);
    $cipher = sodium_crypto_aead_xchacha20poly1305_ietf_encrypt($plain, VAULT_MAGIC . $context, $nonce, $key);
    return VAULT_MAGIC . $nonce . $cipher;
}

function vault_is_sealed(string $blob): bool
{
    return strncmp($blob, VAULT_MAGIC, strlen(VAULT_MAGIC)) === 0;
}

function vault_open(string $blob, string $key, string $context): string
{
    vault_require();
    if (!vault_is_sealed($blob)) {
        throw new RuntimeException('Soubor není zašifrovaný deník.');
    }
    $offset = strlen(VAULT_MAGIC);
    $nonce = substr($blob, $offset, SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES);
    $cipher = substr($blob, $offset + SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES);
    $plain = sodium_crypto_aead_xchacha20poly1305_ietf_decrypt($cipher, VAULT_MAGIC . $context, $nonce, $key);
    if ($plain === false) {
        throw new RuntimeException('Deník nejde odemknout tímto klíčem.');
    }
    return $plain;
}

/**
 * Klíč pro zabalení DEK z přístupového klíče. Přístupový klíč má 160 bitů náhody,
 * takže stačí rychlá hashovací funkce; pomalé odvození by proti hádání nic nepřidalo.
 */
function access_key_kek(string $accessKey, string $salt): string
{
    return sodium_crypto_generichash('trading-desk/kek|' . normalize_access_key($accessKey), $salt, SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES);
}

function session_kek(string $token): string
{
    return sodium_crypto_generichash('trading-desk/session-kek|' . $token, '', SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES);
}

/** Zapíše soubor atomicky: nejdřív vedle, pak přejmenuje, aby výpadek nenechal půlku. */
function write_file_atomic(string $path, string $contents, int $mode = 0660): void
{
    $temporary = $path . '.tmp-' . bin2hex(random_bytes(6));
    if (file_put_contents($temporary, $contents, LOCK_EX) === false) {
        throw new RuntimeException('Soubor nejde zapsat: ' . basename($path));
    }
    @chmod($temporary, $mode);
    if (!rename($temporary, $path)) {
        @unlink($temporary);
        throw new RuntimeException('Soubor nejde uložit: ' . basename($path));
    }
}

/** Soukromý adresář pro dočasně odemčené deníky; přednostně v RAM (/dev/shm). */
function vault_temp_dir(): string
{
    static $directory = null;
    if ($directory !== null) {
        return $directory;
    }
    $base = is_dir('/dev/shm') && is_writable('/dev/shm') ? '/dev/shm' : sys_get_temp_dir();
    $owner = function_exists('posix_geteuid') ? (string)posix_geteuid() : 'php';
    $directory = rtrim($base, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'trading-desk-' . $owner;
    if (!is_dir($directory) && !@mkdir($directory, 0700, true) && !is_dir($directory)) {
        throw new RuntimeException('Nelze vytvořit dočasný adresář pro odemčený deník.');
    }
    @chmod($directory, 0700);
    vault_sweep_temp($directory);
    return $directory;
}

/** Úklid po případném pádu: odemčené kopie starší než deset minut se smažou. */
function vault_sweep_temp(string $directory): void
{
    if (random_int(1, 20) !== 1) {
        return;
    }
    foreach (glob($directory . DIRECTORY_SEPARATOR . '*') ?: [] as $file) {
        if (is_file($file) && filemtime($file) < time() - 600) {
            @unlink($file);
        }
    }
}
