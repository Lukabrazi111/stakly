<?php

namespace Database\Factories;

use App\Models\Page;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Page>
 */
class PageFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'slug' => fake()->unique()->slug(2),
            'locale' => Page::defaultLocale(),
            'title' => fake()->sentence(3),
            'body' => collect(fake()->paragraphs(4))
                ->map(fn (string $p) => $p)
                ->implode("\n\n"),
            'published_at' => now(),
        ];
    }

    /**
     * Unpublished — `published_at` null. Public route 404s; only the signed
     * admin preview URL renders the body.
     */
    public function draft(): self
    {
        return $this->state(['published_at' => null]);
    }

    /**
     * Future publish timestamp. Same 404 behavior as draft until the
     * timestamp passes — covered by `Page::isPublished()`.
     */
    public function scheduled(DateTimeInterface $when): self
    {
        return $this->state(['published_at' => $when]);
    }

    public function locale(string $locale): self
    {
        return $this->state(['locale' => $locale]);
    }
}
