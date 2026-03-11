<?php

declare(strict_types=1);

namespace Maatify\EmailDelivery\Renderer;

use Maatify\EmailDelivery\DTO\EmailPayloadInterface;
use Maatify\EmailDelivery\DTO\RenderedEmailDTO;
use Maatify\EmailDelivery\Exception\EmailRenderException;
use Throwable;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

class TwigEmailRenderer implements EmailRendererInterface
{
    private Environment $twig;

    /**
     * @param array<string, mixed> $globals
     *   Variables injected into every template automatically.
     *   Use for app-wide values like app_name, support_email, base_url.
     *   Callers do NOT need to pass these in the payload context.
     * @param string|false|null $cachePath
     *   Path to the Twig cache directory.
     *   - string: explicit path (used as-is)
     *   - null: enable default cache location
     *   - false:  always disabled (useful in tests)
     */
    public function __construct(
        ?string          $templatesPath = null,
        array            $globals       = [],
        string|false|null $cachePath    = null,
    ) {
        $root = getcwd();

        $templatesPath = $templatesPath ?? ($root . '/templates');

        if ($cachePath === null) {
            $cachePath = $root . '/var/cache/twig';
        }

        $loader     = new FilesystemLoader($templatesPath);
        $this->twig = new Environment($loader, [
            'strict_variables' => true,
            'cache'            => $cachePath,
        ]);

        foreach ($globals as $key => $value) {
            $this->twig->addGlobal($key, $value);
        }
    }

    public function render(
        string $templateKey,
        string $language,
        EmailPayloadInterface $payload
    ): RenderedEmailDTO {
        $templatePath = sprintf('emails/%s/%s.twig', $templateKey, $language);
        $data         = $payload->toArray();

        try {
            $template = $this->twig->load($templatePath);

            if (!$template->hasBlock('subject')) {
                throw new EmailRenderException(
                    "Template '{$templatePath}' is missing required block 'subject'."
                );
            }

            $subject = trim($template->renderBlock('subject', $data));

            if ($subject === '') {
                throw new EmailRenderException(
                    "Subject block in '{$templatePath}' rendered empty string."
                );
            }

            $htmlBody = $template->render($data);

            return new RenderedEmailDTO(
                subject:     $subject,
                htmlBody:    $htmlBody,
                templateKey: $templateKey,
                language:    $language
            );

        } catch (EmailRenderException $e) {
            throw $e;
        } catch (Throwable $e) {
            throw new EmailRenderException(
                "Failed to render email template '{$templateKey}' ({$language}): " . $e->getMessage(),
                0,
                $e
            );
        }
    }
}
