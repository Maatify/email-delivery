<?php

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use Maatify\EmailDelivery\Config\EmailTransportConfigDTO;
use Maatify\EmailDelivery\Renderer\TwigEmailRenderer;
use Maatify\EmailDelivery\Transport\SmtpEmailTransport;
use Maatify\EmailDelivery\Worker\EmailQueueWorker;
use Monolog\Handler\StreamHandler;
use Monolog\Logger;
use Maatify\Crypto\DX\CryptoProvider;
use Maatify\Crypto\Contract\CryptoContextProviderInterface;

// 1. Setup Database Connection
$pdo = new PDO('mysql:host=localhost;dbname=myapp', 'user', 'pass');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// 2. Setup Logger
$logger = new Logger('email-worker');
$logger->pushHandler(new StreamHandler(__DIR__ . '/../../worker.log', Logger::DEBUG));

// 3. Setup Cryptography Dependencies
// (These are needed to decrypt the recipient and payload from the database)
// These should typically come from your Dependency Injection container.
/** @var CryptoProvider $cryptoProvider */
$cryptoProvider = null; /* CryptoProvider instance */
/** @var CryptoContextProviderInterface $cryptoContextProvider */
$cryptoContextProvider = null; /* CryptoContextProviderInterface instance */

// 4. Setup Renderer
// Provide the path to your Twig templates directory
$templatePath = __DIR__ . '/../../emails';
$renderer = new TwigEmailRenderer($templatePath);

// 5. Setup SMTP Transport
$config = new EmailTransportConfigDTO(
    host: 'smtp.mailgun.org',
    port: 587,
    username: 'postmaster@yourdomain.com',
    password: 'your_smtp_password',
    fromAddress: 'no-reply@yourdomain.com',
    fromName: 'My Application',
    encryption: 'tls'
);
$transport = new SmtpEmailTransport($config);

// 6. Initialize Worker
$worker = new EmailQueueWorker(
    $pdo,
    $cryptoProvider,
    $renderer,
    $transport,
    $cryptoContextProvider,
    $logger
);

// 7. Run the Worker Loop
$logger->info('Starting EmailQueueWorker...');
echo "Worker started. Press Ctrl+C to stop.\n";

while (true) {
    try {
        // Process up to 50 pending emails in a batch
        $worker->processBatch(50);
    } catch (\Throwable $e) {
        $logger->critical('Fatal worker error: ' . $e->getMessage(), [
            'exception' => $e
        ]);
        // Pause to prevent aggressive error looping
        sleep(10);
    }

    // Brief pause to reduce database load
    sleep(2);
}
