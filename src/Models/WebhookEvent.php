<?php

namespace ME\Abs\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Received provider event. The unique (provider, event_id) key makes processing idempotent.
 * Only a hash of the payload is stored, never the payload itself.
 */
class WebhookEvent extends Model
{
    protected $table = 'abs_webhook_events';

    protected $fillable = ['provider', 'event_id', 'type', 'status', 'payload_hash', 'error', 'processed_at'];

    protected function casts(): array
    {
        return ['processed_at' => 'immutable_datetime'];
    }
}
