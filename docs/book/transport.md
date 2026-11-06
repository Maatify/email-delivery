# SMTP transport

[Book](README.md) · [Configure SMTP](../how-to/configure-smtp.md) · [Security architecture](../../SECURITY_AUDIT.md)

`SmtpEmailTransport(EmailTransportConfigDTO $config)` implements `EmailTransportInterface`.
`send(string $recipientEmail, RenderedEmailDTO $email, ?string $replyToEmail=null): void` is the
network boundary. It creates PHPMailer in exception mode, configures SMTP authentication, sets
From/recipient, optional Reply-To, literal subject and HTML body, then calls `send()`.

| Configuration | Actual behavior |
| --- | --- |
| `encryption='tls'` | STARTTLS |
| `encryption='ssl'` | SMTPS |
| null (the default) or another value | Empty SMTPSecure and AutoTLS disabled |
| `timeoutSeconds=10` | PHPMailer timeout |
| `charset='UTF-8'`, `debugLevel=0` | Default character set/debug level |

The readonly DTO does not enforce mandatory encryption, validate a provider's TLS policy or prevent
plaintext configuration. Set `tls`/`ssl` explicitly with the correct endpoint/port. Do not log credentials
or expose SMTP debug output to customers. Configure secrets through your application, not committed
examples; the package does not supply an environment loader.

PHPMailer and other caught failures are wrapped as `EmailTransportException`. A returned call is not
a mailbox arrival/read receipt. Timeouts can be ambiguous, and retries may send again. The transport
sets no plain-text alternative and does not itself escape arbitrary HTML supplied to it.
