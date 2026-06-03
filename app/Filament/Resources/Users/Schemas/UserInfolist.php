<?php

namespace App\Filament\Resources\Users\Schemas;

use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;

/**
 * Phase 1 stub — minimal one-row identity view so the ViewUser page renders.
 * Phase 2 expands this into full sections (profile, linked accounts, wallet
 * snapshot + invariant verify, username history, recent listings/matches,
 * open disputes).
 */
class UserInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Identity')
                    ->icon(Heroicon::User)
                    ->columnSpanFull()
                    ->columns(4)
                    ->schema([
                        TextEntry::make('id')->label('User #'),
                        TextEntry::make('username')->copyable(),
                        TextEntry::make('name')->label('Name'),
                        TextEntry::make('email')->copyable(),
                    ]),
            ]);
    }
}
