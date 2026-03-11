# How to Send an Email

To send an email using the **Maatify Email Delivery** library, you don't send it directly. Instead, you enqueue it. The background worker will pick it up and handle the actual SMTP transmission.

## 1. Create the Payload

First, you need to define what you want to send. You do this by creating an `EmailQueuePayloadDTO`. This object holds the template information and the variables needed to render it.

```php
use Maatify\EmailDelivery\Queue\DTO\EmailQueuePayloadDTO;

// The data your Twig template needs
$context = [
    'user' => [
        'firstName' => 'John',
        'lastName' => 'Doe',
    ],
    'orderId' => '12345',
];

$payload = new EmailQueuePayloadDTO(
    templateKey: 'order-confirmation', // Matches emails/order-confirmation/
    language: 'en',                    // Matches emails/order-confirmation/en.twig
    context: $context
);
```

## 2. Enqueue the Email

Next, use your configured `PdoEmailQueueWriter` to save the payload to the database. The writer handles encrypting the recipient's address and the payload data automatically.

```php
// Assuming $queueWriter is an injected instance of PdoEmailQueueWriter

$recipientEmail = 'john.doe@example.com';

// Enqueue the email
$queueWriter->enqueue(
    entityType: 'user',
    entityId: '123',
    recipientEmail: $recipientEmail,
    payload: $payload,
    senderType: 1,
    priority: 10
);

echo "Email successfully queued for delivery!";
```

Once enqueued, the `EmailQueueWorker` (running in the background) will discover the new record, decrypt it, render the `order-confirmation` template with the provided context, and send it via SMTP.
