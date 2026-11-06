# Rendering

[Book](README.md) · [Create a template](../how-to/create-template.md) · [Direct-render example](../examples/render-email.php)

`TwigEmailRenderer::render(templateKey, language, EmailPayloadInterface)` returns
`RenderedEmailDTO(subject, htmlBody, templateKey, language)`. It does not generate a plain-text body
or `AltBody`. `GenericEmailPayload` wraps render-ready context; it is not the queued payload DTO.

The constructor is `TwigEmailRenderer(?string $templatesPath=null, array $globals=[], string|false|null
$cachePath=null)`. Default templates are `getcwd()/templates`; default cache is `getcwd()/var/cache/twig`.
An explicit string chooses a cache directory; false disables caching. Templates use strict variables.

The lookup path relative to the loader root is `emails/{templateKey}/{language}.twig`. A nonempty
`subject` block is required. Subject output is trimmed and HTML entities are decoded to a literal SMTP
subject; HTML body rendering remains separate. Missing template/subject, empty subject and Twig
errors raise `EmailRenderException`.

The **worker** injects `lang` from payload language before rendering. Calling the renderer directly
does not inject it; pass `lang` yourself if the template uses it. Configure app-wide values using the
`globals` constructor argument. The template guide provides matching layout paths and complete
blocks; the direct-render example returns the DTO without invoking any transport.

Use trusted templates and normal Twig escaping for untrusted values. Do not assume the transport
escapes arbitrary HTML or that the renderer automatically produces a plain-text alternative.
