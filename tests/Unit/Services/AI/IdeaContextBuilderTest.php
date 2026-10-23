<?php

namespace Tests\Unit\Services\AI;

use App\Actions\Workspace\CreatePrimaryBrandAction;
use App\Models\AiPostIdea;
use App\Models\Client;
use App\Models\Organization;
use App\Services\AI\IdeaContextBuilder;
use App\Services\Events\StaticEventCalendar;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class IdeaContextBuilderTest extends TestCase
{
    use RefreshDatabase;

    private function builder(): IdeaContextBuilder
    {
        return new IdeaContextBuilder(new StaticEventCalendar);
    }

    private function brand(array $attributes = [], string $timezone = 'Europe/London'): Client
    {
        $organization = Organization::factory()->business()->create(['default_timezone' => $timezone]);
        $brand = (new CreatePrimaryBrandAction)($organization);
        $brand->update($attributes);

        return $brand->refresh();
    }

    public function test_it_builds_the_local_and_seasonal_context_for_a_uk_brand(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-02-01 09:00', 'UTC'));

        $brand = $this->brand(['location' => 'Bristol', 'countries' => ['GB']]);
        $brand->brandBrain->update(['content_pillars' => ['Fresh bakes', 'Behind the counter']]);

        $context = $this->builder()->build($brand->refresh());

        $this->assertSame('Europe/London', $context->timezone);
        $this->assertSame('GB', $context->country);
        $this->assertSame('Bristol', $context->location);
        $this->assertSame('winter', $context->season);
        $this->assertSame(['Fresh bakes', 'Behind the counter'], $context->pillars);

        $eventNames = array_map(fn ($event) => $event->name, $context->events);
        $this->assertContains('Pancake Day (Shrove Tuesday)', $eventNames);

        // The rendered block carries the season and the event so the prompt is grounded.
        $block = $context->toPromptBlock();
        $this->assertStringContainsString('Pancake Day', $block);
        $this->assertStringContainsString('winter', $block);
    }

    public function test_it_resolves_the_country_from_free_text_location(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-06-01 09:00', 'UTC'));

        $brand = $this->brand(['location' => 'Shoreditch, United Kingdom', 'countries' => []]);

        $this->assertSame('GB', $this->builder()->build($brand)->country);
    }

    public function test_it_falls_back_to_the_default_country_when_none_is_known(): void
    {
        config(['ai.ideas.default_country' => 'GB']);
        $brand = $this->brand(['location' => null, 'countries' => []]);

        $this->assertSame('GB', $this->builder()->build($brand)->country);
    }

    public function test_a_southern_hemisphere_country_flips_the_season(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-22 09:00', 'UTC'));

        $northern = $this->brand(['countries' => ['GB']]);
        $southern = $this->brand(['countries' => ['AU']], 'Australia/Sydney');

        $this->assertSame('autumn', $this->builder()->build($northern)->season);
        $this->assertSame('spring', $this->builder()->build($southern)->season);
    }

    public function test_it_lists_recent_titles_to_avoid_repeats_and_caps_them(): void
    {
        $brand = $this->brand();

        foreach (range(1, 15) as $n) {
            AiPostIdea::create([
                'org_id' => $brand->org_id,
                'client_id' => $brand->id,
                'title' => "Recent idea {$n}",
                'description' => 'x',
            ]);
        }

        $context = $this->builder()->build($brand);

        $this->assertNotEmpty($context->recentTitles);
        $this->assertLessThanOrEqual(12, count($context->recentTitles));
        $this->assertContains('Recent idea 15', $context->recentTitles);
        $this->assertStringContainsString('do NOT repeat', $context->toPromptBlock());
    }
}
