<?php

namespace App\Models;

use Database\Factories\PageFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Admin-managed CMS page. Body is markdown; rendered via League/CommonMark
 * which escapes raw HTML by default, so admin-pasted `<script>` can't XSS
 * the public page.
 *
 * Save/delete bust every supported locale's cache slot for the affected slug,
 * so a non-default-locale miss cached via the default fallback also clears
 * when the default row updates.
 */
class Page extends Model
{
    /** @use HasFactory<PageFactory> */
    use HasFactory;

    public const DEFAULT_LOCALE = 'en';

    /**
     * Locales we render publicly. New entries extend the cache-bust loop in
     * `booted()` — keep this list and the route's `whereIn('locale', ...)`
     * constraint in sync.
     *
     * @var list<string>
     */
    public const SUPPORTED_LOCALES = ['en'];

    protected $fillable = [
        'slug',
        'locale',
        'title',
        'body',
        'published_at',
    ];

    protected function casts(): array
    {
        return [
            'published_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        $forget = function (Page $page): void {
            foreach (self::SUPPORTED_LOCALES as $locale) {
                Cache::forget(self::cacheKey($page->slug, $locale));
            }
        };

        static::saved($forget);
        static::deleted($forget);
    }

    public static function cacheKey(string $slug, string $locale): string
    {
        return "cms.page.{$locale}.{$slug}";
    }

    /**
     * Falls back to the default locale when the requested one is missing.
     * Returns null if even the fallback is missing — controller renders 404.
     */
    public static function forSlugWithFallback(string $slug, string $locale): ?self
    {
        $page = self::query()
            ->where('slug', $slug)
            ->where('locale', $locale)
            ->first();

        if ($page !== null) {
            return $page;
        }

        if ($locale === self::DEFAULT_LOCALE) {
            return null;
        }

        return self::query()
            ->where('slug', $slug)
            ->where('locale', self::DEFAULT_LOCALE)
            ->first();
    }

    /**
     * Draft (null) and scheduled (future) both 404 publicly; admins reach
     * them via the signed preview URL.
     */
    public function isPublished(): bool
    {
        return $this->published_at !== null && $this->published_at->isPast();
    }

    public function renderedHtml(): string
    {
        return Str::markdown($this->body);
    }
}
