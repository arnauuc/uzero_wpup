<?php
/*
 * wpup HTTP probe (UZEROWPUP-30). Temporary and single use: the tool uploads it
 * with a random name, calls it once and deletes it. It reports the PHP that the
 * web server runs (PHP_VERSION, SAPI and loaded extensions).
 *
 * PHP 5.x syntax on purpose (ADR-0007): it must also answer on sites below the
 * 7.4 minimum, so they can be detected and blocked. Linted with PHPCompatibility,
 * testVersion 5.4-.
 *
 * Anything but a valid token before the deadline gets a generic 404.
 * The placeholders below are filled in by App\Domain\Inventory\HttpProbe.
 */

$wpupTokenHash = '__WPUP_TOKEN_SHA256__';
// Lifetime counted from the file's own mtime, so the site's clock is the only
// one involved (no skew with the tool server).
$wpupTtl = (int) '__WPUP_TTL_SECONDS__';

function wpup_probe_not_found()
{
    header('HTTP/1.0 404 Not Found');
    header('Content-Type: text/plain');
    echo 'Not Found';
    exit;
}

function wpup_probe_equals($known, $given)
{
    if (strlen($known) !== strlen($given)) {
        return false;
    }
    $diff = 0;
    for ($i = 0; $i < strlen($known); $i++) {
        $diff |= ord($known[$i]) ^ ord($given[$i]);
    }

    return $diff === 0;
}

$wpupWritten = @filemtime(__FILE__);
if ($wpupWritten === false || time() > $wpupWritten + $wpupTtl) {
    @unlink(__FILE__);
    wpup_probe_not_found();
}

$wpupToken = isset($_SERVER['HTTP_X_WPUP_PROBE']) ? (string) $_SERVER['HTTP_X_WPUP_PROBE'] : '';

if ($wpupToken === '' || ! wpup_probe_equals($wpupTokenHash, hash('sha256', $wpupToken))) {
    wpup_probe_not_found();
}

// Single use: gone before answering.
@unlink(__FILE__);

$wpupExtensions = get_loaded_extensions();
sort($wpupExtensions);

header('Content-Type: application/json');
header('Cache-Control: no-store');
header('X-Robots-Tag: noindex');
echo json_encode(array(
    'php_version' => PHP_VERSION,
    'sapi' => PHP_SAPI,
    'extensions' => $wpupExtensions,
));
