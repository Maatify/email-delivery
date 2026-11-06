<?php

declare(strict_types=1);

namespace Maatify\EmailDelivery\Documentation\Examples;

use Maatify\EmailDelivery\DTO\GenericEmailPayload;
use Maatify\EmailDelivery\DTO\RenderedEmailDTO;
use Maatify\EmailDelivery\Renderer\EmailRendererInterface;

/** Renderer alone does not inject lang and generates no plain-text body. No transport is called. */
function renderWelcome(EmailRendererInterface $renderer, string $name, string $language = 'en'): RenderedEmailDTO
{
    return $renderer->render('welcome', $language, new GenericEmailPayload(['name' => $name, 'lang' => $language]));
}
