<?php
return [
    'enabled'=>(bool) env('PAYMENTS_PREVIEW', false),
    'admin_email'=>env('PREVIEW_ADMIN_EMAIL', 'preview-admin@bookboll.com'),
    'admin_password'=>env('PREVIEW_ADMIN_PASSWORD'),
];
