# How to Create a Template

The **Maatify Email Delivery** library utilizes the Twig templating engine to render both HTML and plain-text versions of your transactional emails. This decoupling allows you to maintain clean, reusable templates separate from your PHP logic.

## 1. Organizing Templates

Templates are organized in a directory structure that inherently supports internationalization (i18n) by language code. When you request a template to be rendered, you specify a `templateKey` (the base name of the template) and a `language` (e.g., `en`, `fr`).

The renderer looks for templates in the following structure:

```
emails/{templateKey}/{language}.twig
```

For instance, if you are rendering an order confirmation email (`order-confirmation`) in French (`fr`), the renderer will expect the file at `emails/order-confirmation/fr.twig`.

## 2. Using Twig Blocks

You can create a base layout template that contains common elements like a header, footer, and styling. Your individual email templates can then extend this layout.

```twig
{# emails/layout.twig #}
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>{% block subject %}Default Subject{% endblock %}</title>
</head>
<body>
    <header>
        <h1>My Company</h1>
    </header>

    <main>
        {% block content %}{% endblock %}
    </main>

    <footer>
        <p>Copyright © {{ "now"|date("Y") }}</p>
    </footer>
</body>
</html>
```

## 3. Extending the Layout

Your specific email templates (e.g., `emails/welcome-email/en.twig`) can extend the layout and override the necessary blocks. Notice the injected `lang` variable provided by the worker.

```twig
{# emails/welcome-email/en.twig #}
{% extends "layout.twig" %}

{% block subject %}Welcome, {{ user.firstName }}!{% endblock %}

{% block content %}
    <h2>Hello {{ user.firstName }}!</h2>
    <p>We are glad you joined us in {{ lang|upper }}.</p>

    {% if isPremium %}
        <p>Thank you for subscribing to our premium plan.</p>
    {% endif %}
{% endblock %}
```

## 4. Automatic Language Injection

The `EmailQueueWorker` automatically injects a `lang` variable into the context array before rendering. This means you don't need to explicitly pass the language code in every single payload; it is derived from the `EmailQueuePayloadDTO`'s `language` property. You can use `{{ lang }}` directly in your Twig templates (as shown above) for conditional logic or localized content rendering.
