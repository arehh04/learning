# Omnichannel CRM Core Platform Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build the MVP core of an omnichannel CRM — Telegram messages flow through a unified inbox where a human agent can manually claim and reply, with idempotent ingestion and atomic claim handling as first-class concerns.

**Architecture:** A single Laravel monolith backed by PostgreSQL. Telegram webhooks are ingested through a `ChannelAdapter` abstraction into a small set of domain services (`MessageIngestionService`, `ConversationRoutingService`, `ClaimService`, `ReplyDispatchService`), persisted via Eloquent, and surfaced through a minimal Livewire inbox UI. Outbound sends go through Laravel's database-backed queue.

**Tech Stack:** PHP / Laravel (Breeze + Livewire stack), PostgreSQL, Laravel Sail (Docker), Pest for tests, Laravel's database queue driver (no Redis).

**Spec:** `docs/superpowers/specs/2026-09-23-omnichannel-crm-core-design.md`

## Global Constraints

- Single-tenant only — no `workspace_id`/tenant column anywhere in the schema.
- Telegram is the only implemented channel. The `channels.type` enum reserves `whatsapp` and `email` values but no adapter exists for them yet.
- The AI agent is a routing stub only — `conversations.owner_type` includes `ai` as a valid value, but nothing in this plan makes an LLM call or routes into it automatically. Every new conversation routes to `unassigned`.
- Assignment is manual claim only — no auto-assignment/round-robin logic.
- No Redis. The queue connection is Laravel's `database` driver.
- No containerization tooling beyond Laravel Sail (official Docker Compose setup) — do not hand-roll custom Dockerfiles.
- All file paths below are relative to this repo's root (`C:\Users\HP\Desktop\learning`). The Laravel project is built in place here, not in a separate sibling directory — Task 1 was revised to install Laravel directly into this existing git repo (it originally targeted a sibling `crm` folder; that approach was abandoned after repeated environment failures, see the ledger).
- Sail/Docker commands must be routed through WSL2 (`wsl -d Ubuntu -- bash -lc "cd /mnt/c/Users/HP/Desktop/learning && <cmd>"`), never plain Git Bash — Git Bash (MINGW64) mis-translates the path/volume arguments Sail's scripts pass to Docker, which caused Task 1's first two attempts to fail.

---

### Task 1: Project Initialization with Docker (Laravel Sail + PostgreSQL)

**Revision note:** this task originally targeted a fresh sibling directory
(`C:\Users\HP\Desktop\crm`) and ran into repeated environment failures on
Windows/Git Bash (see the ledger for the full account: hand-rolled Docker
files, HTTP 500, a mid-fix directory deletion). It's revised here to build
in place inside this repo, and to route every Sail/Docker command through
WSL2 from the start instead of discovering that mid-task.

**Files:**
- Create: a Laravel project's files directly in this repo's root (`C:\Users\HP\Desktop\learning`), via the official installer — not hand-written, and not a subdirectory

**Interfaces:**
- Consumes: nothing (first task)
- Produces: a running Laravel app reachable at `http://localhost`, with `./vendor/bin/sail` as the command runner for every subsequent task (`sail artisan`, `sail composer`, `sail npm`, `sail test`) — every such command routed through WSL2, see below

- [ ] **Step 1: Start the Ubuntu WSL2 distro**

```bash
wsl -d Ubuntu -- echo ready
```

- [ ] **Step 2: Create the project via Laravel's official Sail installer, into a temp directory**

Composer's `create-project` refuses a non-empty target, and this repo
already has `.git`/`docs`/`.superpowers` in it — so install into a throwaway
sibling directory first, then merge its contents into this repo's root.
Run via WSL2, not Git Bash:

```bash
wsl -d Ubuntu -- bash -lc "cd /mnt/c/Users/HP/Desktop && curl -s 'https://laravel.build/crm-tmp?with=pgsql' | bash"
```

This uses a temporary Docker container to run `composer create-project`, so no local PHP/Composer install is required. It generates `docker-compose.yml` pre-configured for PHP + PostgreSQL.

- [ ] **Step 3: Merge the generated project into this repo's root, then remove the temp directory**

```bash
cp -a C:/Users/HP/Desktop/crm-tmp/. C:/Users/HP/Desktop/learning/
rm -rf C:/Users/HP/Desktop/crm-tmp
```

(`cp -a ... /.` copies contents including dotfiles like `.env`, `.env.example`, `.gitignore` — it does not create a `.git` here since the installer itself never ran `git init`.)

- [ ] **Step 4: Start the containers**

```bash
wsl -d Ubuntu -- bash -lc "cd /mnt/c/Users/HP/Desktop/learning && ./vendor/bin/sail up -d"
```

- [ ] **Step 5: Verify the app boots**

```bash
curl -sI http://localhost | head -n 1
```

Expected: `HTTP/1.1 200 OK`. If it isn't, do not delete and restart — debug in place first (`wsl -d Ubuntu -- bash -lc "cd /mnt/c/Users/HP/Desktop/learning && ./vendor/bin/sail logs"`, check `storage/logs/laravel.log`), since a prior attempt lost all its work to a premature delete-and-restart.

- [ ] **Step 6: Set the queue connection to database (no Redis)**

Open `.env`, confirm (or set):

```
QUEUE_CONNECTION=database
```

- [ ] **Step 7: Commit (this repo already has git history — no `git init` needed)**

```bash
git add .
git commit -m "Initialize Laravel project with Sail + PostgreSQL, built in place"
```

---

### Task 2: Authentication Scaffolding (Breeze, Livewire stack) for Multi-Agent Login

**Files:**
- Modify: `composer.json` (adds `laravel/breeze`)
- Create: Breeze's generated Livewire auth scaffolding (routes, Livewire components, views) — generated by the installer, not hand-written

**Interfaces:**
- Consumes: the running Sail environment from Task 1
- Produces: `auth` middleware usable by later routes (Task 9), and a logged-in `\App\Models\User` available via `auth()->user()` in later services

- [ ] **Step 1: Require and install Breeze with the Livewire stack**

```bash
sail composer require laravel/breeze --dev
sail artisan breeze:install livewire
```

When prompted, accept the default (Pest for testing).

- [ ] **Step 2: Install frontend dependencies and build assets**

```bash
sail npm install
sail npm run build
```

