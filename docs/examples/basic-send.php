<?php

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use Maatify\EmailDelivery\Queue\DTO\EmailQueuePayloadDTO;
use Maatify\EmailDelivery\Queue\PdoEmailQueueWriter;
use Maatify\Crypto\DX\CryptoProvider;
use Maatify\Crypto\Contract\CryptoContextProviderInterface;

// 1. Database Connection (PDO)
$pdo = new PDO('mysql:host=127.0.0.1;dbname=app_db', 'user', 'password');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// 2. Encryption Setup
// NOTE: Provide your actual crypto dependencies here.
// These should typically come from your Dependency Injection container.
/** @var CryptoProvider $cryptoProvider */
$cryptoProvider = null; /* CryptoProvider instance */

/** @var CryptoContextProviderInterface $cryptoContextProvider */
$cryptoContextProvider = null; /* CryptoContextProviderInterface instance */

// 3. Initialize the Queue Writer
$queueWriter = new PdoEmailQueueWriter(
    $pdo,
    $cryptoProvider,
    $cryptoContextProvider
);

// 4. Create the Email Payload
$context = [
    'user' => [
        'firstName' => 'Jane',
        'lastName' => 'Doe',
    ],
    'orderId' => 'ORD-98765',
    'total' => 125.50,
];

$payload = new EmailQueuePayloadDTO(
    templateKey: 'order-confirmation',
    language: 'en',
    context: $context
);

// 5. Enqueue the Email
$recipientEmail = 'jane.doe@example.com';

try {
    $queueWriter->enqueue(
        entityType: 'user',
        entityId: '123',
        recipientEmail: $recipientEmail,
        payload: $payload,
        senderType: 1,
        priority: 10
    );
    echo "Successfully queued email for $recipientEmail\n";
} catch (\Throwable $e) {
    echo 'Failed to queue email: ' . $e->getMessage() . "\n";
}
