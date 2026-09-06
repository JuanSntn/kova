<?php

return [
    'content_security_policy' => env(
        'CONTENT_SECURITY_POLICY',
        "default-src 'self'; base-uri 'self'; frame-ancestors 'none'; form-action 'self'; object-src 'none'; img-src 'self' data:; font-src 'self'; style-src 'self' 'unsafe-inline'; script-src 'self'; connect-src 'self'",
    ),

    'permissions_policy' => env(
        'PERMISSIONS_POLICY',
        'camera=(), microphone=(), geolocation=(), payment=()',
    ),
];
