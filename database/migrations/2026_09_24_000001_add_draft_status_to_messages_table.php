<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE messages DROP CONSTRAINT messages_status_check');
        DB::statement("ALTER TABLE messages ADD CONSTRAINT messages_status_check CHECK (status IN ('received', 'pending', 'sent', 'failed', 'draft'))");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE messages DROP CONSTRAINT messages_status_check');
        DB::statement("ALTER TABLE messages ADD CONSTRAINT messages_status_check CHECK (status IN ('received', 'pending', 'sent', 'failed'))");
    }
};
