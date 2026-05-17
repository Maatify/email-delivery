<?php

declare(strict_types=1);

namespace Maatify\EmailDelivery\Queue\DTO;

/**
 * EmailQueuePayloadDTO
 *
 * Represents the exact payload to be stored in the email_queue.
 * This DTO carries the context and metadata required for queuing an email.
 */
final readonly class EmailQueuePayloadDTO
{
    /**
     * @param array<string, mixed> $context
     * @param string $templateKey
     * @param string $language
     * @param string|null $replyTo Optional Reply-To email address.
     *                             When set, the transport will add a Reply-To header
     *                             so replies go to this address instead of the sender.
     */
    public function __construct(
        public array $context,
        public string $templateKey,
        public string $language,
        public ?string $replyTo = null,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'context'     => $this->context,
            'templateKey' => $this->templateKey,
            'language'    => $this->language,
            'replyTo'     => $this->replyTo,
        ];
    }
}
