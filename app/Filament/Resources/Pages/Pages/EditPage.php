<?php

namespace App\Filament\Resources\Pages\Pages;

use App\Filament\Resources\Pages\PageResource;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\URL;

class EditPage extends EditRecord
{
    protected static string $resource = PageResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('publish')
                ->label('Publish now')
                ->icon(Heroicon::OutlinedCheckCircle)
                ->color('success')
                ->requiresConfirmation()
                ->modalHeading('Publish this page now?')
                ->modalDescription('The page becomes live on its public URL immediately.')
                ->visible(fn (): bool => ! $this->record->isPublished())
                ->action(function () {
                    $this->record->update(['published_at' => now()]);
                    $this->refreshFormData(['published_at']);
                })
                ->successNotificationTitle('Page published'),

            Action::make('unpublish')
                ->label('Unpublish')
                ->icon(Heroicon::OutlinedEyeSlash)
                ->color('warning')
                ->requiresConfirmation()
                ->modalHeading('Unpublish this page?')
                ->modalDescription('The page returns to Draft and 404s on public URLs until republished.')
                ->visible(fn (): bool => $this->record->published_at !== null)
                ->action(function () {
                    $this->record->update(['published_at' => null]);
                    $this->refreshFormData(['published_at']);
                })
                ->successNotificationTitle('Page unpublished'),

            Action::make('preview')
                ->label('Preview')
                ->icon(Heroicon::OutlinedEye)
                ->url(fn (): string => URL::temporarySignedRoute(
                    'pages.show',
                    CarbonImmutable::now()->addMinutes(30),
                    [
                        'locale' => $this->record->locale,
                        'slug' => $this->record->slug,
                    ],
                ))
                ->openUrlInNewTab(),

            DeleteAction::make(),
        ];
    }
}
