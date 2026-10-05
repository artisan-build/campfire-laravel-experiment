<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('accounts', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('join_code');
            $table->text('custom_styles')->nullable();
            $table->json('settings')->nullable();
            $table->unsignedInteger('singleton_guard')->default(0)->unique();
            $table->timestamps(6);
        });

        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email_address')->nullable()->unique();
            $table->string('password_digest')->nullable();
            $table->text('bio')->nullable();
            $table->string('bot_token')->nullable()->unique();
            $table->unsignedTinyInteger('role')->default(0);
            $table->unsignedTinyInteger('status')->default(0);
            $table->timestamps(6);
        });

        Schema::create('rooms', function (Blueprint $table) {
            $table->id();
            $table->string('name')->nullable();
            $table->string('type');
            $table->foreignId('creator_id')->constrained('users');
            $table->timestamps(6);
        });

        Schema::create('memberships', function (Blueprint $table) {
            $table->id();
            $table->foreignId('room_id')->constrained('rooms')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('involvement')->default('mentions');
            $table->unsignedInteger('connections')->default(0);
            $table->timestamp('connected_at', 6)->nullable();
            $table->timestamp('unread_at', 6)->nullable();
            $table->timestamps(6);

            $table->unique(['room_id', 'user_id']);
            $table->index(['room_id', 'created_at']);
            $table->index('user_id');
        });

        Schema::create('messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('room_id')->constrained('rooms')->cascadeOnDelete();
            $table->foreignId('creator_id')->constrained('users');
            $table->string('client_message_id');
            $table->timestamps(6);

            $table->index(['room_id', 'created_at']);
            $table->index('creator_id');
        });

        // Postgres full text search. Upstream used an SQLite FTS5 virtual table keyed by rowid;
        // this is the first-party equivalent: one row per message plus a fullText (GIN) index.
        Schema::create('message_search_index', function (Blueprint $table) {
            $table->foreignId('message_id')->primary()->constrained('messages')->cascadeOnDelete();
            $table->text('body');

            $table->fullText('body');
        });

        Schema::create('boosts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('message_id')->constrained('messages')->cascadeOnDelete();
            $table->foreignId('booster_id')->constrained('users')->cascadeOnDelete();
            $table->string('content', 16);
            $table->timestamps(6);
        });

        Schema::create('sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('token')->unique();
            $table->string('ip_address')->nullable();
            $table->string('user_agent')->nullable();
            $table->timestamp('last_active_at', 6);
            $table->timestamps(6);
        });

        Schema::create('push_subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('endpoint')->nullable();
            $table->string('p256dh_key')->nullable();
            $table->string('auth_key')->nullable();
            $table->string('user_agent')->nullable();
            $table->timestamps(6);

            $table->index(['endpoint', 'p256dh_key', 'auth_key'], 'push_subscriptions_keys_index');
        });

        Schema::create('webhooks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('url')->nullable();
            $table->timestamps(6);
        });

        Schema::create('searches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('query');
            $table->timestamps(6);
        });

        Schema::create('bans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('ip_address')->index();
            $table->timestamps(6);
        });

        Schema::create('action_text_rich_texts', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->text('body')->nullable();
            $table->string('record_type');
            $table->unsignedBigInteger('record_id');
            $table->timestamps(6);

            $table->unique(['record_type', 'record_id', 'name'], 'action_text_rich_texts_uniqueness');
        });

        Schema::create('active_storage_blobs', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->string('filename');
            $table->string('content_type')->nullable();
            $table->text('metadata')->nullable();
            $table->string('service_name');
            $table->unsignedBigInteger('byte_size');
            $table->string('checksum')->nullable();
            $table->timestamp('created_at', 6);
        });

        Schema::create('active_storage_attachments', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('record_type');
            $table->unsignedBigInteger('record_id');
            $table->foreignId('blob_id')->constrained('active_storage_blobs')->cascadeOnDelete();
            $table->timestamp('created_at', 6);

            $table->unique(['record_type', 'record_id', 'name', 'blob_id'], 'active_storage_attachments_uniqueness');
            $table->index('blob_id');
        });
    }

    public function down(): void
    {
        foreach ([
            'active_storage_attachments', 'active_storage_blobs', 'action_text_rich_texts',
            'bans', 'searches', 'webhooks', 'push_subscriptions', 'sessions', 'boosts',
            'message_search_index', 'messages', 'memberships', 'rooms', 'users', 'accounts',
        ] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
