<?php

use App\Concerns\InteractsWithAuthenticatedUser;
use App\Models\User;
use Flux\Flux;
use Illuminate\Pagination\LengthAwarePaginator;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('Users')] class extends Component {
    use InteractsWithAuthenticatedUser;
    use WithPagination;

    /**
     * Guard against direct Livewire updates bypassing the route middleware.
     */
    public function mount(): void
    {
        $this->ensureAdministrator();
    }

    /**
     * Disable or re-enable a user's account. Disabling is a local override that blocks access immediately, even while
     * the user remains in the GitHub org.
     */
    public function toggleDisabled(int $userId): void
    {
        $this->ensureAdministrator();

        $user = User::query()->findOrFail($userId);

        $disabling = ! $user->isDisabled();

        if ($this->isProtected($user, removesAdminAccess: $disabling && $user->isAdministrator())) {
            return;
        }

        $user->forceFill(['disabled_at' => $disabling ? now() : null])->save();

        Flux::toast(variant: 'success', text: __('User updated.'));
    }

    /**
     * Permanently delete a user's local record. They are re-provisioned on their next GitHub sign-in unless removed
     * from the organization.
     */
    public function deleteUser(int $userId): void
    {
        $this->ensureAdministrator();

        $user = User::query()->findOrFail($userId);

        if ($this->isProtected($user, removesAdminAccess: $user->isAdministrator())) {
            return;
        }

        $user->delete();

        Flux::toast(variant: 'success', text: __('User deleted.'));
    }

    /**
     * All users, administrators first then alphabetically.
     *
     * @return LengthAwarePaginator<int, User>
     */
    #[Computed]
    public function users(): LengthAwarePaginator
    {
        return User::query()
            ->orderByDesc('is_admin')
            ->orderBy('name')
            ->paginate(25);
    }

    /**
     * Determine whether an action on the user must be refused, emitting an explanatory toast when it is.
     */
    private function isProtected(User $user, bool $removesAdminAccess): bool
    {
        if ($user->is($this->authenticatedUser())) {
            Flux::toast(variant: 'warning', text: __('You cannot modify your own account here.'));

            return true;
        }

        if ($removesAdminAccess && $this->isLastAdministrator($user)) {
            Flux::toast(variant: 'warning', text: __('You cannot remove the last administrator.'));

            return true;
        }

        return false;
    }

    private function isLastAdministrator(User $user): bool
    {
        return User::query()
            ->where('is_admin', true)
            ->whereKeyNot($user->getKey())
            ->doesntExist();
    }

    private function ensureAdministrator(): void
    {
        abort_unless($this->authenticatedUser()->isAdministrator(), 403);
    }
}; ?>

<div class="flex w-full flex-col gap-6">
    <div>
        <flux:heading size="xl">{{ __('Users') }}</flux:heading>
        <flux:text class="mt-1">
            {{ __('Members of your GitHub organization. Owners are administrators; everyone else is a user. Disable an account to block access immediately.') }}
        </flux:text>
    </div>

    <flux:table>
        <flux:table.columns>
            <flux:table.column>{{ __('Name') }}</flux:table.column>
            <flux:table.column>{{ __('Email') }}</flux:table.column>
            <flux:table.column>{{ __('Role') }}</flux:table.column>
            <flux:table.column>{{ __('Status') }}</flux:table.column>
            <flux:table.column>{{ __('Created') }}</flux:table.column>
            <flux:table.column />
        </flux:table.columns>

        <flux:table.rows>
            @foreach ($this->users as $user)
                <flux:table.row :key="$user->id">
                    <flux:table.cell variant="strong">{{ $user->name }}</flux:table.cell>
                    <flux:table.cell>{{ $user->email }}</flux:table.cell>
                    <flux:table.cell>
                        @if ($user->isAdministrator())
                            <flux:badge
                                color="indigo"
                                size="sm"
                                icon="shield-check"
                                >{{ __('Administrator') }}</flux:badge
                            >
                        @else
                            <flux:badge color="zinc" size="sm">{{ __('User') }}</flux:badge>
                        @endif
                    </flux:table.cell>
                    <flux:table.cell>
                        @if ($user->isDisabled())
                            <flux:badge color="red" size="sm">{{ __('Disabled') }}</flux:badge>
                        @else
                            <flux:badge color="green" size="sm">{{ __('Active') }}</flux:badge>
                        @endif
                    </flux:table.cell>
                    <flux:table.cell>{{ $user->created_at?->format('Y-m-d') }}</flux:table.cell>
                    <flux:table.cell>
                        @unless ($user->is(auth()->user()))
                            <flux:dropdown align="end">
                                <flux:button variant="ghost" size="sm" icon="ellipsis-horizontal" inset="right" />

                                <flux:menu>
                                    <flux:menu.item
                                        :icon="$user->isDisabled() ? 'lock-open' : 'lock-closed'"
                                        wire:click="toggleDisabled({{ $user->id }})"
                                        data-test="toggle-disabled"
                                    >
                                        {{ $user->isDisabled() ? __('Enable') : __('Disable') }}
                                    </flux:menu.item>

                                    <flux:menu.separator />

                                    <flux:menu.item
                                        variant="danger"
                                        icon="trash"
                                        wire:click="deleteUser({{ $user->id }})"
                                        wire:confirm="{{ __('Delete this user permanently? Files they uploaded remain in their shares.') }}"
                                        data-test="delete-user"
                                    >
                                        {{ __('Delete') }}
                                    </flux:menu.item>
                                </flux:menu>
                            </flux:dropdown>
                        @endunless
                    </flux:table.cell>
                </flux:table.row>
            @endforeach
        </flux:table.rows>
    </flux:table>

    {{ $this->users->links() }}
</div>
