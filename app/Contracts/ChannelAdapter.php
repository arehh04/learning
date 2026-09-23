<?php

namespace App\Contracts;

use App\Support\ParsedInboundMessage;
use App\Support\SentMessageResult;

interface ChannelAdapter
{
    public function parseInbound(array $payload): ParsedInboundMessage;

    public function send(string $externalContactId, string $body): SentMessageResult;
}
