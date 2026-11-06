# Create a Twig email template

[Guides](README.md) · [Renderer contract](../book/rendering.md) · [Direct-render function](../examples/render-email.php)

Use a loader root such as `/application/templates`. Queue/render key `welcome` and language `en`
resolve to `templates/emails/welcome/en.twig`. Templates require a nonempty `subject` block and
produce HTML; no plain-text body is generated.

## Layout file

File: `templates/emails/layout.twig`

```twig
<!doctype html>
<html lang="{{ lang }}">
<head><meta charset="UTF-8"><title>{% block subject %}{{ app_name }}{% endblock %}</title></head>
<body>{% block content %}{% endblock %}</body>
</html>
```

## Welcome template

File: `templates/emails/welcome/en.twig`

```twig
{% extends 'emails/layout.twig' %}
{% block subject %}Welcome, {{ name }}!{% endblock %}
{% block content %}<h1>Hello {{ name }}!</h1><p>Welcome to {{ app_name }}.</p>{% endblock %}
```

The `extends` path is relative to the loader root, so it includes `emails/`. Configure the renderer:

```php
use Maatify\EmailDelivery\Renderer\TwigEmailRenderer;

function createExampleRenderer(string $templatesRoot): TwigEmailRenderer
{
    return new TwigEmailRenderer(
        templatesPath: $templatesRoot,
        globals: ['app_name' => 'Example App'],
        cachePath: false,
    );
}
```

The worker injects `lang='en'` from queued language. For a direct render, pass `name` and `lang` in
`GenericEmailPayload`; [render-email.php](../examples/render-email.php) shows that call. Strict variables
mean missing names/globals fail rather than becoming empty strings. Subject decoding preserves literal
characters for SMTP while HTML context escaping remains in effect. Keep templates trusted; do not
solve missing context by applying `raw` to untrusted input.
