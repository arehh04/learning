<?php

namespace App\Support;

final class SentMessageResult
{
    public function __construct(
        public readonly string $externalMessageId,
    ) {
    }
}
