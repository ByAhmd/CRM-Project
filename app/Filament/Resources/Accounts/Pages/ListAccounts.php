<?php

declare(strict_types=1);

namespace App\Filament\Resources\Accounts\Pages;

use App\Enums\AccountType;
use App\Filament\Concerns\HasSavedViews;
use App\Filament\Imports\AccountImporter;
use App\Filament\Resources\Accounts\AccountResource;
use App\Filament\Support\CustomFieldActions;
use App\Filament\Support\ImportExportActions;
use App\Filament\Support\SavedViewActions;
use App\Models\Account;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;

final class ListAccounts extends ListRecords
{
    use HasSavedViews;

    protected static string $resource = AccountResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ...SavedViewActions::for($this),
            ImportExportActions::import(AccountImporter::class, Account::class),
            // A panel that opens this as a modal instead of linking to the
            // create page builds it from the entity form, custom section
            // included, so the state is stripped and written here too (D-9).
            CustomFieldActions::createAction(CreateAction::make()),
        ];
    }

    /**
     * @return array<string, Tab>
     */
    public function getTabs(): array
    {
        $tabs = ['all' => Tab::make(__('accounts.tabs.all'))];

        foreach (AccountType::cases() as $type) {
            $tabs[$type->value] = Tab::make($type->getLabel())
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->where('type', $type->value));
        }

        return $tabs;
    }
}
