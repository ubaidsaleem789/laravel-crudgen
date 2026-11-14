<?php

use App\Models\TeamPage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithFileUploads;
use Livewire\WithPagination;
use Symfony\Component\HtmlSanitizer\HtmlSanitizer;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerConfig;

new #[Title('Team Pages')] #[Layout('layouts::admin')] class extends Component
{
    use WithFileUploads, WithPagination;

    public bool $showForm = false;

    #[Locked]
    public $editingId = null;

    public $region_id;

    public $title;

    public $para;

    public $body;

    public $published_on;

    public $active = false;

    public $cover;

    public $coverShow;

    public array $team = [];

    public array $logos = [];

    public function mount(): void
    {
        $this->authorize('viewAny', TeamPage::class);
    }

    #[Computed]
    public function records()
    {
        return TeamPage::query()->with(['region'])->paginate(10);
    }

    public function create(): void
    {
        $this->authorize('create', TeamPage::class);
        $this->cancel();
        $this->showForm = true;
    }

    public function edit($id): void
    {
        $record = TeamPage::findOrFail($id);
        $this->authorize('update', $record);
        $this->cancel();
        $this->editingId = $record->getKey();
        $this->region_id = $record->region_id;
        $this->title = $record->title;
        $this->para = $record->para;
        $this->body = $record->body;
        $this->published_on = $record->published_on instanceof \DateTimeInterface ? $record->published_on->format('Y-m-d') : $record->published_on;
        $this->active = (bool) $record->active;
        $this->cover = null;
        $this->coverShow = $record->cover;
        $this->team = $record->team ?? [];
        $this->logos = $record->logos ?? [];
    }

    public function cancel(): void
    {
        $this->resetValidation();
        $this->reset(['showForm', 'editingId', 'region_id', 'title', 'para', 'body', 'published_on', 'active', 'cover', 'coverShow', 'team', 'logos']);
    }

    public function save(): void
    {
        $record = $this->editingId ? TeamPage::findOrFail($this->editingId) : new TeamPage;
        $this->authorize($record->exists ? 'update' : 'create', $record);
        $this->validate(TeamPage::rules($this->editingId, $this));

        try {
            $record->region_id = $this->region_id;
            $record->title = $this->title;
            $record->para = $this->para;
            $record->body = is_string($this->body) ? (new HtmlSanitizer((new HtmlSanitizerConfig)->allowSafeElements()->withMaxInputLength(-1)))->sanitize($this->body) : null;
            $record->published_on = $this->published_on;
            $record->active = $this->active;
            if ($this->cover instanceof UploadedFile) {
                if ($record->cover) {
                    Storage::disk('public')->delete($record->cover);
                }

                $record->cover = $this->cover->store('uploads', 'public');
            }

            // team
            $existing = collect($record->team ?? [])->keyBy('uuid');
            $items = [];

            foreach ($this->team as $item) {
                $old = $existing->pull($item['uuid'] ?? '', []);
                $new = ['uuid' => $old['uuid'] ?? (string) Str::uuid()];
                if (($item['image'] ?? null) instanceof UploadedFile) {
                    if (! empty($old['image'])) {
                        Storage::disk('public')->delete($old['image']);
                    }

                    $new['image'] = $item['image']->store('uploads', 'public');
                } else {
                    $new['image'] = $old['image'] ?? null;
                }
                $new['title'] = $item['title'] ?? '';
                $new['para'] = is_string($item['para'] ?? null) ? (new HtmlSanitizer((new HtmlSanitizerConfig)->allowSafeElements()->withMaxInputLength(-1)))->sanitize($item['para'] ?? null) : '';

                $items[] = $new;
            }

            foreach ($existing as $old) {
                Storage::disk('public')->delete(array_filter([$old['image'] ?? null]));
            }

            $record->team = $items;

            // logos
            $existing = collect($record->logos ?? [])->keyBy('uuid');
            $items = [];

            foreach ($this->logos as $item) {
                $old = $existing->pull($item['uuid'] ?? '', []);
                $new = ['uuid' => $old['uuid'] ?? (string) Str::uuid()];
                if (($item['logo'] ?? null) instanceof UploadedFile) {
                    if (! empty($old['logo'])) {
                        Storage::disk('public')->delete($old['logo']);
                    }

                    $new['logo'] = $item['logo']->store('uploads', 'public');
                } else {
                    $new['logo'] = $old['logo'] ?? null;
                }

                $items[] = $new;
            }

            foreach ($existing as $old) {
                Storage::disk('public')->delete(array_filter([$old['logo'] ?? null]));
            }

            $record->logos = $items;

            $record->save();
        } catch (\Throwable $e) {
            report($e);
            $this->dispatch('toast', type: 'error', message: 'Something went wrong. Please try again.');

            return;
        }

        $this->cancel();
        $this->dispatch('toast', type: 'success', message: 'Saved!');
    }

    public function delete($id): void
    {
        $record = TeamPage::findOrFail($id);
        $this->authorize('delete', $record);
        Storage::disk('public')->delete(array_filter([$record->cover]));

        foreach ($record->team ?? [] as $item) {
            Storage::disk('public')->delete(array_filter([$item['image'] ?? null]));
        }

        foreach ($record->logos ?? [] as $item) {
            Storage::disk('public')->delete(array_filter([$item['logo'] ?? null]));
        }

        $record->delete();
        $this->dispatch('toast', type: 'success', message: 'Deleted!');
    }

    public function addteam(): void
    {
        $this->team[] = ['uuid' => (string) Str::uuid(), 'image' => null, 'title' => '', 'para' => ''];
    }

    public function removeteam($uuid): void
    {
        $this->team = array_values(array_filter($this->team, fn ($item) => ($item['uuid'] ?? null) !== $uuid));
    }

    public function addlogos(): void
    {
        $this->logos[] = ['uuid' => (string) Str::uuid(), 'logo' => null];
    }

    public function removelogos($uuid): void
    {
        $this->logos = array_values(array_filter($this->logos, fn ($item) => ($item['uuid'] ?? null) !== $uuid));
    }
};
?>

