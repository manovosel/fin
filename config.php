<?php
return [
    'name' => 'FIN Planner',
    'session_name' => 'FINSESSID',
    // Keep the app session valid at the public origin root, including behind a /fin upstream mapping.
    'session_cookie_path' => '/',
    // Currency initializes a new database and must match an initialized database forever.
    'currency' => 'DKK',
    // Helper currency is input/presentation only and may be changed without rewriting finance.
    'helper_currency' => 'EUR',
    // Server-only authentication storage. Override these together in config.local.php for development.
    'auth' => [
        'master_pin_file' => '/etc/fin/master-pin.hash',
        'throttle_file' => '/var/lib/fin/master-pin-throttle.json',
        'api_token_file' => '/etc/fin/readonly-token.hash',
        'file_group' => 'www-data',
        'pin_failure_delay_us' => 250000,
    ],
    // WebAuthn relying-party id and origin. These tracked defaults are neutral
    // placeholders; each installation must supply its own values in the ignored
    // config.local.php, because a relying-party id only works on its own host.
    'webauthn_rp_id' => 'example.com',
    'webauthn_origin' => 'https://example.com',
];
