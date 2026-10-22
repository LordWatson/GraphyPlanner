<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Step B-1.2 — a business workspace owns exactly one "primary brand": an ordinary `clients` row
 * with `is_primary = true`. The one-primary-per-org rule is enforced at the database level by a
 * unique index on a generated column that equals `org_id` only for the primary row and NULL
 * otherwise (NULLs never collide in a unique index, which is how MySQL — with no partial indexes —
 * gets a "one true row per org" constraint). The same constraint holds on the SQLite test run.
 *
 * The column is VIRTUAL (not stored) on both drivers: `clients.org_id` carries an ON DELETE CASCADE
 * foreign key, and MySQL forbids a column with a cascading FK from being the base column of a
 * *stored* generated column (it surfaces as the misleading error 1215, "Cannot add foreign key
 * constraint"). A virtual generated column has no such restriction and a unique index on it gives
 * the identical guarantee. SQLite's `ALTER TABLE ADD COLUMN` also only accepts virtual generated
 * columns, so both drivers use the same kind. The column + index are added in separate statements
 * (MySQL can't add a virtual generated column and an index on it in one in-place ALTER).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('clients', 'is_primary')) {
            Schema::table('clients', function (Blueprint $table) {
                $table->boolean('is_primary')->default(false)->after('status');
            });
        }

        $driver = Schema::getConnection()->getDriverName();

        if (! Schema::hasColumn('clients', 'primary_org_id')) {
            Schema::table('clients', function (Blueprint $table) use ($driver) {
                match ($driver) {
                    'mysql', 'mariadb' => $table->unsignedBigInteger('primary_org_id')
                        ->virtualAs('IF(`is_primary`, `org_id`, NULL)')
                        ->nullable()
                        ->after('is_primary'),
                    'sqlite' => $table->unsignedBigInteger('primary_org_id')
                        ->virtualAs('CASE WHEN "is_primary" THEN "org_id" END')
                        ->nullable(),
                    default => throw new RuntimeException("Unsupported database driver [{$driver}] for the primary-brand constraint."),
                };
            });
        }

        Schema::table('clients', function (Blueprint $table) {
            $table->unique('primary_org_id');
        });
    }

    public function down(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->dropUnique(['primary_org_id']);
            $table->dropColumn('primary_org_id');
            $table->dropColumn('is_primary');
        });
    }
};
