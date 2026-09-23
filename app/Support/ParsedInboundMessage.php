<?php

namespace App\Support;

final class ParsedInboundMessage
{
    public function __construct(
        public readonly string $externalEventId,
        public readonly string $externalContactId,
        public readonly ?string $contactName,
        public readonly string $body,
        public readonly array $rawPayload,
    ) {
    }
}
