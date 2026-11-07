<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Client onboarding templates: the settings an agency applies to every new client, saved once.
 *
 * The research named onboarding as the thing that stops an agency scaling, and recommended
 * reusable templates for workspaces, roles and approval stages. Campaign templates already exist;
 * nothing covered the step before — standing up a new client at all. For an agency signing two
 * clients a month, that is the difference between this being where work starts and where work gets
 * copied to.
 *
 * The snapshot is a single JSON column rather than thirty mirrored ones. A template is a *copy of
 * some settings at a moment in time*, not a second home for the client schema: duplicating every
 * column here would mean every future client field had to be added in two places and kept in step,
 * and the one that was forgotten would silently stop being templated. Nothing queries inside it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('client_templates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('org_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('name');
            $table->string('description')->nullable();
            // What gets copied onto a new client. See `App\Support\Clients\ClientTemplateSnapshot`
            // for the shape and, more importantly, for what is deliberately left out of it.
            $table->json('snapshot');
            // A built-in template shipped with the app rather than saved by this agency. Seeded per
            // organization so an Owner can edit or delete one without it coming back.
            $table->boolean('is_built_in')->default(false);
            $table->timestamps();

            $table->index(['org_id', 'name']);
            // `IndexCoverageTest` requires every foreign key to lead an index; without it a
            // staff-member delete would scan this table to null the column.
            $table->index('created_by');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('client_templates');
    }
};
