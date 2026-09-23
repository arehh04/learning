<?php

namespace App\Http\Controllers;

use App\Models\Channel;
use App\Services\Channels\TelegramAdapter;
use App\Services\MessageIngestionService;
use Illuminate\Http\Request;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

class TelegramWebhookController extends Controller
{
    public function __invoke(
        Request $request,
        TelegramAdapter $adapter,
        MessageIngestionService $ingestionService,
    ): SymfonyResponse {
        $configuredSecret = config('services.telegram.webhook_secret');
        $providedSecret = $request->header('X-Telegram-Bot-Api-Secret-Token');

        if (empty($configuredSecret) || ! hash_equals($configuredSecret, (string) $providedSecret)) {
            return response()->noContent(403);
        }

        $channel = Channel::where('type', 'telegram')->where('is_active', true)->firstOrFail();

        try {
            $parsed = $adapter->parseInbound($request->all());
        } catch (RuntimeException) {
            // Telegram sends update types (edited_message, callback_query, my_chat_member, etc.)
            // that this app doesn't ingest yet. Acknowledge with 200 so Telegram doesn't retry.
            return response()->noContent(200);
        }

        $ingestionService->ingest($channel, $parsed);

        return response()->noContent(200);
    }
}
