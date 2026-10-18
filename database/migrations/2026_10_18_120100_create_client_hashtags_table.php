<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Step I-2.6 — a client's hashtag vocabulary (`tag`, how many live posts use it, when it was
     * last used), maintained by `ClientHashtagService` on post create/update, so the editor's
     * suggestions are a top-50 read instead of plucking every post's hashtags.
     *
     * `tag_key` is the lowercased tag: hashtags are case-insensitive on every platform, and it
     * keeps uniqueness identical on MySQL (case-insensitive collation) and SQLite (binary).
     */
    public function up(): void
    {
        Schema::create('client_hashtags', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained('clients')->cascadeOnDelete();
            $table->string('tag');
            $table->string('tag_key');
            $table->unsignedInteger('uses')->default(0);
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();

            $table->unique(['client_id', 'tag_key']);
            $table->index(['client_id', 'uses', 'last_used_at']);
        });

        $this->backfill();
    }

    public function down(): void
    {
        Schema::dropIfExists('client_hashtags');
    }

    /**
     * Seed the vocabulary from existing (non-deleted) posts.
     */
    private function backfill(): void
    {
        /** @var array<string, array{client_id: int, tag: string, tag_key: string, uses: int, last_used_at: string|null}> $rows */
        $rows = [];

        DB::table('posts')
            ->whereNull('deleted_at')
            ->whereNotNull('hashtags')
            ->select(['id', 'client_id', 'hashtags', 'updated_at'])
            ->orderBy('id')
            ->chunk(500, function ($posts) use (&$rows) {
                foreach ($posts as $post) {
                    $tags = json_decode((string) $post->hashtags, true);

                    if (! is_array($tags)) {
                        continue;
                    }

                    $seen = [];

                    foreach ($tags as $tag) {
                        $tag = ltrim(trim((string) $tag), '#');
                        $key = mb_strtolower($tag);

                        if ($tag === '' || isset($seen[$key])) {
                            continue;
                        }

                        $seen[$key] = true;
                        $id = $post->client_id.'|'.$key;
                        $rows[$id] ??= ['client_id' => (int) $post->client_id, 'tag' => $tag, 'tag_key' => $key, 'uses' => 0, 'last_used_at' => null];
                        $rows[$id]['uses']++;
                        $rows[$id]['last_used_at'] = max($rows[$id]['last_used_at'] ?? '', (string) $post->updated_at) ?: null;
                    }
                }
            });

        $now = Carbon::now();

        foreach (array_chunk(array_values($rows), 500) as $chunk) {
            DB::table('client_hashtags')->insert(array_map(fn (array $row) => [...$row, 'created_at' => $now, 'updated_at' => $now], $chunk));
        }
    }
};
