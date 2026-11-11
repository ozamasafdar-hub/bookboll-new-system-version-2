<?php

return [
    // The application has no signing key and no “mark complete” endpoint.
    // Operations supplies a public key and an approval signed outside the app.
    'public_key_file' => env('PAYMENT_RELEASE_PUBLIC_KEY_FILE'),
    'approval_file' => env('PAYMENT_RELEASE_APPROVAL_FILE', storage_path('app/private/payment-release/approval.json')),
    'policy_file' => base_path('../docs/payments/rules-v1.json'),
];
