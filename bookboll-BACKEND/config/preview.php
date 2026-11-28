<?php
return [
    'enabled'=>(bool) env('PAYMENTS_PREVIEW', false),
    'workspace'=>(bool) env('PAYMENTS_PREVIEW', false) && (bool) env('BOOKBOLL_WORKSPACE', false),
    'stripe_sandbox'=>(bool) env('WORKSPACE_STRIPE_SANDBOX', false),
    'admin_email'=>env('PREVIEW_ADMIN_EMAIL', 'preview-admin@bookboll.com'),
    'admin_password'=>env('PREVIEW_ADMIN_PASSWORD'),
];
