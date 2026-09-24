<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('event_traffic_views', function (Blueprint $table) {
            $table->id();
            $table->foreignId('event_id')->constrained('events')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->char('visitor_hash', 64);
            $table->string('source_code', 32)->nullable();
            $table->timestamp('view_bucket');
           $table->dateTime('viewed_at');
            $table->char('ip_hash', 64)->nullable();
            $table->char('user_agent_hash', 64)->nullable();
            $table->timestamps();

            $table->unique(
                ['event_id', 'visitor_hash', 'view_bucket'],
                'uq_event_traffic_hour'
            );
            $table->index(
                ['event_id', 'viewed_at'],
                'idx_event_traffic_event_time'
            );
            $table->index(
                ['event_id', 'source_code', 'viewed_at'],
                'idx_event_traffic_source_time'
            );
            $table->index(
                ['event_id', 'visitor_hash', 'viewed_at'],
                'idx_event_traffic_visitor_time'
            );
        });


        // Preserve any authenticated historical views recorded by the legacy
        // event_views table. Guest traffic was never captured there, so this
        // can only carry forward what genuinely exists.
        if (
            Schema::hasTable('event_views')
            && Schema::hasColumn('event_views', 'id')
            && Schema::hasColumn('event_views', 'event_id')
            && Schema::hasColumn('event_views', 'viewed_at')
        ) {
            $fingerprintKey = (string) (config('app.key') ?: 'kakatickets');

            DB::table('event_views')
                ->orderBy('id')
                ->chunkById(500, function ($rows) use ($fingerprintKey) {
                    $inserts = [];

                    foreach ($rows as $row) {
                        if (! $row->viewed_at) {
                            continue;
                        }

                        $userId = property_exists($row, 'user_id')
                            ? $row->user_id
                            : null;

                        $legacyVisitorSeed = $userId
                            ? 'legacy-user:' . $userId
                            : 'legacy-view:' . $row->id;

                        $ipAddress = property_exists($row, 'ip_address')
                            ? $row->ip_address
                            : null;

                        $viewedAt = \Carbon\CarbonImmutable::parse(
                            (string) $row->viewed_at,
                            'UTC'
                        );

                        $inserts[] = [
                            'event_id' => $row->event_id,
                            'user_id' => $userId,
                            'visitor_hash' => hash(
                                'sha256',
                                $legacyVisitorSeed
                            ),
                            'source_code' => null,
                            'view_bucket' => $viewedAt
                                ->startOfHour()
                                ->toDateTimeString(),
                            'viewed_at' => $viewedAt->toDateTimeString(),
                            'ip_hash' => $ipAddress
                                ? hash_hmac(
                                    'sha256',
                                    (string) $ipAddress,
                                    $fingerprintKey
                                )
                                : null,
                            'user_agent_hash' => null,
                            'created_at' => $viewedAt->toDateTimeString(),
                            'updated_at' => $viewedAt->toDateTimeString(),
                        ];
                    }

                    if ($inserts !== []) {
                        DB::table('event_traffic_views')
                            ->insertOrIgnore($inserts);
                    }
                });
        }

        Schema::create('event_share_links', function (Blueprint $table) {
            $table->id();
            $table->foreignId('event_id')->constrained('events')->cascadeOnDelete();
            $table->string('label', 80);
            $table->string('source_code', 32);
            $table->boolean('is_system')->default(false);
            $table->timestamps();

            $table->unique(
                ['event_id', 'source_code'],
                'uq_event_share_source'
            );
            $table->index(
                ['event_id', 'created_at'],
                'idx_event_share_event_created'
            );
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->char('visitor_hash', 64)->nullable()->after('checkout_token_hash');
            $table->index(
                ['visitor_hash', 'created_at'],
                'idx_orders_visitor_created'
            );
        });

        Schema::table('order_items', function (Blueprint $table) {
            $table->string('source_code', 32)->nullable()->after('seller_user_id');
            $table->foreignId('attributed_event_view_id')
                ->nullable()
                ->after('source_code')
                ->constrained('event_traffic_views')
                ->nullOnDelete();

            $table->index(
                ['event_id', 'source_code', 'created_at'],
                'idx_order_items_event_source'
            );
        });
    }

    public function down(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            $table->dropIndex('idx_order_items_event_source');
            $table->dropConstrainedForeignId('attributed_event_view_id');
            $table->dropColumn('source_code');
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->dropIndex('idx_orders_visitor_created');
            $table->dropColumn('visitor_hash');
        });

        Schema::dropIfExists('event_share_links');
        Schema::dropIfExists('event_traffic_views');
    }
};
