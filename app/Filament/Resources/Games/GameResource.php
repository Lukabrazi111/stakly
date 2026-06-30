<?php

namespace App\Filament\Resources\Games;

use App\Enums\Game as GameEnum;
use App\Enums\GameStatus;
use App\Filament\Resources\Games\Pages\ManageGames;
use App\Models\Game;
use BackedEnum;
use Closure;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Cache;

/**
 * Admin catalog for the homepage GameSelector tiles. Server rule: Active is
 * only allowed when the slug matches an `App\Enums\Game` case that has a wired
 * settlement adapter (`hasArbitrationDriver()`) — guards admin from
 * advertising a "live" game before its backend integration exists (M42:
 * tightened from bare enum membership, which let adapter-less Dota2 go Active).
 */
class GameResource extends Resource
{
    protected static ?string $model = Game::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    protected static string|\UnitEnum|null $navigationGroup = 'Catalog';

    protected static ?string $navigationLabel = 'Games';

    protected static ?string $modelLabel = 'Game';

    protected static ?string $pluralModelLabel = 'Games';

    protected static ?string $slug = 'games';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('display_name')
                ->label('Display name')
                ->required()
                ->maxLength(120),

            TextInput::make('slug')
                ->label('Slug')
                ->required()
                ->maxLength(64)
                ->alphaDash()
                ->unique(ignoreRecord: true)
                // `->rule()` (singular) so Filament injects `$get`; `->rules([...])`
                // hands the closure straight to Laravel, which calls it with
                // ($attribute,...) — `$get` would be the attribute string and the
                // returned closure silently ignored (the gate was a no-op).
                ->rule(
                    fn (Get $get): Closure => function (string $attribute, mixed $value, Closure $fail) use ($get) {
                        // $get('status') may be the enum or its backing string
                        // depending on the Select's option source — normalize.
                        $status = $get('status');
                        $isActive = $status instanceof GameStatus
                            ? $status === GameStatus::Active
                            : $status === GameStatus::Active->value;

                        if ($isActive
                            && GameEnum::tryFrom((string) $value)?->hasArbitrationDriver() !== true) {
                            $fail('Slug must match an App\\Enums\\Game case with a wired settlement adapter before this game can be set to Active.');
                        }
                    },
                ),

            FileUpload::make('poster_path')
                ->label('Poster')
                ->image()
                ->imageEditor()
                ->disk('public')
                ->directory('games')
                ->maxSize(5120)
                ->imageResizeMode('cover')
                ->imageResizeTargetWidth('600')
                ->imageResizeTargetHeight('900')
                ->rules(['dimensions:min_width=480,min_height=640'])
                ->helperText('Min 480×640. Resized to 600×900 on upload.'),

            Select::make('status')
                ->label('Status')
                ->options(GameStatus::class)
                ->default(GameStatus::ComingSoon->value)
                ->required(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('position')
            ->reorderable('position')
            // Filament's reorderTable bypasses Eloquent events (raw SQL
            // CASE update), so `Game::saved` doesn't fire — bust manually.
            ->afterReordering(fn () => Cache::forget(Game::HOMEPAGE_CACHE_KEY))
            ->columns([
                ImageColumn::make('poster_path')
                    ->label('Poster')
                    ->disk('public')
                    ->height(72)
                    ->width(48)
                    ->defaultImageUrl('https://placehold.co/48x72/14101c/8a8696?text=No+poster')
                    ->extraImgAttributes(['class' => 'rounded-md object-cover']),

                TextColumn::make('display_name')
                    ->label('Name')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('slug')
                    ->label('Slug')
                    ->searchable()
                    ->copyable()
                    ->fontFamily('mono')
                    ->size('sm'),

                TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->color(fn (GameStatus $state) => match ($state) {
                        GameStatus::Active => 'success',
                        GameStatus::ComingSoon => 'warning',
                        GameStatus::Disabled => 'gray',
                    }),

                TextColumn::make('position')
                    ->label('Pos')
                    ->sortable(),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageGames::route('/'),
        ];
    }
}
