<?php

namespace ME\Abs\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;

/**
 * Per-business counters (invoice numbers). next() must run inside a transaction.
 */
class Sequence extends Model
{
    protected $table = 'abs_sequences';

    protected $fillable = ['business_id', 'name', 'next_value'];

    public static function next(int $businessId, string $name): int
    {
        try {
            static::query()->firstOrCreate(['business_id' => $businessId, 'name' => $name], ['next_value' => 1]);
        } catch (QueryException) {
            // Created concurrently by another transaction; the locking read below waits for it.
        }

        $row = static::query()->where('business_id', $businessId)->where('name', $name)->lockForUpdate()->firstOrFail();
        $value = (int) $row->next_value;
        $row->increment('next_value');

        return $value;
    }
}
