<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ticket_types', function (Blueprint $table) {
            if (! Schema::hasColumn('ticket_types', 'unit_type')) {
                $table
                    ->enum('unit_type', ['individual', 'table'])
                    ->default('individual')
                    ->after('name');
            }

            if (! Schema::hasColumn('ticket_types', 'color')) {
                $table
                    ->string('color', 20)
                    ->nullable()
                    ->after('unit_type');
            }

            if (! Schema::hasColumn('ticket_types', 'people_per_unit')) {
                $table
                    ->unsignedInteger('people_per_unit')
                    ->default(1)
                    ->after('quantity');
            }

            if (! Schema::hasColumn('ticket_types', 'max_per_person')) {
                $table
                    ->unsignedInteger('max_per_person')
                    ->nullable()
                    ->after('people_per_unit');
            }

            if (! Schema::hasColumn('ticket_types', 'visible')) {
                $table
                    ->boolean('visible')
                    ->default(true)
                    ->after('max_per_person');
            }
        });

        DB::table('ticket_types')
            ->whereRaw('LOWER(name) LIKE ?', ['%table%'])
            ->update([
                'unit_type' => 'table',
                'people_per_unit' => 10,
            ]);
    }

    public function down(): void
    {
        $columns = collect([
            'unit_type',
            'color',
            'people_per_unit',
            'max_per_person',
            'visible',
        ])
            ->filter(
                fn (string $column) =>
                    Schema::hasColumn(
                        'ticket_types',
                        $column
                    )
            )
            ->all();

        if ($columns !== []) {
            Schema::table(
                'ticket_types',
                fn (Blueprint $table) =>
                    $table->dropColumn($columns)
            );
        }
    }
};