{{-- The component dispatches a plain "toast" event; this line hands it to Flux. --}}
<div class="flex flex-col gap-6" x-data
    x-on:toast.window="$flux.toast({ text: $event.detail.message, variant: $event.detail.type === 'error' ? 'danger' : 'success' })">

    <div class="flex items-center justify-between">
        <flux:heading size="xl">Team Pages</flux:heading>

        @unless ($showForm || $editingId)
            <flux:button variant="primary" icon="plus" wire:click="create">Add Team Page</flux:button>
        @endunless
    </div>

    @if ($showForm || $editingId)
        <flux:card>
            <form wire:submit="save" class="grid grid-cols-1 gap-6 sm:grid-cols-2">
                <flux:heading size="lg" class="col-span-full">{{ $editingId ? 'Edit' : 'Add' }} Team Page</flux:heading>

                <flux:select wire:model="region_id" label="Region" placeholder="Select Region">
                    @foreach (\App\Models\Region::all() as $option)
                        <flux:select.option value="{{ $option->id }}">{{ $option->name }}</flux:select.option>
                    @endforeach
                </flux:select>

                <flux:input wire:model="title" label="Title" />

                <div class="col-span-full">
                    <flux:textarea wire:model="para" label="Para" rows="4" />
                </div>

                {{-- Flux Pro is not installed, so this uses Toast UI Editor (php artisan crudgen:install-assets). --}}
                <flux:field class="col-span-full">
                    <flux:label>Body</flux:label>

                    {{-- The saved HTML is handed to the editor through $wire, never printed raw into the page. --}}
                    <div wire:ignore x-data x-init="
                        const editor = new window.Editor({
                            el: $refs.editor,
                            height: '300px',
                            initialEditType: 'wysiwyg',
                            usageStatistics: false,
                        });

                        editor.setHTML($wire.$get('body') ?? '');

                        editor.on('change', () => {
                            $wire.$set('body', editor.getHTML(), false);
                        });
                    ">
                        <div x-ref="editor"></div>
                    </div>

                    <flux:error name="body" />
                </flux:field>

                <flux:input type="date" wire:model="published_on" label="Published On" />

                <flux:switch wire:model="active" label="Active" align="left" />

                <div class="flex flex-col gap-2">
                    <flux:input type="file" wire:model="cover" label="Cover" accept="image/*" />

                    @if ($cover instanceof \Livewire\Features\SupportFileUploads\TemporaryUploadedFile && $cover->isPreviewable())
                        <img src="{{ $cover->temporaryUrl() }}" alt="" class="size-24 rounded-lg object-cover">
                    @elseif ($coverShow)
                        <img src="{{ Storage::disk('public')->url($coverShow) }}" alt="" class="size-24 rounded-lg object-cover">
                    @endif
                </div>

                <div class="col-span-full flex flex-col gap-4">
                    <flux:heading>Team</flux:heading>

                    @foreach ($team as $index => $item)
                        <flux:card wire:key="team-{{ $item['uuid'] }}" class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                            <div class="flex flex-col gap-2">
                                <flux:input type="file" wire:model="team.{{ $index }}.image" label="Image" accept="image/*" />

                                @php
                                    $value = $item['image'] ?? null;
                                @endphp

                                @if ($value instanceof \Livewire\Features\SupportFileUploads\TemporaryUploadedFile && $value->isPreviewable())
                                    <img src="{{ $value->temporaryUrl() }}" alt="" class="size-24 rounded-lg object-cover">
                                @elseif (is_string($value) && $value !== '')
                                    <img src="{{ Storage::disk('public')->url($value) }}" alt="" class="size-24 rounded-lg object-cover">
                                @endif
                            </div>

                            <flux:input wire:model="team.{{ $index }}.title" label="Title" />

                            {{-- Flux Pro is not installed, so this uses Toast UI Editor (php artisan crudgen:install-assets). --}}
                            <flux:field class="col-span-full">
                                <flux:label>Para</flux:label>

                                {{-- The saved HTML is handed to the editor through $wire, never printed raw into the page. --}}
                                <div wire:ignore x-data x-init="
                                    const editor = new window.Editor({
                                        el: $refs.editor,
                                        height: '300px',
                                        initialEditType: 'wysiwyg',
                                        usageStatistics: false,
                                    });

                                    editor.setHTML($wire.$get('team.{{ $index }}.para') ?? '');

                                    editor.on('change', () => {
                                        $wire.$set('team.{{ $index }}.para', editor.getHTML(), false);
                                    });
                                ">
                                    <div x-ref="editor"></div>
                                </div>

                                <flux:error name="team.{{ $index }}.para" />
                            </flux:field>

                            <div class="col-span-full flex justify-end">
                                <flux:button size="sm" variant="danger" icon="trash" wire:click="removeteam('{{ $item['uuid'] }}')">Remove</flux:button>
                            </div>
                        </flux:card>
                    @endforeach

                    <div>
                        <flux:button size="sm" icon="plus" wire:click="addteam">Add Team</flux:button>
                    </div>

                    {{-- Plain @error: flux:error would also repeat the errors of the items inside. --}}
                    @error('team')
                        <p class="text-sm font-medium text-red-500 dark:text-red-400">{{ $message }}</p>
                    @enderror
                </div>

                <div class="col-span-full flex flex-col gap-4">
                    <flux:heading>Logos</flux:heading>

                    @foreach ($logos as $index => $item)
                        <flux:card wire:key="logos-{{ $item['uuid'] }}" class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                            <div class="flex flex-col gap-2">
                                <flux:input type="file" wire:model="logos.{{ $index }}.logo" label="Logo" accept="image/*" />

                                @php
                                    $value = $item['logo'] ?? null;
                                @endphp

                                @if ($value instanceof \Livewire\Features\SupportFileUploads\TemporaryUploadedFile && $value->isPreviewable())
                                    <img src="{{ $value->temporaryUrl() }}" alt="" class="size-24 rounded-lg object-cover">
                                @elseif (is_string($value) && $value !== '')
                                    <img src="{{ Storage::disk('public')->url($value) }}" alt="" class="size-24 rounded-lg object-cover">
                                @endif
                            </div>

                            <div class="col-span-full flex justify-end">
                                <flux:button size="sm" variant="danger" icon="trash" wire:click="removelogos('{{ $item['uuid'] }}')">Remove</flux:button>
                            </div>
                        </flux:card>
                    @endforeach

                    <div>
                        <flux:button size="sm" icon="plus" wire:click="addlogos">Add Logos</flux:button>
                    </div>

                    {{-- Plain @error: flux:error would also repeat the errors of the items inside. --}}
                    @error('logos')
                        <p class="text-sm font-medium text-red-500 dark:text-red-400">{{ $message }}</p>
                    @enderror
                </div>

                <div class="col-span-full flex justify-end gap-2">
                    <flux:button wire:click="cancel">Cancel</flux:button>
                    <flux:button type="submit" variant="primary">Save</flux:button>
                </div>
            </form>
        </flux:card>
    @endif

    <flux:table :paginate="$this->records">
        <flux:table.columns>
            <flux:table.column>Title</flux:table.column>
            <flux:table.column>Cover</flux:table.column>
            <flux:table.column>Region Id</flux:table.column>
            <flux:table.column></flux:table.column>
        </flux:table.columns>

        <flux:table.rows>
            @foreach ($this->records as $pt)
                <flux:table.row :key="$pt->getKey()">
                    <flux:table.cell>{{ $pt->title }}</flux:table.cell>
                    <flux:table.cell>
                        @if ($pt->cover)
                            <img src="{{ Storage::disk('public')->url($pt->cover) }}" alt="" class="size-10 rounded object-cover">
                        @endif
                    </flux:table.cell>
                    <flux:table.cell>{{ $pt->region?->name }}</flux:table.cell>
                    <flux:table.cell align="end">
                        <flux:button size="sm" variant="ghost" icon="pencil-square" wire:click="edit({{ $pt->getKey() }})" />
                        <flux:button size="sm" variant="ghost" icon="trash" wire:click="delete({{ $pt->getKey() }})"
                            wire:confirm="Are you sure you want to delete this?" />
                    </flux:table.cell>
                </flux:table.row>
            @endforeach
        </flux:table.rows>
    </flux:table>
</div>
