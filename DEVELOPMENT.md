# Development Notes

Operational knowledge for running this app locally on Windows via Docker/Sail. Written down here because it was expensive to (re)discover — read this before debugging something that looks like a bug but might just be one of these.

## Starting the environment

```powershell
docker compose up -d
```

Run this from PowerShell, not Git Bash — Git Bash (MINGW64) mis-translates Docker's path/volume arguments and will fail in confusing ways. The `WWWUSER`/`WWWGROUP` "variable is not set" warnings on every `docker compose` command are cosmetic and harmless; ignore them.

**After every restart**, re-apply the storage/cache permission fix — Docker Desktop's Windows bind-mount can leave `storage/` and `bootstrap/cache/` root-owned, which the non-root `sail` user inside the container can't write to (shows up as a `tempnam()` 500 error):

```powershell
docker compose exec -u root laravel.test bash -c "chown -R sail:sail /var/www/html/storage /var/www/html/bootstrap/cache && chmod -R 775 /var/www/html/storage /var/www/html/bootstrap/cache"
```

The same fix applies to `node_modules/` if you ever see `EACCES` there.

## The queue worker is not persistent

Outbound Telegram replies and the AI Agent's job (`GenerateAiReplyJob`) both go through Laravel's `database` queue driver, which needs a worker actually running to process anything:

```powershell
docker compose exec -d laravel.test bash -c "su sail -c 'php artisan queue:work --tries=3 --sleep=1'"
```

This is **not** wired into Sail's supervisord config — it's a manual process you start yourself, and it dies when the container restarts. If replies or AI responses silently stop working, this is the first thing to check (`jobs`/`failed_jobs` tables piling up is the tell).

## Performance: cache views and routes, NOT config

This app was originally very slow (4-10s per request) due to two compounding causes on this filesystem setup:

1. Blade views being re-checked/recompiled on every request.
2. PHP's opcache re-validating file timestamps every ~2 seconds (`opcache.revalidate_freq`), which means re-`stat()`-ing thousands of files over Docker Desktop's slow Windows bind-mount.

Fixed by:

```powershell
docker compose exec laravel.test bash -c "su sail -c 'php artisan route:cache && php artisan view:cache'"
```

plus `opcache.validate_timestamps=0` appended to `/etc/php/8.5/cli/php.ini` **inside the running container** (not committed anywhere — it's a container-runtime setting, and will be lost if the image is ever rebuilt from scratch; re-apply if so).

**Do NOT run `php artisan config:cache`** in this app. It freezes `APP_ENV` (and everything else) at whatever value was active when you ran it. Since Livewire/Volt's test-support macros (`assertSeeVolt`, `assertSeeLivewire`) are registered conditionally based on the live environment, a config cached under `local` silently breaks those macros for every subsequent process — including `php artisan test`, which expects `testing` via `phpunit.xml`'s env overrides. This isn't hypothetical; it happened and took real debugging to trace back to `config:cache`. `route:cache` and `view:cache` don't have this problem and are the actual source of the performance win anyway.

If you ever need to undo config caching: `php artisan config:clear`.

## After editing Blade files

Recompiled views are cached (see above), so changes to `.blade.php` files won't show up until you clear and re-cache:

```powershell
docker compose exec laravel.test bash -c "su sail -c 'php artisan view:clear && php artisan view:cache'"
```

If the running `php artisan serve` process still shows stale output after that, a full `docker compose restart laravel.test` (then re-apply the permission fix above) resolves it — a plain `view:clear` doesn't always evict what's already loaded into a long-running process's memory.

## Shell quoting on this machine

PowerShell reliably fails on deeply nested quoting — anything like `docker compose exec ... bash -c "su sail -c '...'"` wrapping a `tinker --execute` that itself contains escaped quotes. Two fallbacks, in order of preference:

1. Use the Bash tool (Git Bash) instead of PowerShell for that specific command — it handles the same nesting fine.
2. For anything more than a one-liner (a DB query, a multi-step check), write a small PHP script to the project root — it's bind-mounted straight into the container — and run it via `php artisan tinker --execute="require base_path('_yourfile.php');"`. Delete the script when done; don't commit it.

## Testing

- PHPUnit, not Pest — every test file is a class extending `Tests\TestCase`.
- All external API calls (Telegram, Anthropic/Claude, TypeSafe AI/Jev) are faked in tests via `Http::fake()`. Never make live calls in the suite — `tests/TestCase.php` calls `Http::preventStrayRequests()` specifically to catch this if it ever regresses.
- Run the full suite: `docker compose exec laravel.test bash -c "su sail -c 'php artisan test'"`.

## Local dev data

There is no seeder for this app's domain data (contacts/conversations/channels) — it's meant to be populated by real Telegram traffic. **Never run `php artisan migrate:fresh` or `migrate:refresh` against the running dev database** — there's no backup, and doing so wipes all channels/contacts/conversations/messages/users, including whatever login you're using to test with. If you need a clean slate, be deliberate about it and expect to recreate:

```php
App\Models\Channel::create(['type' => 'telegram', 'label' => 'Support Bot', 'is_active' => true]);
App\Models\User::factory()->create(['email' => 'agent@example.com', 'password' => bcrypt('password')]);
```

## Telegram / AI Agent setup

Env keys needed in `.env` (see `.env.example` for the full list): `TELEGRAM_BOT_TOKEN`, `TELEGRAM_WEBHOOK_SECRET`, `ANTHROPIC_API_KEY`, `ANTHROPIC_MODEL`, `TYPESAFE_AI_API_KEY`, `AI_CONFIDENCE_THRESHOLD`, `AI_AGENT_ENABLED`. A real Telegram bot needs a public HTTPS URL (ngrok or similar) registered via Telegram's `setWebhook` API pointing at `/webhooks/telegram` for local testing — see the AI Agent design spec in `docs/superpowers/specs/` for the full flow.

## Known deferred items (not bugs, deliberately out of scope so far)

- `channels.credentials` column is unused — `TelegramAdapter` reads the bot token from global `.env` config, not per-channel. Fine at single-channel scale; relevant if a second Telegram account or channel type is ever added.
- Inbound `messages.external_message_id` stores Telegram's `update_id`, not `message.message_id` — mixes two ID namespaces. Low-impact today.
- No UI for images or heavier motion yet (explicitly deferred — "keep it simple first" per the UI/UX pass).
- Business rules and RAG/knowledge-base integration for the AI Agent are explicit seams, not built — the system prompt is one hardcoded string.
