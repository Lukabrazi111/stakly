<?php

namespace App\Filament\Resources\Pages\Schemas;

use App\Models\Page;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\MarkdownEditor;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Unique;

/**
 * Slug + locale together are the public URL key, so the unique check is
 * composite — same slug allowed across locales for future translations.
 */
class PageForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('title')
                ->label('Title')
                ->required()
                ->maxLength(200)
                ->columnSpanFull(),

            TextInput::make('slug')
                ->label('Slug')
                ->required()
                ->maxLength(64)
                ->rule('regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/')
                ->dehydrateStateUsing(fn (?string $state): string => Str::slug((string) $state))
                ->helperText('Lowercase letters, numbers, and single dashes only. Example: privacy-policy.')
                ->unique(
                    ignoreRecord: true,
                    modifyRuleUsing: fn (Unique $rule, Get $get) => $rule->where(
                        'locale',
                        $get('locale') ?? Page::defaultLocale(),
                    ),
                ),

            Select::make('locale')
                ->label('Locale')
                ->options(collect(Page::supportedLocales())
                    ->mapWithKeys(fn (string $l): array => [$l => config("stakly.locales_meta.$l.native_label", $l)])
                    ->all())
                ->default(Page::defaultLocale())
                ->required()
                ->native(false),

            DateTimePicker::make('published_at')
                ->label('Publish at')
                ->seconds(false)
                ->helperText('Leave empty to save as draft. Future timestamp = scheduled (404 until then).')
                ->columnSpanFull(),

            MarkdownEditor::make('body')
                ->label('Body')
                ->required()
                ->columnSpanFull(),
        ]);
    }
}
