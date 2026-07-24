<?php
require_once __DIR__ . '/env.php';
require_once dirname(__DIR__) . '/vendor/autoload.php';

function stripe_client(): \Stripe\StripeClient
{
    static $client = null;
    if ($client === null) {
        $secret = $_ENV['STRIPE_SECRET_KEY'] ?? getenv('STRIPE_SECRET_KEY') ?: '';
        $client = new \Stripe\StripeClient($secret);
    }
    return $client;
}

function stripe_public_url(): string
{
    $url = $_ENV['APP_URL'] ?? getenv('APP_URL') ?: 'http://localhost:8080';
    return rtrim($url, '/');
}
