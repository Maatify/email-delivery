# Rendering

The **Maatify Email Delivery** library utilizes the Twig templating engine to render both HTML and plain-text versions of your transactional emails. This decoupling allows you to maintain clean, reusable templates separate from your PHP logic.

## Twig Template Structure

Templates are organized in a directory structure that inherently supports internationalization (i18n) by language code. When you request a template to be rendered, you specify a `templateKey` (the base name of the template) and a `language` (e.g., `en`, `fr`).

The renderer looks for templates in the following structure:

```
emails/{templateKey}/{language}.twig
```

For instance, if you are rendering an order confirmation email (`order-confirmation`) in French (`fr`), the renderer will expect the file at `emails/order-confirmation/fr.twig`.

## Example Template

Below is an example of what a typical Twig email template might look like. Note that the worker automatically injects the `lang` variable into the context, so you can use `{{ lang }}` directly within your templates.

```twig
{# emails/welcome-email/en.twig #}

<!DOCTYPE html>
<html lang="{{ lang }}">
<head>
    <meta charset="UTF-8">
    <title>Welcome to Our Service</title>
</head>
<body>
    <h1>Hello, {{ user.firstName }}!</h1>

    <p>Thank you for joining us. We're excited to have you on board.</p>

    <p>Your account details:</p>
    <ul>
        <li>Username: {{ user.username }}</li>
        <li>Registration Date: {{ registrationDate|date('Y-m-d') }}</li>
    </ul>

    <p>If you have any questions, feel free to contact our support team.</p>

    <p>Best regards,<br>The Team</p>
</body>
</html>
```

## Creating a Renderer

You configure the `TwigEmailRenderer` by providing the base path to your templates directory:

```php
use Maatify\EmailDelivery\Renderer\TwigEmailRenderer;

$renderer = new TwigEmailRenderer('/path/to/your/templates/directory');
```

The renderer ensures that both the layout and the dynamic data passed in the `GenericEmailPayload` are properly compiled and merged into the final email content before transmission.
