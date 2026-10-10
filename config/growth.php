<?php
return [
    'razorpay' => ['key' => env('RAZORPAY_KEY_ID'), 'secret' => env('RAZORPAY_KEY_SECRET'), 'webhook_secret' => env('RAZORPAY_WEBHOOK_SECRET')],
    'grace_days' => (int) env('BILLING_GRACE_DAYS', 3),
    'graph_version' => env('WHATSAPP_GRAPH_VERSION', 'v23.0'),
];
