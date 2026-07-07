<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('enrichment_proposals', function (Blueprint $table) {
            $table->text('value')->nullable()->after('value_id');
        });

        if (DB::connection()->getDriverName() === 'sqlite') {
            $this->backfillPortably('enrichment_proposals');
        } else {
            // A single set-based UPDATE (fast even over 100k+ rows) instead
            // of a per-row PHP loop, which would hold the ACCESS EXCLUSIVE
            // lock from the `text('value')` column add above for as long as
            // the backfill took, blocking every other query against this
            // table in the meantime.
            DB::statement(<<<'SQL'
                UPDATE enrichment_proposals
                SET value = COALESCE(value_text, RTRIM(RTRIM(value_num::text, '0'), '.'))
                WHERE value_num IS NOT NULL OR value_text IS NOT NULL
            SQL);
        }

        Schema::table('enrichment_proposals', function (Blueprint $table) {
            $table->dropColumn(['value_num', 'value_text']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('enrichment_proposals', function (Blueprint $table) {
            $table->decimal('value_num', 12, 3)->nullable()->after('value_id');
            $table->text('value_text')->nullable()->after('value_num');
        });

        if (DB::connection()->getDriverName() === 'sqlite') {
            DB::table('enrichment_proposals')
                ->whereNotNull('value')
                ->orderBy('id')
                ->chunkById(500, function ($rows) {
                    foreach ($rows as $row) {
                        $isNumeric = is_numeric($row->value);

                        DB::table('enrichment_proposals')->where('id', $row->id)->update([
                            'value_num' => $isNumeric ? $row->value : null,
                            'value_text' => $isNumeric ? null : $row->value,
                        ]);
                    }
                });
        } else {
            DB::statement(<<<'SQL'
                UPDATE enrichment_proposals
                SET
                    value_num = CASE WHEN value ~ '^-?[0-9]+(\.[0-9]+)?$' THEN value::numeric ELSE NULL END,
                    value_text = CASE WHEN value !~ '^-?[0-9]+(\.[0-9]+)?$' THEN value ELSE NULL END
                WHERE value IS NOT NULL
            SQL);
        }

        Schema::table('enrichment_proposals', function (Blueprint $table) {
            $table->dropColumn('value');
        });
    }

    /**
     * SQLite has no reliable cross-version numeric-to-trimmed-string cast, so
     * the (test-only, always small) SQLite path backfills row-by-row in PHP
     * instead of a single driver-specific statement.
     */
    private function backfillPortably(string $table): void
    {
        DB::table($table)
            ->where(fn ($query) => $query->whereNotNull('value_num')->orWhereNotNull('value_text'))
            ->orderBy('id')
            ->chunkById(500, function ($rows) use ($table) {
                foreach ($rows as $row) {
                    DB::table($table)->where('id', $row->id)->update([
                        'value' => $row->value_text ?? rtrim(rtrim((string) $row->value_num, '0'), '.'),
                    ]);
                }
            });
    }
};
