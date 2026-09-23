<?php

namespace App\Http\Controllers;

use App\Models\Channel;
use App\Services\Channels\TelegramAdapter;
use App\Services\MessageIngestionService;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

class TelegramWebhookController extends Controller
{
    public function __invoke(
        Request $request,
        TelegramAdapter $adapter,
        MessageIngestionService $ingestionService,
    ): SymfonyResponse {
        if ($request->header('X-Telegram-Bot-Api-Secret-Token') !== config('services.telegram.webhook_secret')) {
            return response()->noContent(403);
        }

        $channel = Channel::where('type', 'telegram')->where('is_active', true)->firstOrFail();

        $parsed = $adapter->parseInbound($request->all());

        $ingestionService->ingest($channel, $parsed);

        return response()->noContent(200);
    }
}
