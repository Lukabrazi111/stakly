<?php

namespace App\Filament\Resources\Pages\Schemas;

use App\Models\Page;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\MarkdownEditor;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
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
                ->alphaDash()
                ->helperText('Lowercase, dashes only. Example: privacy-policy.')
                ->unique(
                    ignoreRecord: true,
                    modifyRuleUsing: fn (Unique $rule, Get $get) => $rule->where(
                        'locale',
                        $get('locale') ?? Page::DEFAULT_LOCALE,
                    ),
                ),

            Select::make('locale')
                ->label('Locale')
                ->options(array_combine(Page::SUPPORTED_LOCALES, Page::SUPPORTED_LOCALES))
                ->default(Page::DEFAULT_LOCALE)
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
