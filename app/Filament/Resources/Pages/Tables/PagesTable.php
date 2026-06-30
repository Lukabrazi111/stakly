<?php

namespace App\Filament\Resources\Pages\Tables;

use App\Models\Page;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\URL;

class PagesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('updated_at', 'desc')
            ->columns([
                TextColumn::make('title')
                    ->label('Title')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('slug')
                    ->label('Slug')
                    ->searchable()
                    ->copyable()
                    ->fontFamily('mono')
                    ->size('sm'),

                TextColumn::make('locale')
                    ->label('Locale')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => config("stakly.locales_meta.$state.native_label", $state)),

                TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->state(fn (Page $record): string => self::statusOf($record))
                    ->color(fn (string $state): string => match ($state) {
                        'Published' => 'success',
                        'Scheduled' => 'warning',
                        default => 'gray',
                    }),

                TextColumn::make('published_at')
                    ->label('Publish at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(),

                TextColumn::make('updated_at')
                    ->label('Updated')
                    ->since()
                    ->sortable(),
            ])
            ->filters([
                // Status is a computed value derived from `published_at`;
                // the filter does the equivalent math in SQL.
                SelectFilter::make('status')
                    ->label('Status')
                    ->options([
                        'draft' => 'Draft',
                        'scheduled' => 'Scheduled',
                        'published' => 'Published',
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return match ($data['value'] ?? null) {
                            'draft' => $query->whereNull('published_at'),
                            'scheduled' => $query->where('published_at', '>', now()),
                            'published' => $query->whereNotNull('published_at')
                                ->where('published_at', '<=', now()),
                            default => $query,
                        };
                    }),
            ])
            ->recordActions([
                // Temporary signed URL so drafts + scheduled rows render.
                // Controller checks `hasValidSignature()` to gate the bypass.
                Action::make('preview')
                    ->label('Preview')
                    ->icon(Heroicon::OutlinedEye)
                    ->url(fn (Page $record): string => URL::temporarySignedRoute(
                        'pages.show',
                        CarbonImmutable::now()->addMinutes(30),
                        ['locale' => $record->locale, 'slug' => $record->slug],
                    ))
                    ->openUrlInNewTab(),

                EditAction::make(),
                DeleteAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    // Already-published rows are skipped silently (idempotent).
                    BulkAction::make('publish')
                        ->label('Publish selected')
                        ->icon(Heroicon::OutlinedCheckCircle)
                        ->color('success')
                        ->requiresConfirmation()
                        ->modalHeading('Publish selected pages?')
                        ->modalDescription('Already-published rows in the selection are skipped.')
                        ->action(function (Collection $records): void {
                            $count = $records->reject(fn (Page $page) => $page->isPublished())
                                ->each(fn (Page $page) => $page->update(['published_at' => now()]))
                                ->count();

                            Notification::make()
                                ->title(self::summaryTitle($count, 'published'))
                                ->success()
                                ->send();
                        })
                        ->deselectRecordsAfterCompletion(),

                    // Covers Published + Scheduled (Scheduled = cancel the
                    // future publish). Draft rows in selection are skipped.
                    BulkAction::make('unpublish')
                        ->label('Unpublish selected')
                        ->icon(Heroicon::OutlinedEyeSlash)
                        ->color('warning')
                        ->requiresConfirmation()
                        ->modalHeading('Unpublish selected pages?')
                        ->modalDescription('Each row returns to Draft. Public URLs 404 until republished.')
                        ->action(function (Collection $records): void {
                            $count = $records->filter(fn (Page $page) => $page->published_at !== null)
                                ->each(fn (Page $page) => $page->update(['published_at' => null]))
                                ->count();

                            Notification::make()
                                ->title(self::summaryTitle($count, 'unpublished'))
                                ->success()
                                ->send();
                        })
                        ->deselectRecordsAfterCompletion(),

                    DeleteBulkAction::make(),
                ]),
            ]);
    }

    private static function summaryTitle(int $count, string $verb): string
    {
        return match ($count) {
            0 => "Nothing to {$verb}",
            1 => "1 page {$verb}",
            default => "{$count} pages {$verb}",
        };
    }

    private static function statusOf(Page $page): string
    {
        if ($page->published_at === null) {
            return 'Draft';
        }

        return $page->published_at->isFuture() ? 'Scheduled' : 'Published';
    }
}
