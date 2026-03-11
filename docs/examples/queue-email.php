<?php

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use Maatify\EmailDelivery\Queue\DTO\EmailQueuePayloadDTO;
use Maatify\EmailDelivery\Queue\PdoEmailQueueWriter;
use Maatify\Crypto\DX\CryptoProvider;
use Maatify\Crypto\Contract\CryptoContextProviderInterface;

// 1. Establish Database Connection
$pdo = new PDO('mysql:host=localhost;dbname=myapp', 'root', '');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// 2. Setup Encryption dependencies
// These are required by the PdoEmailQueueWriter to encrypt the payload
/** @var CryptoProvider $cryptoProvider */
$cryptoProvider = null; // Normally injected by your DI container

/** @var CryptoContextProviderInterface $cryptoContext */
$cryptoContext = null; // Normally injected by your DI container

// 3. Initialize Queue Writer
$writer = new PdoEmailQueueWriter(
    $pdo,
    $cryptoProvider,
    $cryptoContext
);

// 4. Create an Email Payload
// The 'context' array contains variables passed to your Twig template
$payload = new EmailQueuePayloadDTO(
    templateKey: 'welcome-email', // This maps to emails/welcome-email/
    language: 'es',               // This maps to emails/welcome-email/es.twig
    context: [
        'user' => [
            'firstName' => 'Carlos',
            'lastName' => 'Santana'
        ],
        'registrationDate' => date('Y-m-d')
    ]
);

$recipientEmail = 'carlos@example.com';

// 5. Enqueue the message for asynchronous delivery
try {
    $writer->enqueue(
        recipient: $recipientEmail,
        payload: $payload,
        priority: 5 // Optional: 5 is higher priority than the default 10
    );
    echo "Email successfully enqueued!\n";
} catch (\Throwable $e) {
    echo 'Error enqueueing email: ' . $e->getMessage() . "\n";
}
