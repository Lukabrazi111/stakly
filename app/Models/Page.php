<?php

namespace App\Models;

use Database\Factories\PageFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Admin-managed CMS page (M26 Phase 1). Owns the body of static surfaces
 * like About, Privacy, Terms — content that changes on its own cadence
 * and shouldn't require a code push to update.
 *
 * Body is markdown; consumers render via `renderedHtml()`, which delegates
 * to `Str::markdown()` (League/CommonMark). CommonMark escapes raw HTML by
 * default, so admin-pasted `<script>` tags can't XSS the public page.
 *
 * Caching: public reads go through `Page::cacheKey()` + `Cache::remember()`
 * in the controller. The model events below bust every supported locale's
 * cache slot for the affected slug on save/delete — so a Spanish miss
 * cached via the English fallback also clears when the English row updates.
 */
class Page extends Model
{
    /** @use HasFactory<PageFactory> */
    use HasFactory;

    public const DEFAULT_LOCALE = 'en';

    /**
     * Locales we render publicly today. New entries here automatically extend
     * the cache-bust loop in `booted()` — keep this list and the route's
     * `whereIn('locale', ...)` constraint in sync.
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
     * Resolve a page by (slug, locale), falling back to the default locale
     * when the requested one doesn't have a row yet. Returns `null` if even
     * the fallback is missing — the controller turns that into a 404.
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
     * Published = `published_at` is set and not in the future. Draft (null)
     * and scheduled (future timestamp) both 404 publicly; admins reach them
     * via the signed preview URL.
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
