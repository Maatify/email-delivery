# Configure SMTP explicitly

[Guides](README.md) · [Transport behavior](../book/transport.md) · [Security policy](../../SECURITY.md)

Supply application-managed SMTP configuration to the readonly `EmailTransportConfigDTO`. The
constructor's required fields are host, port, username, password, fromAddress and fromName. Optional
fields are encryption=null, timeoutSeconds=10, charset='UTF-8' and debugLevel=0.

This reusable factory constructs an adapter; it does not send or connect until the application invokes it.
Arguments come from your secret/configuration system, not literal production credentials in an example.

```php
use Maatify\EmailDelivery\Config\EmailTransportConfigDTO;
use Maatify\EmailDelivery\Transport\SmtpEmailTransport;

function createStartTlsTransport(
    string $host,
    int $port,
    string $username,
    string $password,
    string $fromAddress,
    string $fromName,
): SmtpEmailTransport {
    return new SmtpEmailTransport(new EmailTransportConfigDTO(
        host: $host,
        port: $port,
        username: $username,
        password: $password,
        fromAddress: $fromAddress,
        fromName: $fromName,
        encryption: 'tls',
        debugLevel: 0,
    ));
}
```

Use the provider's STARTTLS port with `tls`, or explicitly choose `ssl` with its SMTPS endpoint/port.
The default null (and values other than these two strings) disable AutoTLS and leave SMTPSecure empty;
the DTO does not enforce an encryption policy. Configure it deliberately rather than assuming a default
prevents plaintext. Keep credentials and debug output restricted; diagnostics can expose message data.
The transport supplies HTML only, with optional Reply-To. SMTP return is not proof of mailbox receipt.
