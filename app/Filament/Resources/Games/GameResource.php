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
 * only allowed when slug matches an `App\Enums\Game` enum case — guards
 * admin from advertising a "live" game before backend integration exists.
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
                ->rules([
                    fn (Get $get): Closure => function (string $attribute, mixed $value, Closure $fail) use ($get) {
                        if ($get('status') === GameStatus::Active->value
                            && GameEnum::tryFrom((string) $value) === null) {
                            $fail('Slug must match an App\\Enums\\Game enum case before this game can be set to Active.');
                        }
                    },
                ]),

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
                ->options([
                    GameStatus::Active->value => 'Active',
                    GameStatus::ComingSoon->value => 'Coming soon',
                    GameStatus::Disabled->value => 'Disabled',
                ])
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
