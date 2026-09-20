<?php

declare(strict_types=1);

namespace App\Filament\Resources\Users\Tables;

use App\Enums\UserStatus;
use App\Filament\Support\LtrText;
use App\Models\Role;
use App\Models\Team;
use App\Models\User;
use App\Services\Users\UserInvitationService;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\RestoreBulkAction;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

final class UsersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label(__('users.fields.name'))
                    ->searchable()
                    ->sortable(),

                LtrText::column(
                    TextColumn::make('email')
                        ->label(__('users.fields.email'))
                        ->searchable(),
                ),

                TextColumn::make('roles')
                    ->label(__('users.fields.roles'))
                    ->state(fn (User $record): array => $record->roles
                        ->map(fn (Model $role): string => (string) $role->getAttribute('display_name'))
                        ->all())
                    ->badge()
                    ->color('primary'),

                TextColumn::make('team.display_name')
                    ->label(__('users.fields.team'))
                    ->placeholder(__('users.placeholders.no_team'))
                    ->toggleable(),

                TextColumn::make('status')
                    ->label(__('users.fields.status'))
                    ->badge(),

                TextColumn::make('last_login_at')
                    ->label(__('users.fields.last_login'))
                    ->dateTime('Y-m-d H:i')
                    ->placeholder(__('users.placeholders.never_logged_in'))
                    ->sortable()
                    ->toggleable(),
            ])
            ->defaultSort('name')
            ->filters([
                SelectFilter::make('roles')
                    ->label(__('users.filters.role'))
                    ->relationship('roles', 'name', fn (Builder $query): Builder => $query->where('guard_name', 'web'))
                    ->getOptionLabelFromRecordUsing(fn (Role $record): string => $record->display_name)
                    ->preload(),

                SelectFilter::make('team')
                    ->label(__('users.filters.team'))
                    ->relationship('team', Team::localisedNameColumn())
                    ->getOptionLabelFromRecordUsing(fn (Team $record): string => $record->display_name)
                    ->preload(),

                SelectFilter::make('status')
                    ->label(__('users.filters.status'))
                    ->options(UserStatus::class),

                TrashedFilter::make()->label(__('users.filters.trashed')),
            ])
            // One three-dot menu per row instead of a wall of links; every
            // action keeps its own authorisation, and the menu hides itself
            // when the policy refuses every action in it.
            ->recordActions([
                ActionGroup::make([
                    // Visible only while the invitee has not accepted (see UserInvitationService::canBeInvited).
                    Action::make('resendInvitation')
                        ->label(__('users.invitation.resend'))
                        ->icon(Heroicon::OutlinedEnvelope)
                        ->color('gray')
                        ->requiresConfirmation()
                        ->modalHeading(__('users.invitation.resend_heading'))
                        ->modalDescription(__('users.invitation.resend_description'))
                        ->authorize(fn (User $record): bool => auth()->user()?->can('invite', $record) ?? false)
                        ->visible(fn (User $record): bool => app(UserInvitationService::class)->canBeInvited($record))
                        ->action(function (User $record): void {
                            $actor = auth()->user();

                            app(UserInvitationService::class)->invite($record, $actor instanceof User ? $actor : null);

                            Notification::make()
                                ->title(__('users.invitation.sent_title'))
                                ->body(__('users.invitation.sent_body', ['email' => $record->email]))
                                ->success()
                                ->send();
                        }),

                    EditAction::make(),
                ])
                    ->label(__('app.actions.row_actions'))
                    ->tooltip(__('app.actions.row_actions')),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make()->authorizeIndividualRecords('delete'),
                    RestoreBulkAction::make()->authorizeIndividualRecords('restore'),
                ]),
            ])
            ->emptyStateHeading(__('users.empty.heading'))
            ->emptyStateDescription(__('users.empty.description'));
    }
}
