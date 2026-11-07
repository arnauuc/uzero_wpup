<?php

declare(strict_types=1);

/*
 * Stand-in for verifier/dist/bin.js in the feature tests: speaks protocol v1
 * and behaves according to the site name. It also records the environment
 * it was given, for the test that no secret reaches the verifier.
 */
$input = json_decode((string) stream_get_contents(STDIN), true);
if (! is_array($input) || ! is_string($input['output_dir'] ?? null) || ! is_array($input['site'] ?? null)) {
    exit(2);
}
/** @var array{output_dir: string, run_id: int, site: array{name: string}, urls: list<array{path: string, profile: string}>, viewports: list<array{name: string, width: int}>} $input */
$dir = $input['output_dir'];
file_put_contents("{$dir}/env.json", json_encode(getenv()));
$error = static fn (string $code, string $message, array $issues = []): string => json_encode(['version' => 1, 'error' => ['code' => $code, 'message' => $message, 'issues' => $issues]])."\n";

switch ($input['site']['name']) {
    case 'invalid':
        echo $error('invalid_input', 'input does not match protocol version 1', ['urls.0.path: invalid_format']);
        exit(2);
    case 'crash':
        fwrite(STDERR, "Error: boom\n    at /app/verifier/dist/verify.js:1:1 DB_PASSWORD=leak\n");
        echo $error('internal', 'browser crashed');
        exit(1);
    case 'slow':
        sleep(30);
        exit(0);
    case 'silent':
        exit(0);
    case 'garbage':
        file_put_contents("{$dir}/result.json", json_encode(['version' => 1, 'run_id' => $input['run_id'], 'results' => [[
            'path' => '/', 'profile' => 'home', 'viewport' => 'desktop', 'http_status' => 200, 'screenshot' => '../../etc/passwd.png',
            'console_errors' => [], 'resource_errors' => [], 'timings' => ['navigation_ms' => 1], 'error' => null,
        ]], 'verifier_errors' => []]));
        exit(0);
    case 'huge':
        file_put_contents("{$dir}/result.json", str_repeat(' ', 8192));
        exit(0);
}

$results = [];
foreach ($input['viewports'] as $viewport) {
    foreach ($input['urls'] as $index => $url) {
        $results[] = [
            'path' => $url['path'], 'profile' => $url['profile'], 'viewport' => $viewport['name'],
            'http_status' => $url['path'] === '/broken' ? 500 : 200, 'screenshot' => "{$viewport['name']}/{$index}.png",
            'console_errors' => ['Uncaught TypeError: x is undefined'],
            'resource_errors' => [['url' => 'https://botiga.test/missing.png', 'status' => 404, 'failure' => null]],
            'timings' => ['navigation_ms' => 120], 'error' => null,
        ];
    }
}
file_put_contents("{$dir}/result.json", json_encode(['version' => 1, 'run_id' => $input['run_id'], 'results' => $results, 'verifier_errors' => []]));
