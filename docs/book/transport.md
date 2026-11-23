# SMTP transport

[Book](README.md) · [Configure SMTP](../how-to/configure-smtp.md) · [Security architecture](../../SECURITY_AUDIT.md)

`SmtpEmailTransport(EmailTransportConfigDTO $config)` implements `EmailTransportInterface`.
`send(string $recipientEmail, RenderedEmailDTO $email, ?string $replyToEmail=null): void` is the
network boundary. It creates PHPMailer in exception mode, configures SMTP authentication, sets
From/recipient, optional Reply-To, literal subject and HTML body, then calls `send()`. The published
1.2.0 dependency accepts PHPMailer 6.9; PHPMailer 7 support is prepared for the unpublished 1.3.0
candidate. See [PHPMailer 6.9 and 7](#phpmailer-69-and-7).

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

<a id="phpmailer-69-and-7"></a>

## PHPMailer 6.9 and 7 — 1.3.0 candidate

Published 1.2.0 accepts `phpmailer/phpmailer` `^6.9`. The prepared, unpublished 1.3.0 candidate accepts
`^6.9 || ^7.0`. The transport creates its own `PHPMailer` (it does
not subclass it) and uses only `isSMTP()`, `SMTPDebug`, `Host`, `SMTPAuth`, `Username`, `Password`,
`SMTPSecure` with `ENCRYPTION_STARTTLS`/`ENCRYPTION_SMTPS`, `SMTPAutoTLS`, `Port`, `Timeout`, `CharSet`,
`setFrom()`, `addAddress()`, `addReplyTo()`, `isHTML()`, `Subject`, `Body`, `send()` and
`PHPMailer\PHPMailer\Exception`. PHPMailer 7.0.0 is 6.11.1 with a major version, so 7.x carries 6.11's
changes too. What 7.x changes on the sending path, and why the transport is unaffected:

| PHPMailer change | Effect on this transport |
| --- | --- |
| 7.0.0: `lang()`, `setLanguage()`, `$language` are static (child classes) | none: no subclass, no language calls |
| 6.11.0 (in 7.x): the protected `ReplyTo` array has a new format (child classes) | none: Reply-To goes through `addReplyTo()` |
| 7.1.0: strict checks on `Encoding` values (case-insensitive since 7.1.1) | none: `Encoding` is not set |
| 7.1.0: line breaks stripped from `XMailer`, `ContentType`, `CharSet` | `CharSet` comes from configuration; only a value containing line breaks changes |
| 7.1.0: `MessageDate` validated; no crash when `Mailer` is empty | none: neither is set by hand (`isSMTP()` sets `Mailer`) |
| 7.2.0: XCLIENT expects 220 and `xtext`-encodes raw values (a stated BC break) | none: XCLIENT is not used |
| 7.2.0: long address headers folded; no `QUIT` after a connection lost during `DATA` | headers stay valid; a failed send still throws, wrapped as `EmailTransportException` |
| 7.0.1, 7.2.0, 6.11.0: XOAUTH2 handling, S/MIME line endings, CIDs in `msgHTML()`, qmail, `parseAddresses()` and `pcre8` deprecations | none: not used |

The 1.3.0 candidate's CI sends through the transport to an isolated test SMTP server on PHPMailer 6.9.0
and on the latest 7.x, and checks the delivered sender, recipient, Reply-To, UTF-8 subject, HTML body and `X-Mailer`.
