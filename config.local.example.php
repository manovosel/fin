<?php

// Copy to config.local.php (gitignored) and use absolute paths.
// These files remain outside the FIN web root and outside SQLite.
return [
    // 'currency' => 'DKK', // Initialization-only; must match an existing database.
    // 'helper_currency' => 'EUR', // May be changed; financial amounts remain unchanged.
    'auth' => [
        'master_pin_file' => '/home/your-user/.config/fin/master-pin.hash',
        'throttle_file' => '/home/your-user/.config/fin/master-pin-throttle.json',
        'api_token_file' => '/home/your-user/.config/fin/readonly-token.hash',
        // Leave empty for a single-user local development directory.
        'file_group' => '',
    ],
    // WebAuthn relying-party id and origin. Localhost values work for local
    // development only; a deployed installation must set its own host here.
    'webauthn_rp_id' => 'localhost',
    'webauthn_origin' => 'http://localhost:8080',
];
