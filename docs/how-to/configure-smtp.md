# How to Configure SMTP

The **Maatify Email Delivery** library utilizes a robust SMTP transport layer (`SmtpEmailTransport`) to actually deliver your generated emails. To configure this transport, you use the `EmailTransportConfigDTO` class.

## The Configuration DTO

The `EmailTransportConfigDTO` is a simple, readonly class that holds all the necessary settings for your SMTP connection.

```php
use Maatify\EmailDelivery\Config\EmailTransportConfigDTO;

$config = new EmailTransportConfigDTO(
    host: 'smtp.mailgun.org',       // Your SMTP server address
    port: 587,                      // Typical ports: 587 (TLS), 465 (SSL)
    username: 'postmaster@yourdomain.com', // Your SMTP username
    password: 'your_super_secret_password',// Your SMTP password
    fromAddress: 'no-reply@yourdomain.com',// Default sender email address
    fromName: 'Your Application Name',     // Default sender name
    encryption: 'tls',              // Optional: 'tls', 'ssl', or null
    timeoutSeconds: 15,             // Optional: connection timeout (default 10)
    charset: 'UTF-8',               // Optional: character encoding (default UTF-8)
    debugLevel: 0                   // Optional: debug output level for PHPMailer (default 0)
);
```

## Creating the Transport Instance

Once you have your configuration object, you instantiate the transport layer. This transport layer is then injected into the `EmailQueueWorker`, which handles the actual transmission of rendered emails.

```php
use Maatify\EmailDelivery\Transport\SmtpEmailTransport;

$transport = new SmtpEmailTransport($config);

// This $transport instance is now ready to be injected into the EmailQueueWorker
```

## Best Practices

*   **Environment Variables:** Always store your SMTP credentials (username, password, host, port) in environment variables rather than hardcoding them into your PHP files. Use `getenv()` or your framework's `.env` parsing to populate the DTO.
*   **Debug Level:** When troubleshooting delivery issues, you can increase the `debugLevel` (e.g., to `1` or `2`) to see the detailed communication between your application and the SMTP server. Remember to set this back to `0` in production environments.
