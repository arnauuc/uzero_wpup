<?php

declare(strict_types=1);

use App\Domain\Secrets\Secret;
use App\Domain\Secrets\SecretRedactor;

const DB_PASSWORD = 'S3cr3t-p@ss/w0rd"x';

it('masks a registered secret in all its encodings', function (): void {
    $redactor = new SecretRedactor;
    $redactor->register(new Secret(DB_PASSWORD));

    $text = implode(' | ', [
        'plain '.DB_PASSWORD,
        'b64 '.base64_encode(DB_PASSWORD),
        'url '.rawurlencode(DB_PASSWORD),
        'form '.urlencode(DB_PASSWORD),
        'json '.json_encode(['p' => DB_PASSWORD]),
    ]);

    $redacted = $redactor->redact($text);

    foreach ([DB_PASSWORD, base64_encode(DB_PASSWORD), rawurlencode(DB_PASSWORD), urlencode(DB_PASSWORD), substr((string) json_encode(DB_PASSWORD), 1, -1)] as $leak) {
        expect(str_contains($redacted, $leak))->toBeFalse();
    }
    expect($redacted)->toContain(SecretRedactor::MASK);
});

it('does not mask very short registered values on their own', function (): void {
    $redactor = new SecretRedactor;
    $redactor->register('abc');

    expect($redactor->redact('abcdef'))->toBe('abcdef');
});

it('forgets registered secrets on reset', function (): void {
    $redactor = new SecretRedactor;
    $redactor->register(DB_PASSWORD);
    $redactor->reset();

    expect($redactor->redact('x '.DB_PASSWORD))->toBe('x '.DB_PASSWORD);
});

it('masks common secret shapes without registration', function (string $input, string $leak): void {
    $out = (new SecretRedactor)->redact($input);

    expect(str_contains($out, $leak))->toBeFalse()
        ->and($out)->toContain(SecretRedactor::MASK);
})->with([
    'openssh key' => ["key:\n-----BEGIN OPENSSH PRIVATE KEY-----\nAAAAb3BlbnNzaC1rZXk\n-----END OPENSSH PRIVATE KEY-----\n", 'AAAAb3BlbnNzaC1rZXk'],
    'rsa key' => ["-----BEGIN RSA PRIVATE KEY-----\nMIIEpAIBAAKCAQ\n-----END RSA PRIVATE KEY-----", 'MIIEpAIBAAKCAQ'],
    'dsn' => ['mysql://wp_user:hunter2xyz@db:3306/wp', 'hunter2xyz'],
    'sftp url' => ['sftp://backup:Pa55word@host.example/path', 'Pa55word'],
    'mysqldump arg' => ['mysqldump --password=hunter2xyz wp', 'hunter2xyz'],
    'quoted arg' => ["wp db export --pass='hunter 2xyz'", 'hunter 2xyz'],
    'env var' => ['MYSQL_PWD=hunter2xyz mysqldump wp', 'hunter2xyz'],
    'custom env' => ['QUIPU_API_SECRET="abc123def" php run', 'abc123def'],
    'bearer' => ['Authorization: Bearer eyJhbGciOiJIUzI1NiJ9.payload.sig', 'eyJhbGciOiJIUzI1NiJ9'],
    'json field' => ['{"user":"a","password":"hunter2xyz"}', 'hunter2xyz'],
]);

it('leaves ordinary output untouched', function (): void {
    $text = "Success: Updated 3 of 3 plugins.\nwoocommerce 9.1.0 -> 9.2.0\nhttps://example.com/shop";

    expect((new SecretRedactor)->redact($text))->toBe($text);
});

it('redacts nested arrays and Secret objects', function (): void {
    $redactor = new SecretRedactor;
    $redactor->register(DB_PASSWORD);

    $out = $redactor->redactArray([
        'cmd' => 'mysql -p'.DB_PASSWORD,
        'nested' => ['secret' => new Secret('whatever'), 'n' => 3],
    ]);

    expect($out)->toBe([
        'cmd' => 'mysql -p'.SecretRedactor::MASK,
        'nested' => ['secret' => SecretRedactor::MASK, 'n' => 3],
    ]);
});
