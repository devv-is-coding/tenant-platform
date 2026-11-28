<?php

/**
 * Read config/cashier.php's webhook secret afresh with the given environment.
 *
 * @param  array<string, string>  $environment
 */
function webhookSecretWith(array $environment): mixed
{
    $previous = [];

    foreach ($environment as $key => $value) {
        $previous[$key] = [$_SERVER[$key] ?? null, $_ENV[$key] ?? null];
        $_SERVER[$key] = $_ENV[$key] = $value;
    }

    try {
        return (require config_path('cashier.php'))['webhook']['secret'];
    } finally {
        foreach ($previous as $key => [$server, $env]) {
            $_SERVER[$key] = $server;
            $_ENV[$key] = $env;
        }
    }
}

test('the webhook secret is the one the Stripe CLI saved while its file holds one, and STRIPE_WEBHOOK_SECRET otherwise', function (?string $fileContents, string $expected) {
    $file = tempnam(sys_get_temp_dir(), 'stripe-secret');
    $path = $fileContents === null ? $file.'-missing' : $file;
    file_put_contents($file, $fileContents ?? '');

    try {
        expect(webhookSecretWith(['STRIPE_WEBHOOK_SECRET_FILE' => $path, 'STRIPE_WEBHOOK_SECRET' => 'whsec_from_env']))->toBe($expected);
    } finally {
        unlink($file);
    }
})->with([
    'the CLI saved its secret' => ["whsec_from_cli\n", 'whsec_from_cli'],
    'the file is empty' => ['', 'whsec_from_env'],
    'there is no file' => [null, 'whsec_from_env'],
]);

test('without a secret file the webhook secret is STRIPE_WEBHOOK_SECRET, as in production', function () {
    expect(webhookSecretWith(['STRIPE_WEBHOOK_SECRET_FILE' => '', 'STRIPE_WEBHOOK_SECRET' => 'whsec_from_env']))->toBe('whsec_from_env');
});
