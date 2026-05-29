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
            // One-click publish. Visible when the row isn't already live
            // (Draft OR Scheduled). Filament doesn't re-render the record on
            // action completion, so the visibility callback uses `fresh()`
            // to read post-action state — without it the button stays after
            // a click until manual reload.
            Action::make('publish')
                ->label('Publish now')
                ->icon(Heroicon::OutlinedCheckCircle)
                ->color('success')
                ->visible(fn (): bool => ! $this->record->fresh()->isPublished())
                ->action(function () {
                    $this->record->update(['published_at' => now()]);
                    $this->refreshFormData(['published_at']);
                })
                ->successNotificationTitle('Page published'),

            // Unpublish reverts to Draft (clears `published_at`). Shown for
            // both Published AND Scheduled rows — Scheduled treats this as
            // cancelling the future publish. Confirmation gate because the
            // page disappears from public (more dangerous than Publish).
            Action::make('unpublish')
                ->label('Unpublish')
                ->icon(Heroicon::OutlinedEyeSlash)
                ->color('warning')
                ->requiresConfirmation()
                ->modalHeading('Unpublish this page?')
                ->modalDescription('The page returns to Draft and 404s on public URLs until republished.')
                ->visible(fn (): bool => $this->record->fresh()->published_at !== null)
                ->action(function () {
                    $this->record->update(['published_at' => null]);
                    $this->refreshFormData(['published_at']);
                })
                ->successNotificationTitle('Page unpublished'),

            // Same preview affordance as the table row action — admin can
            // verify the rendered markdown without leaving the edit screen.
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
