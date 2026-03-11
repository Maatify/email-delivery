# Transport

The **Maatify Email Delivery** library utilizes a robust SMTP transport layer (`SmtpEmailTransport`) backed by PHPMailer to physically send your generated emails to their intended recipients. The transport layer is responsible for the actual transmission over the network.

## SMTP Transport Configuration

Configuration for the SMTP connection is handled entirely through the `EmailTransportConfigDTO` class. This Data Transfer Object provides a structured, type-safe way to define your mail server settings.

When instantiating the `SmtpEmailTransport`, you must provide an instance of `EmailTransportConfigDTO` which contains the following parameters:

-   `host` (string): The hostname of your SMTP server (e.g., `smtp.mailgun.org`).
-   `port` (int): The port to connect to (typically `587` for TLS or `465` for SSL).
-   `username` (string): The username for SMTP authentication.
-   `password` (string): The password for SMTP authentication.
-   `fromAddress` (string): The default email address the message should appear to be sent from (e.g., `noreply@yourdomain.com`).
-   `fromName` (string): The default name associated with the `fromAddress` (e.g., `Your App Name`).
-   `encryption` (?string): Optional encryption method. Usually `'tls'`, `'ssl'`, or `null` for unencrypted connections.
-   `timeoutSeconds` (int): The maximum time (in seconds) to wait for a connection to the SMTP server. Defaults to `10`.
-   `charset` (string): The character set for the email content. Defaults to `'UTF-8'`.
-   `debugLevel` (int): Optional debug level for troubleshooting connection issues (passed directly to PHPMailer). Defaults to `0` (off).

```php
use Maatify\EmailDelivery\Config\EmailTransportConfigDTO;

$config = new EmailTransportConfigDTO(
    host: 'smtp.example.com',
    port: 587,
    username: 'your_username',
    password: 'your_secure_password',
    fromAddress: 'notifications@example.com',
    fromName: 'My Application'
);
```

By encapsulating all configuration within this DTO, you can easily load these settings from environment variables or a configuration file and inject them into the transport layer. This keeps the transport implementation clean and decoupled from your application's specific configuration mechanism.
