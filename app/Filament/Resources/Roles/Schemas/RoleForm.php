<?php

declare(strict_types=1);

namespace App\Filament\Resources\Roles\Schemas;

use App\Enums\Permission;
use App\Models\Role;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Validation\Rule;

/**
 * Create / edit role.
 *
 * The machine key is fixed once a role is seeded (code refers to it); display
 * names and permissions stay editable. Permissions are not a model attribute:
 * the pages strip them from the data and hand them to RoleService.
 */
final class RoleForm
{
    /** The permission group of the four workspace keys (D-23). */
    private const string WORKSPACE_GROUP = 'workspace';

    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make(__('roles.sections.details'))
                    ->schema([
                        TextInput::make('name')
                            ->label(__('roles.fields.key'))
                            ->helperText(__('roles.helpers.key'))
                            ->placeholder(__('roles.placeholders.key'))
                            ->required()
                            ->minLength(3)
                            ->maxLength(50)
                            ->regex('/^[a-z][a-z0-9_]*$/')
                            ->validationMessages(['regex' => __('roles.validation.key_format')])
                            ->rules([
                                fn (?Role $record): object => Rule::unique('roles', 'name')
                                    ->where('guard_name', 'web')
                                    ->ignore($record?->getKey()),
                            ])
                            ->disabled(fn (?Role $record): bool => $record?->isSeeded() ?? false)
                            ->dehydrated(fn (?Role $record): bool => ! ($record?->isSeeded() ?? false))
                            ->extraInputAttributes(['dir' => 'ltr'])
                            ->columnSpanFull(),

                        TextInput::make('name_ar')
                            ->label(__('roles.fields.name_ar'))
                            ->required()
                            ->minLength(2)
                            ->maxLength(100),

                        TextInput::make('name_en')
                            ->label(__('roles.fields.name_en'))
                            ->required()
                            ->minLength(2)
                            ->maxLength(100),
                    ])
                    ->columns(['default' => 1, 'lg' => 2]),

                // D-23: which workspaces the role may enter, ahead of what it
                // may do inside them. Same `permissions.<group>` state as every
                // other list, so RoleService saves it like any permission.
                Section::make(__('roles.sections.workspaces'))
                    ->description(__('roles.helpers.workspaces'))
                    ->schema([self::permissionList(self::WORKSPACE_GROUP, Permission::workspaces())->hiddenLabel()])
                    ->columns(1),

                Section::make(__('roles.sections.permissions'))
                    ->description(__('roles.helpers.permissions'))
                    ->schema(self::permissionGroups())
                    ->columns(1),
            ])
            ->columns(1);
    }

    /**
     * One checkbox list per permission group, in enum declaration order; the
     * workspace keys have their own section above.
     *
     * @return list<CheckboxList>
     */
    private static function permissionGroups(): array
    {
        $lists = [];

        foreach (Permission::grouped() as $group => $permissions) {
            if ($group === self::WORKSPACE_GROUP) {
                continue;
            }

            $lists[] = self::permissionList($group, $permissions);
        }

        return $lists;
    }

    /**
     * @param  list<Permission>  $permissions
     */
    private static function permissionList(string $group, array $permissions): CheckboxList
    {
        $options = [];

        foreach ($permissions as $permission) {
            $options[$permission->value] = $permission->label();
        }

        return CheckboxList::make('permissions.'.$group)
            ->label(__('permissions.groups.'.$group))
            ->options($options)
            ->bulkToggleable()
            ->columns(3);
    }
}