- [ ] **Step 3: Run migrations (creates the `users` table and Breeze's supporting tables)**

```bash
sail artisan migrate
```

- [ ] **Step 4: Run Breeze's own generated auth tests to verify the scaffold works**

```bash
sail artisan test --filter=Auth
```

Expected: all tests pass (Breeze ships these itself).

- [ ] **Step 5: Commit**

```bash
git add .
git commit -m "Add Breeze (Livewire stack) authentication scaffolding"
```

---

### Task 3: Core Database Schema & Eloquent Models

**Files:**
- Create: `database/migrations/2026_09_23_000001_create_channels_table.php`
- Create: `database/migrations/2026_09_23_000002_create_contacts_table.php`
- Create: `database/migrations/2026_09_23_000003_create_conversations_table.php`
- Create: `database/migrations/2026_09_23_000004_create_messages_table.php`
- Create: `database/migrations/2026_09_23_000005_create_webhook_events_table.php`
- Create: `database/migrations/2026_09_23_000006_create_handoff_events_table.php`
- Create: `app/Models/Channel.php`
- Create: `app/Models/Contact.php`
- Create: `app/Models/Conversation.php`
- Create: `app/Models/Message.php`
- Create: `app/Models/WebhookEvent.php`
- Create: `app/Models/HandoffEvent.php`
- Create: `database/factories/ChannelFactory.php`
- Create: `database/factories/ContactFactory.php`
- Create: `database/factories/ConversationFactory.php`
- Create: `database/factories/MessageFactory.php`
- Modify: `tests/Pest.php` (add `RefreshDatabase` for Feature tests)
- Test: `tests/Feature/SchemaConstraintsTest.php`

**Interfaces:**
- Consumes: nothing new from prior tasks (uses Laravel's default `users` table from Task 2)
- Produces:
  - `Channel::class` with `contacts()`, `conversations()` relations
  - `Contact::class` with `channel()`, `conversations()` relations
  - `Conversation::class` with constants `STATUS_OPEN`, `STATUS_CLOSED`, `OWNER_UNASSIGNED`, `OWNER_AI`, `OWNER_HUMAN`, and `contact()`, `channel()`, `ownerAgent()`, `messages()`, `handoffEvents()` relations
  - `Message::class` with constants `DIRECTION_INBOUND`, `DIRECTION_OUTBOUND`, `STATUS_RECEIVED`, `STATUS_PENDING`, `STATUS_SENT`, `STATUS_FAILED`, and `conversation()` relation
  - `WebhookEvent::class` with `channel()` relation
  - `HandoffEvent::class` with `conversation()`, `agent()` relations
  - Factories for `Channel`, `Contact`, `Conversation`, `Message` usable in all later tests

- [ ] **Step 1: Write the channels migration**

`database/migrations/2026_09_23_000001_create_channels_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('channels', function (Blueprint $table) {
            $table->id();
            $table->enum('type', ['telegram', 'whatsapp', 'email']);
            $table->string('label');
            $table->text('credentials')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('channels');
    }
};
```

- [ ] **Step 2: Write the contacts migration**

`database/migrations/2026_09_23_000002_create_contacts_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contacts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('channel_id')->constrained()->cascadeOnDelete();
            $table->string('external_contact_id');
            $table->string('name')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['channel_id', 'external_contact_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contacts');
    }
};
```

- [ ] **Step 3: Write the conversations migration**

`database/migrations/2026_09_23_000003_create_conversations_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('conversations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('contact_id')->constrained()->cascadeOnDelete();
            $table->foreignId('channel_id')->constrained()->cascadeOnDelete();
            $table->enum('status', ['open', 'closed'])->default('open');
            $table->enum('owner_type', ['unassigned', 'ai', 'human'])->default('unassigned');
            $table->foreignId('owner_agent_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('last_inbound_at')->nullable();
            $table->timestamp('opened_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('conversations');
    }
};
```

- [ ] **Step 4: Write the messages migration**

`database/migrations/2026_09_23_000004_create_messages_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('conversation_id')->constrained()->cascadeOnDelete();
            $table->enum('direction', ['inbound', 'outbound']);
            $table->enum('sender_type', ['contact', 'agent', 'ai', 'system']);
            $table->unsignedBigInteger('sender_id')->nullable();
            $table->text('body');
            $table->string('external_message_id')->nullable()->index();
            $table->enum('status', ['received', 'pending', 'sent', 'failed'])->default('received');
            $table->json('raw_payload')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('messages');
    }
};
```

- [ ] **Step 5: Write the webhook_events migration**

`database/migrations/2026_09_23_000005_create_webhook_events_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('webhook_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('channel_id')->constrained()->cascadeOnDelete();
            $table->string('external_event_id');
            $table->json('payload');
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();

            $table->unique(['channel_id', 'external_event_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('webhook_events');
    }
};
```

- [ ] **Step 6: Write the handoff_events migration**

`database/migrations/2026_09_23_000006_create_handoff_events_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('handoff_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('conversation_id')->constrained()->cascadeOnDelete();
            $table->string('from_owner_type');
            $table->string('to_owner_type');
            $table->foreignId('agent_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('reason')->nullable();
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('handoff_events');
    }
};
```

- [ ] **Step 7: Write the Eloquent models**

`app/Models/Channel.php`:

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Channel extends Model
{
    use HasFactory;

    protected $fillable = ['type', 'label', 'credentials', 'is_active'];

    protected $casts = [
        'credentials' => 'encrypted',
        'is_active' => 'boolean',
    ];

    public function contacts(): HasMany
    {
        return $this->hasMany(Contact::class);
    }

    public function conversations(): HasMany
    {
        return $this->hasMany(Conversation::class);
    }
}
```

`app/Models/Contact.php`:

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Contact extends Model
{
    use HasFactory;

    protected $fillable = ['channel_id', 'external_contact_id', 'name', 'metadata'];

    protected $casts = [
        'metadata' => 'array',
    ];

    public function channel(): BelongsTo
    {
        return $this->belongsTo(Channel::class);
    }

    public function conversations(): HasMany
    {
        return $this->hasMany(Conversation::class);
    }
}
```

`app/Models/Conversation.php`:

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Conversation extends Model
{
    use HasFactory;

    public const STATUS_OPEN = 'open';
    public const STATUS_CLOSED = 'closed';

    public const OWNER_UNASSIGNED = 'unassigned';
    public const OWNER_AI = 'ai';
    public const OWNER_HUMAN = 'human';

    protected $fillable = [
        'contact_id', 'channel_id', 'status', 'owner_type', 'owner_agent_id',
        'last_inbound_at', 'opened_at', 'closed_at',
    ];

    protected $casts = [
        'last_inbound_at' => 'datetime',
        'opened_at' => 'datetime',
        'closed_at' => 'datetime',
    ];

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    public function channel(): BelongsTo
    {
        return $this->belongsTo(Channel::class);
    }

    public function ownerAgent(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_agent_id');
    }

    public function messages(): HasMany
    {
        return $this->hasMany(Message::class);
    }

    public function handoffEvents(): HasMany
    {
        return $this->hasMany(HandoffEvent::class);
    }
}
```

`app/Models/Message.php`:

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Message extends Model
{
    use HasFactory;

    public const DIRECTION_INBOUND = 'inbound';
    public const DIRECTION_OUTBOUND = 'outbound';

    public const STATUS_RECEIVED = 'received';
    public const STATUS_PENDING = 'pending';
    public const STATUS_SENT = 'sent';
    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'conversation_id', 'direction', 'sender_type', 'sender_id',
        'body', 'external_message_id', 'status', 'raw_payload',
    ];

    protected $casts = [
        'raw_payload' => 'array',
    ];

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }
}
```

`app/Models/WebhookEvent.php`:

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WebhookEvent extends Model
{
    use HasFactory;

    protected $fillable = ['channel_id', 'external_event_id', 'payload', 'processed_at'];

    protected $casts = [
        'payload' => 'array',
        'processed_at' => 'datetime',
    ];

    public function channel(): BelongsTo
    {
        return $this->belongsTo(Channel::class);
    }
}
```

`app/Models/HandoffEvent.php`:

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class HandoffEvent extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'conversation_id', 'from_owner_type', 'to_owner_type', 'agent_id', 'reason', 'created_at',
    ];

    protected $casts = [
        'created_at' => 'datetime',
    ];

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    public function agent(): BelongsTo
    {
        return $this->belongsTo(User::class, 'agent_id');
    }
}
```

- [ ] **Step 8: Write the factories**

`database/factories/ChannelFactory.php`:

```php
<?php

namespace Database\Factories;

use App\Models\Channel;
use Illuminate\Database\Eloquent\Factories\Factory;

class ChannelFactory extends Factory
{
    protected $model = Channel::class;

    public function definition(): array
    {
        return [
            'type' => 'telegram',
            'label' => 'Telegram Bot',
            'credentials' => json_encode(['bot_token' => 'test-token']),
            'is_active' => true,
        ];
    }
}
```

`database/factories/ContactFactory.php`:

```php
<?php

namespace Database\Factories;

use App\Models\Channel;
use App\Models\Contact;
use Illuminate\Database\Eloquent\Factories\Factory;

class ContactFactory extends Factory
{
    protected $model = Contact::class;

    public function definition(): array
    {
        return [
            'channel_id' => Channel::factory(),
            'external_contact_id' => (string) fake()->unique()->numberBetween(100000, 999999),
            'name' => fake()->name(),
            'metadata' => [],
        ];
    }
}
```

`database/factories/ConversationFactory.php`:

```php
<?php

namespace Database\Factories;

use App\Models\Channel;
use App\Models\Contact;
use App\Models\Conversation;
use Illuminate\Database\Eloquent\Factories\Factory;

class ConversationFactory extends Factory
{
    protected $model = Conversation::class;

    public function definition(): array
    {
        return [
            'contact_id' => Contact::factory(),
            'channel_id' => Channel::factory(),
            'status' => Conversation::STATUS_OPEN,
            'owner_type' => Conversation::OWNER_UNASSIGNED,
        ];
    }
}
```

`database/factories/MessageFactory.php`:

```php
<?php

namespace Database\Factories;

use App\Models\Conversation;
use App\Models\Message;
use Illuminate\Database\Eloquent\Factories\Factory;

class MessageFactory extends Factory
{
    protected $model = Message::class;

    public function definition(): array
    {
        return [
            'conversation_id' => Conversation::factory(),
            'direction' => Message::DIRECTION_INBOUND,
            'sender_type' => 'contact',
            'body' => fake()->sentence(),
            'status' => Message::STATUS_RECEIVED,
        ];
    }
}
```

- [ ] **Step 9: Enable RefreshDatabase for Feature tests**

In `tests/Pest.php`, find the line configuring the `Feature` directory and ensure it uses `RefreshDatabase`:

```php
<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');
```

(If your generated `tests/Pest.php` uses the older `uses(TestCase::class)->in('Feature');` syntax, change it to `uses(TestCase::class, RefreshDatabase::class)->in('Feature');` instead — either form is correct for the Pest version installed by Breeze.)

- [ ] **Step 10: Write the failing constraint tests**

`tests/Feature/SchemaConstraintsTest.php`:

```php
<?php

use App\Models\Channel;
use App\Models\Contact;
use App\Models\WebhookEvent;
use Illuminate\Database\QueryException;

test('contacts enforce a unique external id per channel', function () {
    $channel = Channel::factory()->create();

    Contact::factory()->create(['channel_id' => $channel->id, 'external_contact_id' => '12345']);

    expect(fn () => Contact::factory()->create(['channel_id' => $channel->id, 'external_contact_id' => '12345']))
        ->toThrow(QueryException::class);
});

test('webhook events enforce a unique external event id per channel', function () {
    $channel = Channel::factory()->create();

    WebhookEvent::create([
        'channel_id' => $channel->id,
        'external_event_id' => 'evt-1',
        'payload' => ['foo' => 'bar'],
    ]);

    expect(fn () => WebhookEvent::create([
        'channel_id' => $channel->id,
        'external_event_id' => 'evt-1',
        'payload' => ['foo' => 'baz'],
    ]))->toThrow(QueryException::class);
});
```

- [ ] **Step 11: Run migrations and the tests**

```bash
sail artisan migrate
sail artisan test --filter=SchemaConstraintsTest
```

Expected: both tests PASS (the migrations must exist and the unique constraints must be in place first — if you're following strict TDD, running the tests before Step 11's migration would fail with "table does not exist," which is an acceptable proof of the test being meaningful).

- [ ] **Step 12: Commit**

```bash
git add .
git commit -m "Add core schema (channels, contacts, conversations, messages, webhook_events, handoff_events) and models"
```

---

### Task 4: Telegram Channel Adapter

**Files:**
- Create: `app/Contracts/ChannelAdapter.php`
- Create: `app/Support/ParsedInboundMessage.php`
- Create: `app/Support/SentMessageResult.php`
- Create: `app/Services/Channels/TelegramAdapter.php`
- Modify: `config/services.php`
- Modify: `.env` and `.env.example`
- Modify: `app/Providers/AppServiceProvider.php`
- Test: `tests/Feature/TelegramAdapterTest.php`

**Interfaces:**
- Consumes: nothing from prior tasks
- Produces:
  - `App\Contracts\ChannelAdapter` interface: `parseInbound(array $payload): ParsedInboundMessage`, `send(string $externalContactId, string $body): SentMessageResult`
  - `App\Support\ParsedInboundMessage` — readonly properties `externalEventId`, `externalContactId`, `contactName` (nullable), `body`, `rawPayload`
  - `App\Support\SentMessageResult` — readonly property `externalMessageId`
  - `App\Services\Channels\TelegramAdapter` implementing `ChannelAdapter`, resolvable from the container with its bot token injected from config

- [ ] **Step 1: Write the interface and value objects**

`app/Contracts/ChannelAdapter.php`:

```php
<?php

namespace App\Contracts;

use App\Support\ParsedInboundMessage;
use App\Support\SentMessageResult;

interface ChannelAdapter
{
    public function parseInbound(array $payload): ParsedInboundMessage;

    public function send(string $externalContactId, string $body): SentMessageResult;
}
```

`app/Support/ParsedInboundMessage.php`:

```php
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
```

`app/Support/SentMessageResult.php`:

```php
<?php

namespace App\Support;

final class SentMessageResult
{
    public function __construct(
        public readonly string $externalMessageId,
    ) {
    }
}
```

- [ ] **Step 2: Add Telegram config**

In `config/services.php`, add inside the returned array:

```php
'telegram' => [
    'bot_token' => env('TELEGRAM_BOT_TOKEN'),
    'webhook_secret' => env('TELEGRAM_WEBHOOK_SECRET'),
],
```

In `.env` and `.env.example`, add:

```
TELEGRAM_BOT_TOKEN=
TELEGRAM_WEBHOOK_SECRET=
```

- [ ] **Step 3: Write the failing tests**

`tests/Feature/TelegramAdapterTest.php`:

```php
<?php

use App\Services\Channels\TelegramAdapter;
use Illuminate\Support\Facades\Http;

test('parseInbound extracts fields from a telegram update payload', function () {
    $adapter = new TelegramAdapter('fake-token');

    $payload = [
        'update_id' => 555666777,
        'message' => [
            'message_id' => 42,
            'from' => ['id' => 999, 'first_name' => 'Alice', 'username' => 'alice'],
            'chat' => ['id' => 999, 'type' => 'private'],
            'date' => 1690000000,
            'text' => 'Hello there',
        ],
    ];

    $parsed = $adapter->parseInbound($payload);

    expect($parsed->externalEventId)->toBe('555666777');
    expect($parsed->externalContactId)->toBe('999');
    expect($parsed->contactName)->toBe('alice');
    expect($parsed->body)->toBe('Hello there');
});

test('send posts to the telegram sendMessage endpoint and returns the message id', function () {
    Http::fake([
        'api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 42]], 200),
    ]);

    $adapter = new TelegramAdapter('fake-token');

    $result = $adapter->send('999', 'Hi back');

    expect($result->externalMessageId)->toBe('42');

    Http::assertSent(function ($request) {
        return str_contains($request->url(), 'api.telegram.org/botfake-token/sendMessage')
            && $request['chat_id'] === '999'
            && $request['text'] === 'Hi back';
    });
});

test('send throws when telegram responds with an error', function () {
    Http::fake([
        'api.telegram.org/*' => Http::response(['ok' => false, 'description' => 'bad request'], 400),
    ]);

    $adapter = new TelegramAdapter('fake-token');

    expect(fn () => $adapter->send('999', 'Hi'))->toThrow(RuntimeException::class);
});
```

- [ ] **Step 4: Run the tests to verify they fail**

```bash
sail artisan test --filter=TelegramAdapterTest
```

Expected: FAIL with "Class TelegramAdapter not found".

- [ ] **Step 5: Implement TelegramAdapter**

`app/Services/Channels/TelegramAdapter.php`:

```php
<?php

namespace App\Services\Channels;

use App\Contracts\ChannelAdapter;
use App\Support\ParsedInboundMessage;
use App\Support\SentMessageResult;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class TelegramAdapter implements ChannelAdapter
{
    public function __construct(private readonly string $botToken)
    {
    }

    public function parseInbound(array $payload): ParsedInboundMessage
    {
        $message = $payload['message'] ?? throw new RuntimeException('Update payload missing "message" key.');

        return new ParsedInboundMessage(
            externalEventId: (string) $payload['update_id'],
            externalContactId: (string) $message['chat']['id'],
            contactName: $message['from']['username'] ?? $message['from']['first_name'] ?? null,
            body: $message['text'] ?? '',
            rawPayload: $payload,
        );
    }

    public function send(string $externalContactId, string $body): SentMessageResult
    {
        $response = Http::post("https://api.telegram.org/bot{$this->botToken}/sendMessage", [
            'chat_id' => $externalContactId,
            'text' => $body,
        ]);

        if (! $response->successful() || ! ($response->json('ok') ?? false)) {
            throw new RuntimeException('Telegram sendMessage failed: '.$response->body());
        }

        return new SentMessageResult(
            externalMessageId: (string) $response->json('result.message_id'),
        );
    }
}
```

- [ ] **Step 6: Bind TelegramAdapter in the container**

In `app/Providers/AppServiceProvider.php`, inside the `register()` method, add:

```php
$this->app->bind(\App\Services\Channels\TelegramAdapter::class, function () {
    return new \App\Services\Channels\TelegramAdapter(config('services.telegram.bot_token'));
});
```

- [ ] **Step 7: Run the tests to verify they pass**

```bash
sail artisan test --filter=TelegramAdapterTest
```

Expected: PASS (3 tests).

- [ ] **Step 8: Commit**

```bash
git add .
git commit -m "Add ChannelAdapter interface and Telegram implementation"
```

---

### Task 5: Message Ingestion & Conversation Routing Services

**Files:**
- Create: `app/Services/ConversationRoutingService.php`
- Create: `app/Services/MessageIngestionService.php`
- Test: `tests/Feature/MessageIngestionServiceTest.php`

**Interfaces:**
- Consumes:
  - `App\Models\Channel`, `App\Models\Contact`, `App\Models\Conversation`, `App\Models\Message`, `App\Models\WebhookEvent` (Task 3)
  - `App\Support\ParsedInboundMessage` (Task 4)
- Produces:
  - `App\Services\ConversationRoutingService::decideInitialOwner(): string`
  - `App\Services\MessageIngestionService::ingest(Channel $channel, ParsedInboundMessage $parsed): ?Message` — returns `null` when the event was already processed (duplicate delivery)

- [ ] **Step 1: Write the failing tests**

`tests/Feature/MessageIngestionServiceTest.php`:

```php
<?php

use App\Models\Channel;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Message;
use App\Services\Channels\TelegramAdapter;
use App\Services\MessageIngestionService;

function ingestionPayload(int $updateId, int $chatId = 42, string $text = 'Hi'): array
{
    return [
        'update_id' => $updateId,
        'message' => [
            'message_id' => 1,
            'from' => ['id' => $chatId, 'first_name' => 'Bob'],
            'chat' => ['id' => $chatId, 'type' => 'private'],
            'date' => 1690000000,
            'text' => $text,
        ],
    ];
}

test('duplicate telegram update produces exactly one message', function () {
    $channel = Channel::factory()->create();
    $adapter = new TelegramAdapter('fake-token');
    $service = app(MessageIngestionService::class);

    $parsed = $adapter->parseInbound(ingestionPayload(111));

    $first = $service->ingest($channel, $parsed);
    $second = $service->ingest($channel, $parsed);

    expect($first)->not->toBeNull();
    expect($second)->toBeNull();
    expect(Message::count())->toBe(1);
});

test('a closed conversation is reopened by a new inbound message instead of forking', function () {
    $channel = Channel::factory()->create();
    $contact = Contact::factory()->create(['channel_id' => $channel->id, 'external_contact_id' => '42']);
    $conversation = Conversation::factory()->create([
        'contact_id' => $contact->id,
        'channel_id' => $channel->id,
        'status' => Conversation::STATUS_CLOSED,
        'closed_at' => now(),
    ]);

    $adapter = new TelegramAdapter('fake-token');
    $service = app(MessageIngestionService::class);

    $parsed = $adapter->parseInbound(ingestionPayload(222, chatId: 42, text: 'I am back'));

    $service->ingest($channel, $parsed);

    expect(Conversation::count())->toBe(1);

    $conversation->refresh();
    expect($conversation->status)->toBe(Conversation::STATUS_OPEN);
    expect($conversation->closed_at)->toBeNull();
});

test('a brand new contact starts a new unassigned open conversation', function () {
    $channel = Channel::factory()->create();
    $adapter = new TelegramAdapter('fake-token');
    $service = app(MessageIngestionService::class);

    $parsed = $adapter->parseInbound(ingestionPayload(333, chatId: 77, text: 'Hello'));

    $service->ingest($channel, $parsed);

    $conversation = Conversation::first();
    expect($conversation->owner_type)->toBe(Conversation::OWNER_UNASSIGNED);
    expect($conversation->status)->toBe(Conversation::STATUS_OPEN);
});
```

- [ ] **Step 2: Run the tests to verify they fail**

```bash
sail artisan test --filter=MessageIngestionServiceTest
```

Expected: FAIL with "Class MessageIngestionService not found".

- [ ] **Step 3: Implement ConversationRoutingService**

`app/Services/ConversationRoutingService.php`:

```php
<?php

namespace App\Services;

use App\Models\Conversation;

class ConversationRoutingService
{
    public function decideInitialOwner(): string
    {
        // MVP: the AI agent is a routing stub with no real logic yet, so
        // every new conversation lands in the shared human queue. The
        // "ai" owner type exists on Conversation for when real routing
        // logic replaces this trivial rule — see the design spec §3, §6.
        return Conversation::OWNER_UNASSIGNED;
    }
}
```

- [ ] **Step 4: Implement MessageIngestionService**

`app/Services/MessageIngestionService.php`:

```php
<?php

namespace App\Services;

use App\Models\Channel;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\WebhookEvent;
use App\Support\ParsedInboundMessage;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

class MessageIngestionService
{
    public function __construct(
        private readonly ConversationRoutingService $routingService,
    ) {
    }

    public function ingest(Channel $channel, ParsedInboundMessage $parsed): ?Message
    {
        return DB::transaction(function () use ($channel, $parsed) {
            if (! $this->recordWebhookEvent($channel, $parsed)) {
                return null; // duplicate delivery — already processed
            }

            $contact = $this->findOrCreateContact($channel, $parsed);
            $conversation = $this->findOrCreateConversation($channel, $contact);

            return Message::create([
                'conversation_id' => $conversation->id,
                'direction' => Message::DIRECTION_INBOUND,
                'sender_type' => 'contact',
                'body' => $parsed->body,
                'external_message_id' => $parsed->externalEventId,
                'status' => Message::STATUS_RECEIVED,
                'raw_payload' => $parsed->rawPayload,
            ]);
        });
    }

    /**
     * Runs in its own nested transaction (a Postgres SAVEPOINT, since
     * we're already inside the outer transaction from ingest()). This
     * matters: without the savepoint, catching a unique-violation here
     * would leave the outer transaction in Postgres's aborted state and
     * every later statement in ingest() would fail too.
     */
    private function recordWebhookEvent(Channel $channel, ParsedInboundMessage $parsed): bool
    {
        try {
            DB::transaction(function () use ($channel, $parsed) {
                WebhookEvent::create([
                    'channel_id' => $channel->id,
                    'external_event_id' => $parsed->externalEventId,
                    'payload' => $parsed->rawPayload,
                    'processed_at' => now(),
                ]);
            });

            return true;
        } catch (QueryException $e) {
            if ($this->isUniqueViolation($e)) {
                return false;
            }

            throw $e;
        }
    }

    private function findOrCreateContact(Channel $channel, ParsedInboundMessage $parsed): Contact
    {
        $existing = Contact::where('channel_id', $channel->id)
            ->where('external_contact_id', $parsed->externalContactId)
            ->first();

        if ($existing) {
            return $existing;
        }

        try {
            return DB::transaction(function () use ($channel, $parsed) {
                return Contact::create([
                    'channel_id' => $channel->id,
                    'external_contact_id' => $parsed->externalContactId,
                    'name' => $parsed->contactName,
                ]);
            });
        } catch (QueryException $e) {
            if (! $this->isUniqueViolation($e)) {
                throw $e;
            }

            // Lost a create race to a concurrent request for the same contact.
            return Contact::where('channel_id', $channel->id)
                ->where('external_contact_id', $parsed->externalContactId)
                ->firstOrFail();
        }
    }

    private function findOrCreateConversation(Channel $channel, Contact $contact): Conversation
    {
        $conversation = $contact->conversations()
            ->where('channel_id', $channel->id)
            ->latest('id')
            ->first();

        if (! $conversation) {
            return Conversation::create([
                'contact_id' => $contact->id,
                'channel_id' => $channel->id,
                'status' => Conversation::STATUS_OPEN,
                'owner_type' => $this->routingService->decideInitialOwner(),
                'last_inbound_at' => now(),
                'opened_at' => now(),
            ]);
        }

        if ($conversation->status === Conversation::STATUS_CLOSED) {
            $conversation->update([
                'status' => Conversation::STATUS_OPEN,
                'last_inbound_at' => now(),
                'closed_at' => null,
            ]);

            return $conversation;
        }

        $conversation->update(['last_inbound_at' => now()]);

        return $conversation;
    }

    private function isUniqueViolation(QueryException $e): bool
    {
        return $e->getCode() === '23505';
    }
}
```

- [ ] **Step 5: Run the tests to verify they pass**

```bash
sail artisan test --filter=MessageIngestionServiceTest
```

Expected: PASS (3 tests).

- [ ] **Step 6: Commit**

```bash
git add .
git commit -m "Add idempotent MessageIngestionService and ConversationRoutingService"
```

---

### Task 6: Telegram Webhook Endpoint

**Files:**
- Create: `app/Http/Controllers/TelegramWebhookController.php`
- Modify: `routes/web.php`
- Modify: `bootstrap/app.php` (CSRF exception for the webhook route)
- Test: `tests/Feature/TelegramWebhookControllerTest.php`

**Interfaces:**
- Consumes:
  - `App\Services\Channels\TelegramAdapter` (Task 4)
  - `App\Services\MessageIngestionService` (Task 5)
  - `App\Models\Channel` (Task 3)
- Produces: `POST /webhooks/telegram` route, publicly reachable but gated by the `X-Telegram-Bot-Api-Secret-Token` header

- [ ] **Step 1: Write the failing tests**

`tests/Feature/TelegramWebhookControllerTest.php`:

```php
<?php

use App\Models\Channel;
use App\Models\Message;
use App\Models\WebhookEvent;

function telegramUpdate(int $updateId = 800): array
{
    return [
        'update_id' => $updateId,
        'message' => [
            'message_id' => 1,
            'from' => ['id' => 555, 'first_name' => 'Dana'],
            'chat' => ['id' => 555, 'type' => 'private'],
            'date' => 1690000000,
            'text' => 'Hi there',
        ],
    ];
}

beforeEach(function () {
    config(['services.telegram.webhook_secret' => 'test-secret']);
});

test('webhook rejects requests with a missing or invalid secret token', function () {
    Channel::factory()->create(['type' => 'telegram']);

    $response = $this->postJson('/webhooks/telegram', telegramUpdate(), [
        'X-Telegram-Bot-Api-Secret-Token' => 'wrong-secret',
    ]);

    $response->assertStatus(403);
    expect(Message::count())->toBe(0);
    expect(WebhookEvent::count())->toBe(0);
});

test('webhook persists a message when the secret token is valid', function () {
    Channel::factory()->create(['type' => 'telegram']);

    $response = $this->postJson('/webhooks/telegram', telegramUpdate(updateId: 900), [
        'X-Telegram-Bot-Api-Secret-Token' => 'test-secret',
    ]);

    $response->assertStatus(200);
    expect(Message::count())->toBe(1);
});

test('webhook is idempotent for a redelivered update', function () {
    Channel::factory()->create(['type' => 'telegram']);
    $payload = telegramUpdate(updateId: 901);

    $this->postJson('/webhooks/telegram', $payload, ['X-Telegram-Bot-Api-Secret-Token' => 'test-secret']);
    $this->postJson('/webhooks/telegram', $payload, ['X-Telegram-Bot-Api-Secret-Token' => 'test-secret']);

    expect(Message::count())->toBe(1);
});
```

- [ ] **Step 2: Run the tests to verify they fail**

```bash
sail artisan test --filter=TelegramWebhookControllerTest
```

Expected: FAIL with a 404 (route doesn't exist yet).

- [ ] **Step 3: Implement the controller**

`app/Http/Controllers/TelegramWebhookController.php`:

```php
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
```

- [ ] **Step 4: Add the route**

In `routes/web.php`, add:

```php
use App\Http\Controllers\TelegramWebhookController;

Route::post('/webhooks/telegram', TelegramWebhookController::class);
```

- [ ] **Step 5: Exempt the webhook route from CSRF verification**

In `bootstrap/app.php`, inside the `->withMiddleware(function (Middleware $middleware) { ... })` block (add the block if it doesn't already exist), add:

```php
$middleware->validateCsrfTokens(except: [
    'webhooks/telegram',
]);
```

- [ ] **Step 6: Run the tests to verify they pass**

```bash
sail artisan test --filter=TelegramWebhookControllerTest
```

Expected: PASS (3 tests).

- [ ] **Step 7: Commit**

```bash
git add .
git commit -m "Add Telegram webhook endpoint with secret token verification"
```

---

### Task 7: Conversation Claim Service

**Files:**
- Create: `app/Exceptions/ConversationAlreadyClaimedException.php`
- Create: `app/Services/ClaimService.php`
- Test: `tests/Feature/ClaimServiceTest.php`

**Interfaces:**
- Consumes: `App\Models\Conversation`, `App\Models\HandoffEvent`, `App\Models\User` (Task 3 / Breeze)
- Produces: `App\Services\ClaimService::claim(Conversation $conversation, User $agent): Conversation` — throws `App\Exceptions\ConversationAlreadyClaimedException` if the conversation isn't `unassigned`

- [ ] **Step 1: Write the failing tests**

`tests/Feature/ClaimServiceTest.php`:

```php
<?php

use App\Exceptions\ConversationAlreadyClaimedException;
use App\Models\Conversation;
use App\Models\HandoffEvent;
use App\Models\User;
use App\Services\ClaimService;

test('an agent can claim an unassigned conversation', function () {
    $conversation = Conversation::factory()->create(['owner_type' => Conversation::OWNER_UNASSIGNED]);
    $agent = User::factory()->create();

    $claimed = app(ClaimService::class)->claim($conversation, $agent);

    expect($claimed->owner_type)->toBe(Conversation::OWNER_HUMAN);
    expect($claimed->owner_agent_id)->toBe($agent->id);
    expect(HandoffEvent::count())->toBe(1);
});

test('a second claim attempt on an already-claimed conversation fails', function () {
    $conversation = Conversation::factory()->create(['owner_type' => Conversation::OWNER_UNASSIGNED]);
    $firstAgent = User::factory()->create();
    $secondAgent = User::factory()->create();

    app(ClaimService::class)->claim($conversation, $firstAgent);

    expect(fn () => app(ClaimService::class)->claim($conversation->fresh(), $secondAgent))
        ->toThrow(ConversationAlreadyClaimedException::class);

    $conversation->refresh();
    expect($conversation->owner_agent_id)->toBe($firstAgent->id);
});
```

- [ ] **Step 2: Run the tests to verify they fail**

```bash
sail artisan test --filter=ClaimServiceTest
```

Expected: FAIL with "Class ClaimService not found".

- [ ] **Step 3: Implement the exception and service**

`app/Exceptions/ConversationAlreadyClaimedException.php`:

```php
<?php

namespace App\Exceptions;

use RuntimeException;

class ConversationAlreadyClaimedException extends RuntimeException
{
}
```

`app/Services/ClaimService.php`:

```php
<?php

namespace App\Services;

use App\Exceptions\ConversationAlreadyClaimedException;
use App\Models\Conversation;
use App\Models\HandoffEvent;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class ClaimService
{
    public function claim(Conversation $conversation, User $agent): Conversation
    {
        return DB::transaction(function () use ($conversation, $agent) {
            $locked = Conversation::where('id', $conversation->id)->lockForUpdate()->firstOrFail();

            if ($locked->owner_type !== Conversation::OWNER_UNASSIGNED) {
                throw new ConversationAlreadyClaimedException(
                    "Conversation {$locked->id} is already owned by {$locked->owner_type}."
                );
            }

            $fromOwnerType = $locked->owner_type;

            $locked->update([
                'owner_type' => Conversation::OWNER_HUMAN,
                'owner_agent_id' => $agent->id,
            ]);

            HandoffEvent::create([
                'conversation_id' => $locked->id,
                'from_owner_type' => $fromOwnerType,
                'to_owner_type' => Conversation::OWNER_HUMAN,
                'agent_id' => $agent->id,
                'reason' => 'manual_claim',
                'created_at' => now(),
            ]);

            return $locked;
        });
    }
}
```

- [ ] **Step 4: Run the tests to verify they pass**

```bash
sail artisan test --filter=ClaimServiceTest
```

Expected: PASS (2 tests).

- [ ] **Step 5: Commit**

```bash
git add .
git commit -m "Add atomic ClaimService with handoff event logging"
```

---

### Task 8: Reply Dispatch Service & Queued Send Job

**Files:**
- Create: `app/Services/ReplyDispatchService.php`
- Create: `app/Jobs/SendTelegramReplyJob.php`
- Test: `tests/Feature/ReplyDispatchServiceTest.php`
- Test: `tests/Feature/SendTelegramReplyJobTest.php`

**Interfaces:**
- Consumes:
  - `App\Models\Conversation`, `App\Models\Message`, `App\Models\User` (Task 3 / Breeze)
  - `App\Services\Channels\TelegramAdapter` (Task 4)
- Produces:
  - `App\Services\ReplyDispatchService::send(Conversation $conversation, User $agent, string $body): Message`
  - `App\Jobs\SendTelegramReplyJob` (constructed with `int $messageId`), queued via `ShouldQueue`

- [ ] **Step 1: Verify a jobs table migration exists**

Laravel's default skeleton includes a jobs table migration. Confirm it:

```bash
ls database/migrations | grep create_jobs_table
```

If nothing is found, generate and run it:

```bash
sail artisan queue:table
sail artisan migrate
```

- [ ] **Step 2: Write the failing tests**

`tests/Feature/ReplyDispatchServiceTest.php`:

```php
<?php

use App\Jobs\SendTelegramReplyJob;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use App\Services\ReplyDispatchService;
use Illuminate\Support\Facades\Queue;

test('sending a reply creates a pending outbound message and dispatches the send job', function () {
    Queue::fake();

    $conversation = Conversation::factory()->create();
    $agent = User::factory()->create();

    $message = app(ReplyDispatchService::class)->send($conversation, $agent, 'On it!');

    expect($message->status)->toBe(Message::STATUS_PENDING);
    expect($message->direction)->toBe(Message::DIRECTION_OUTBOUND);
    expect($message->sender_id)->toBe($agent->id);

    Queue::assertPushed(SendTelegramReplyJob::class);
});
```

`tests/Feature/SendTelegramReplyJobTest.php`:

```php
<?php

use App\Jobs\SendTelegramReplyJob;
use App\Models\Channel;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Message;
use App\Services\Channels\TelegramAdapter;
use Illuminate\Support\Facades\Http;

test('the send job marks the message as sent on success', function () {
    Http::fake([
        'api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 99]], 200),
    ]);

    $channel = Channel::factory()->create();
    $contact = Contact::factory()->create(['channel_id' => $channel->id, 'external_contact_id' => '555']);
    $conversation = Conversation::factory()->create(['contact_id' => $contact->id, 'channel_id' => $channel->id]);
    $message = Message::factory()->create([
        'conversation_id' => $conversation->id,
        'direction' => Message::DIRECTION_OUTBOUND,
        'status' => Message::STATUS_PENDING,
    ]);

    (new SendTelegramReplyJob($message->id))->handle(app(TelegramAdapter::class));

    $message->refresh();
    expect($message->status)->toBe(Message::STATUS_SENT);
    expect($message->external_message_id)->toBe('99');
});

test('the job marks the message as failed when the failed() hook runs', function () {
    $channel = Channel::factory()->create();
    $contact = Contact::factory()->create(['channel_id' => $channel->id, 'external_contact_id' => '555']);
    $conversation = Conversation::factory()->create(['contact_id' => $contact->id, 'channel_id' => $channel->id]);
    $message = Message::factory()->create([
        'conversation_id' => $conversation->id,
        'direction' => Message::DIRECTION_OUTBOUND,
        'status' => Message::STATUS_PENDING,
    ]);

    (new SendTelegramReplyJob($message->id))->failed(new RuntimeException('boom'));

    $message->refresh();
    expect($message->status)->toBe(Message::STATUS_FAILED);
});
```

- [ ] **Step 3: Run the tests to verify they fail**

```bash
sail artisan test --filter=ReplyDispatchServiceTest
sail artisan test --filter=SendTelegramReplyJobTest
```

Expected: FAIL with "Class ReplyDispatchService not found" / "Class SendTelegramReplyJob not found".

- [ ] **Step 4: Implement the job**

`app/Jobs/SendTelegramReplyJob.php`:

```php
<?php

namespace App\Jobs;

use App\Models\Message;
use App\Services\Channels\TelegramAdapter;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

class SendTelegramReplyJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public function __construct(private readonly int $messageId)
    {
    }

    public function handle(TelegramAdapter $adapter): void
    {
        $message = Message::with('conversation.contact')->findOrFail($this->messageId);
        $contact = $message->conversation->contact;

        $result = $adapter->send($contact->external_contact_id, $message->body);

        $message->update([
            'status' => Message::STATUS_SENT,
            'external_message_id' => $result->externalMessageId,
        ]);
    }

    public function failed(Throwable $exception): void
    {
        Message::whereKey($this->messageId)->update(['status' => Message::STATUS_FAILED]);
    }
}
```

- [ ] **Step 5: Implement ReplyDispatchService**

`app/Services/ReplyDispatchService.php`:

```php
<?php

namespace App\Services;

use App\Jobs\SendTelegramReplyJob;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;

class ReplyDispatchService
{
    public function send(Conversation $conversation, User $agent, string $body): Message
    {
        $message = Message::create([
            'conversation_id' => $conversation->id,
            'direction' => Message::DIRECTION_OUTBOUND,
            'sender_type' => 'agent',
            'sender_id' => $agent->id,
            'body' => $body,
            'status' => Message::STATUS_PENDING,
        ]);

        SendTelegramReplyJob::dispatch($message->id);

        return $message;
    }
}
```

- [ ] **Step 6: Run the tests to verify they pass**

```bash
sail artisan test --filter=ReplyDispatchServiceTest
sail artisan test --filter=SendTelegramReplyJobTest
```

Expected: PASS (3 tests total).

- [ ] **Step 7: Commit**

```bash
git add .
git commit -m "Add ReplyDispatchService and queued SendTelegramReplyJob"
```

---

### Task 9: Agent Inbox UI (Livewire)

**Files:**
- Create: `app/Livewire/Inbox.php`
- Create: `resources/views/livewire/inbox.blade.php`
- Modify: `routes/web.php`
- Test: `tests/Feature/InboxTest.php`

**Interfaces:**
- Consumes:
  - `App\Services\ClaimService` (Task 7)
  - `App\Services\ReplyDispatchService` (Task 8)
  - `App\Models\Conversation` (Task 3), Breeze's `auth` middleware (Task 2)
- Produces: `GET /inbox` (auth-protected) rendering the `Inbox` Livewire component

- [ ] **Step 1: Write the failing tests**

`tests/Feature/InboxTest.php`:

```php
<?php

use App\Livewire\Inbox;
use App\Models\Channel;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

test('an agent can claim an unassigned conversation from the inbox', function () {
    $agent = User::factory()->create();
    $channel = Channel::factory()->create();
    $contact = Contact::factory()->create(['channel_id' => $channel->id]);
    $conversation = Conversation::factory()->create([
        'contact_id' => $contact->id,
        'channel_id' => $channel->id,
        'owner_type' => Conversation::OWNER_UNASSIGNED,
    ]);

    Livewire::actingAs($agent)
        ->test(Inbox::class)
        ->call('claim', $conversation->id)
        ->assertSet('claimError', null);

    $conversation->refresh();
    expect($conversation->owner_type)->toBe(Conversation::OWNER_HUMAN);
    expect($conversation->owner_agent_id)->toBe($agent->id);
});

test('a second agent cannot claim a conversation already claimed by another agent', function () {
    $firstAgent = User::factory()->create();
    $secondAgent = User::factory()->create();
    $channel = Channel::factory()->create();
    $contact = Contact::factory()->create(['channel_id' => $channel->id]);
    $conversation = Conversation::factory()->create([
        'contact_id' => $contact->id,
        'channel_id' => $channel->id,
        'owner_type' => Conversation::OWNER_HUMAN,
        'owner_agent_id' => $firstAgent->id,
    ]);

    Livewire::actingAs($secondAgent)
        ->test(Inbox::class)
        ->call('claim', $conversation->id)
        ->assertSet('claimError', 'Someone already claimed this conversation.');
});

test('an agent can send a reply on a conversation they own', function () {
    Queue::fake();

    $agent = User::factory()->create();
    $channel = Channel::factory()->create();
    $contact = Contact::factory()->create(['channel_id' => $channel->id]);
    $conversation = Conversation::factory()->create([
        'contact_id' => $contact->id,
        'channel_id' => $channel->id,
        'owner_type' => Conversation::OWNER_HUMAN,
        'owner_agent_id' => $agent->id,
    ]);

    Livewire::actingAs($agent)
        ->test(Inbox::class)
        ->call('select', $conversation->id)
        ->set('replyBody', 'Thanks for reaching out!')
        ->call('sendReply');

    expect(
        Message::where('conversation_id', $conversation->id)
            ->where('direction', Message::DIRECTION_OUTBOUND)
            ->count()
    )->toBe(1);
});
```

- [ ] **Step 2: Run the tests to verify they fail**

```bash
sail artisan test --filter=InboxTest
```

Expected: FAIL with "Class Inbox not found".

- [ ] **Step 3: Implement the Livewire component**

`app/Livewire/Inbox.php`:

```php
<?php

namespace App\Livewire;

use App\Exceptions\ConversationAlreadyClaimedException;
use App\Models\Conversation;
use App\Services\ClaimService;
use App\Services\ReplyDispatchService;
use Livewire\Component;

class Inbox extends Component
{
    public ?int $selectedConversationId = null;
    public string $replyBody = '';
    public ?string $claimError = null;

    public function select(int $conversationId): void
    {
        $this->selectedConversationId = $conversationId;
        $this->claimError = null;
    }

    public function claim(int $conversationId, ClaimService $claimService): void
    {
        $conversation = Conversation::findOrFail($conversationId);

        try {
            $claimService->claim($conversation, auth()->user());
            $this->selectedConversationId = $conversationId;
            $this->claimError = null;
        } catch (ConversationAlreadyClaimedException $e) {
            $this->claimError = 'Someone already claimed this conversation.';
        }
    }

    public function sendReply(ReplyDispatchService $replyService): void
    {
        $this->validate(['replyBody' => 'required|string|min:1']);

        $conversation = Conversation::findOrFail($this->selectedConversationId);

        $replyService->send($conversation, auth()->user(), $this->replyBody);

        $this->replyBody = '';
    }

    public function render()
    {
        return view('livewire.inbox', [
            'unassigned' => Conversation::where('owner_type', Conversation::OWNER_UNASSIGNED)
                ->with('contact')
                ->latest('last_inbound_at')
                ->get(),
            'mine' => Conversation::where('owner_type', Conversation::OWNER_HUMAN)
                ->where('owner_agent_id', auth()->id())
                ->with('contact')
                ->latest('last_inbound_at')
                ->get(),
            'selected' => $this->selectedConversationId
                ? Conversation::with(['contact', 'messages' => fn ($q) => $q->orderBy('created_at')])
                    ->find($this->selectedConversationId)
                : null,
        ]);
    }
}
```

- [ ] **Step 4: Implement the view**

`resources/views/livewire/inbox.blade.php`:

```blade
<div class="flex h-full gap-4">
    <div class="w-1/3 space-y-4">
        <div>
            <h2 class="font-bold">Unassigned</h2>
            @if ($claimError)
                <p class="text-red-600 text-sm">{{ $claimError }}</p>
            @endif
            @foreach ($unassigned as $conversation)
                <div class="border p-2 flex justify-between items-center">
                    <span wire:click="select({{ $conversation->id }})" class="cursor-pointer">
                        {{ $conversation->contact->name ?? $conversation->contact->external_contact_id }}
                    </span>
                    <button wire:click="claim({{ $conversation->id }})" class="text-sm text-blue-600">Claim</button>
                </div>
            @endforeach
        </div>

        <div>
            <h2 class="font-bold">Mine</h2>
            @foreach ($mine as $conversation)
                <div wire:click="select({{ $conversation->id }})" class="border p-2 cursor-pointer">
                    {{ $conversation->contact->name ?? $conversation->contact->external_contact_id }}
                </div>
            @endforeach
        </div>
    </div>

    <div class="w-2/3">
        @if ($selected)
            <div class="space-y-2 mb-4">
                @foreach ($selected->messages as $message)
                    <div class="{{ $message->direction === 'outbound' ? 'text-right' : 'text-left' }}">
                        <span class="inline-block border rounded p-2">{{ $message->body }}</span>
                    </div>
                @endforeach
            </div>

            @if ($selected->owner_agent_id === auth()->id())
                <form wire:submit="sendReply" class="flex gap-2">
                    <input type="text" wire:model="replyBody" class="border flex-1 p-2" placeholder="Type a reply..." />
                    <button type="submit" class="bg-blue-600 text-white px-4 py-2">Send</button>
                </form>
                @error('replyBody') <span class="text-red-600 text-sm">{{ $message }}</span> @enderror
            @endif
        @else
            <p>Select a conversation.</p>
        @endif
    </div>
</div>
```

- [ ] **Step 5: Add the route**

In `routes/web.php`, add:

```php
use App\Livewire\Inbox;

Route::get('/inbox', Inbox::class)->middleware(['auth'])->name('inbox');
```

- [ ] **Step 6: Run the tests to verify they pass**

```bash
sail artisan test --filter=InboxTest
```

Expected: PASS (3 tests).

- [ ] **Step 7: Commit**

```bash
git add .
git commit -m "Add agent inbox UI: unassigned queue, claim, and reply"
```

---

### Task 10: Manual End-to-End Verification with a Real Telegram Bot

This task has no automated test — it validates the full stack against Telegram's real API, which the earlier fakes can't cover (actual webhook delivery, actual `sendMessage` behavior).

**Files:** none (configuration and manual steps only)

- [ ] **Step 1: Create a Telegram bot**

In Telegram, message `@BotFather`, run `/newbot`, follow the prompts. Copy the bot token it gives you.

- [ ] **Step 2: Configure the app**

In `.env`:

```
TELEGRAM_BOT_TOKEN=<token from BotFather>
TELEGRAM_WEBHOOK_SECRET=<any random string you generate>
```

Restart Sail so the new env values load:

```bash
sail down && sail up -d
```

- [ ] **Step 3: Create the Telegram channel row**

```bash
sail artisan tinker --execute="App\Models\Channel::create(['type' => 'telegram', 'label' => 'Support Bot', 'is_active' => true]);"
```

- [ ] **Step 4: Expose local Sail to the internet and register the webhook**

Using any HTTP tunnel tool (e.g. ngrok: `ngrok http 80`), get a public HTTPS URL, then call Telegram's `setWebhook`:

```bash
curl -X POST "https://api.telegram.org/bot<TOKEN>/setWebhook" \
  -d "url=https://<your-tunnel-domain>/webhooks/telegram" \
  -d "secret_token=<the TELEGRAM_WEBHOOK_SECRET value>"
```

Expected response: `{"ok":true,"result":true,...}`.

- [ ] **Step 5: Register an agent and log in**

```bash
sail artisan tinker --execute="App\Models\User::factory()->create(['email' => 'agent@example.com', 'password' => bcrypt('password')]);"
```

Visit `https://<your-tunnel-domain>/login`, sign in as `agent@example.com` / `password`.

- [ ] **Step 6: Send a real message to the bot**

From your own Telegram account, message the bot directly. Confirm the conversation appears under "Unassigned" at `/inbox` (refresh the page — this MVP uses polling/refresh, not live push, per the design spec).

- [ ] **Step 7: Claim and reply**

Click "Claim," select the conversation, type a reply, and send it. Confirm the message arrives in your Telegram client.

- [ ] **Step 8: Confirm redelivery safety**

In the Telegram app, note that BotFather-created bots don't expose a manual "redeliver" button — instead, verify idempotency by re-running Step 4's `setWebhook` call redundantly (Telegram won't redeliver on its own without a real failure), or rely on the automated `TelegramWebhookControllerTest` from Task 6 as the source of truth for this behavior. Record in your notes that this step is best-effort manual verification, not a substitute for the automated test.

---

## Self-Review Notes

- **Spec coverage**: §4 architecture → Tasks 1–9. §5 inbound flow → Tasks 5–6. §5 outbound flow → Task 8. §6 conversation state/handoff → Tasks 3, 7. §7 entities → Task 3 (all six tables). §8 error handling → covered inline in Tasks 5–8 (idempotency, claim race, send failure). §9 testing approach → all four listed scenarios have a corresponding automated test (duplicate `update_id`: Task 5 + Task 6; concurrent claim: Task 7; reopen on inbound: Task 5; unverified webhook: Task 6). §10 deferred items are not built anywhere in this plan, matching the spec.
- **Placeholder scan**: no TBD/TODO; every step has runnable code or exact commands.
- **Type consistency**: `Conversation::OWNER_UNASSIGNED`/`OWNER_HUMAN`/`OWNER_AI`, `Message::STATUS_*`/`DIRECTION_*` constants are defined once in Task 3 and referenced identically in Tasks 5, 6, 7, 8, 9. `ChannelAdapter::send(string $externalContactId, string $body): SentMessageResult` signature (Task 4) matches its call site in `SendTelegramReplyJob::handle()` (Task 8) and its test doubles (Task 4, Task 8). `MessageIngestionService::ingest()` return type (`?Message`) matches its usage in Task 5's and Task 6's tests.
