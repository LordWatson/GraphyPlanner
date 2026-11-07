<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Step I-1.5: soft deletes on the records an agency can't afford to lose by accident.
     *
     * @var list<string>
     */
    private array $softDeletable = ['organizations', 'clients', 'posts', 'campaigns', 'assets', 'social_accounts', 'invoices'];

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        foreach ($this->softDeletable as $table) {
            Schema::table($table, function (Blueprint $table) {
                $table->softDeletes();
            });
        }

        // Invoices are legal records and must outlive a deleted client: a client with invoices can
        // no longer be hard-deleted on its own.
        //
        // This was written as NO ACTION rather than RESTRICT on the belief that NO ACTION is
        // checked at the end of the statement, so purging a whole organization — which cascades to
        // its clients *and* its invoices at once — would still work. **That is not true on
        // InnoDB**, where NO ACTION is a synonym for RESTRICT and checked immediately; MySQL does
        // not implement deferred foreign keys at all. SQLite does, which is why the test suite
        // agreed with the comment for a year. The constraint is kept (it is what protects a
        // single client's invoices), and `PurgeOrganizationJob` now deletes the invoices itself
        // before force-deleting the organization.
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropForeign(['client_id']);
            $table->foreign('client_id')->references('id')->on('clients')->noActionOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropForeign(['client_id']);
            $table->foreign('client_id')->references('id')->on('clients')->cascadeOnDelete();
        });

        foreach ($this->softDeletable as $table) {
            Schema::table($table, function (Blueprint $table) {
                $table->dropSoftDeletes();
            });
        }
    }
};
