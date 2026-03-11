# How to Setup the Worker

The core of the **Maatify Email Delivery** library is the `EmailQueueWorker`. This background process continuously polls the database for pending emails, decrypts their payloads, renders the templates, and sends them via SMTP.

To run the worker, you need a long-running PHP script.

## The Worker Script

Create a script (e.g., `worker-runner.php`) that initializes the worker and runs a continuous loop. This script must assemble all the necessary dependencies.

```php
<?php

use Maatify\Crypto\DX\CryptoProvider;
use Maatify\EmailDelivery\Worker\EmailQueueWorker;
use Monolog\Logger;
// ... other imports for PDO, Config, Renderer, Transport, and CryptoContextProvider ...

// 1. Setup Dependencies
$pdo = new PDO('mysql:host=localhost;dbname=mydb', 'user', 'pass');
$logger = new Logger('email_worker');

// (Assume CryptoProvider, EmailRendererInterface, EmailTransportInterface,
// and CryptoContextProviderInterface are initialized here based on your app's config)
$cryptoProvider = /* ... */;
$renderer = /* ... */;
$transport = /* ... */;
$cryptoContextProvider = /* ... */;

// 2. Initialize the Worker
$worker = new EmailQueueWorker(
    $pdo,
    $cryptoProvider,
    $renderer,
    $transport,
    $cryptoContextProvider,
    $logger
);

// 3. The Continuous Loop
echo "Starting EmailQueueWorker...\n";

while (true) {
    try {
        // Process a batch of pending emails (e.g., up to 50 at a time)
        $worker->processBatch(50);
    } catch (\Throwable $e) {
        // Log critical errors that escape the worker's internal handling
        $logger->critical('Worker encountered a fatal error: ' . $e->getMessage());

        // Prevent a tight error loop from burning CPU
        sleep(10);
    }

    // Pause briefly before polling again to reduce database load
    sleep(2);
}
```

## Running the Worker in Production

Running a PHP script continuously in production requires a process manager. The most common and robust way to handle this is using **Supervisor**.

### Supervisor Configuration

Create a configuration file for Supervisor (e.g., `/etc/supervisor/conf.d/email-worker.conf`):

```ini
[program:email-worker]
process_name=%(program_name)s_%(process_num)02d
command=php /path/to/your/project/worker-runner.php
autostart=true
autorestart=true
user=www-data
numprocs=2 ; Run multiple instances if you have high volume
redirect_stderr=true
stdout_logfile=/var/log/email-worker.log
```

After creating the file, update Supervisor:

```bash
sudo supervisorctl reread
sudo supervisorctl update
sudo supervisorctl start email-worker:*
```

Supervisor ensures that the worker starts when your server boots and automatically restarts it if it crashes.
