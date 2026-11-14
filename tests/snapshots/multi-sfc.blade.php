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

    public $active;

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
        $this->active = $record->active;
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
            $record->body = $this->body;
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
                $new['para'] = $item['para'] ?? '';

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

<div class="max-w-4xl mx-auto mt-10">

    <div x-data="{ show: false, message: '', type: 'success' }"
        x-on:toast.window="message = $event.detail.message; type = $event.detail.type; show = true; setTimeout(() => show = false, 3000)"
        x-show="show"
        :class="type === 'success' ? 'bg-green-100 text-green-700 border-green-500' :
            'bg-red-100 text-red-700 border-red-500'"
        class="fixed top-4 right-4 px-4 py-2 rounded shadow flex items-center gap-2 border border-opacity-50">

        <span x-text="message"></span>

        <!-- Close Button -->
        <button @click="show = false" class="ml-2 text-lg font-bold">
            &times;
        </button>
    </div>

    <div class="flex w-full mt-[20px] justify-between items-center">
        <h2 class="text-xl font-bold text-gray-800">Team Pages</h2>



        <button wire:click="{{ $showForm ? 'cancel' : 'create' }}"
            class="rounded-md bg-black px-3 py-2 text-sm font-semibold text-white shadow-xs hover:bg-gray-800 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-black">
            {{ $showForm ? 'Close' : 'Add Team Page' }}
            <svg wire:loading wire:target="create, cancel" class="w-4 h-4 animate-spin text-white inline-block"
                fill="none" viewBox="0 0 24 24">
                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4">
                </circle>
                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z"></path>
            </svg>
        </button>



    </div>

    @if ($showForm)
        <form wire:submit="save">



            <div class="mt-10 grid grid-cols-1 gap-x-6 gap-y-8 sm:grid-cols-6">


                                                        <div class="sm:col-span-3">
                                            <label class="block text-sm font-medium text-gray-900">Foreign Key: Region Id</label>
                                            <div class="mt-2 relative">
                                                <select wire:model="region_id"
                                                    class="w-full appearance-none rounded-md bg-white py-1.5 pr-8 pl-3 text-base text-gray-900 outline-1 outline-gray-300 focus:outline-2 focus:outline-black sm:text-sm">
                                                    <option value="">--Select Region Id--</option>
                                                    @foreach (\App\Models\Region::all() as $item)
                                                        <option value="{{ $item->id }}">{{ $item->id }}-{{ $item->name }}
                                                        </option>
                                                    @endforeach
                                                </select>
                                                <svg class="pointer-events-none absolute right-3 top-1/2 transform -translate-y-1/2 size-5 text-gray-500"
                                                    viewBox="0 0 16 16" fill="currentColor" aria-hidden="true">
                                                    <path fill-rule="evenodd"
                                                        d="M4.22 6.22a.75.75 0 0 1 1.06 0L8 8.94l2.72-2.72a.75.75 0 1 1 1.06 1.06l-3.25 3.25a.75.75 0 0 1-1.06 0L4.22 7.28a.75.75 0 0 1 0-1.06Z"
                                                        clip-rule="evenodd" />
                                                </svg>
                                            </div>
                                            @if ($errors->has("region_id"))
                                                <span class="text-red-500 text-sm">{{ $errors->first("region_id") }}</span>
                                            @endif
                                        </div>


                    <div class="sm:col-span-3">
                        <label class="block text-sm/6 font-medium text-gray-900">title</label>
                        <div class="mt-2">
                            <input type="text" wire:model="title"
                                class="block w-full rounded-md bg-white px-3 py-1.5 text-base text-gray-900 outline-1 -outline-offset-1 outline-gray-300 placeholder:text-gray-400 focus:outline-2 focus:-outline-offset-2 focus:outline-black sm:text-sm/6">
                        </div>
                        @if ($errors->has("title"))
                            <span class="text-red-500 text-sm">{{ $errors->first("title") }}</span>
                        @endif
                    </div>


                    <div class="col-span-full">
                        <label class="block text-sm/6 font-medium text-gray-900">para</label>
                        <div class="mt-2">
                            <textarea type="text" wire:model="para"
                                class="block w-full rounded-md bg-white px-3 py-1.5 text-base text-gray-900 outline-1 -outline-offset-1 outline-gray-300 placeholder:text-gray-400 focus:outline-2 focus:-outline-offset-2 focus:outline-black sm:text-sm/6"></textarea>
                        </div>
                        @if ($errors->has("para"))
                            <span class="text-red-500 text-sm">{{ $errors->first("para") }}</span>
                        @endif
                    </div>


<div class="col-span-full">
    <label class="block text-sm/6 font-medium text-gray-900">Body</label>
    {{-- The saved HTML is handed to the editor through $wire, never printed raw into the page. --}}
    <div class="mt-2" wire:ignore x-data x-init="
        const editor = new window.Editor({
            el: $refs.editor,
            height: '300px',
            initialEditType: 'wysiwyg',
            previewStyle: 'vertical',
            toolbarItems: [
                ['heading', 'bold', 'italic'],
                ['hr', 'quote'],
                ['ul', 'ol', 'task'],
                ['table'],
                ['link'],
                ['code', 'codeblock']
            ]
        });

        editor.setHTML($wire.$get('body') ?? '');

        editor.on('change', () => {
            $wire.$set('body', editor.getHTML(), false);
        });
    ">
        <div x-ref="editor"></div>
    </div>
    @if ($errors->has("body"))
        <span class="text-red-500 text-sm">{{ $errors->first("body") }}</span>
    @endif
</div>


                    <div class="sm:col-span-3">
                        <label class="block text-sm/6 font-medium text-gray-900">Published On</label>
                        <div class="mt-2">
                            <input type="date" wire:model="published_on"
                                class="block w-full rounded-md bg-white px-3 py-1.5 text-base text-gray-900 outline-1 -outline-offset-1 outline-gray-300 placeholder:text-gray-400 focus:outline-2 focus:-outline-offset-2 focus:outline-black sm:text-sm/6">
                        </div>
                        @if ($errors->has("published_on"))
                            <span class="text-red-500 text-sm">{{ $errors->first("published_on") }}</span>
                        @endif
                    </div>


                                        <div class="sm:col-span-3">
                                            <label class="block text-sm font-medium text-gray-900">Active</label>
                                            <div class="mt-2 relative">
                                                <select wire:model="active"
                                                    class="w-full appearance-none rounded-md bg-white py-1.5 pr-8 pl-3 text-base text-gray-900 outline-1 outline-gray-300 focus:outline-2 focus:outline-black sm:text-sm">
                                                    <option value="">--Select Active--</option>

                                                        <option value="0">No</option>
                                                        <option value="1">Yes</option>
                                                </select>
                                                <svg class="pointer-events-none absolute right-3 top-1/2 transform -translate-y-1/2 size-5 text-gray-500"
                                                    viewBox="0 0 16 16" fill="currentColor" aria-hidden="true">
                                                    <path fill-rule="evenodd"
                                                        d="M4.22 6.22a.75.75 0 0 1 1.06 0L8 8.94l2.72-2.72a.75.75 0 1 1 1.06 1.06l-3.25 3.25a.75.75 0 0 1-1.06 0L4.22 7.28a.75.75 0 0 1 0-1.06Z"
                                                        clip-rule="evenodd" />
                                                </svg>
                                            </div>
                                            @if ($errors->has("active"))
                                                <span class="text-red-500 text-sm">{{ $errors->first("active") }}</span>
                                            @endif
                                        </div>


                    <div class="col-span-full">
                        <label class="block text-sm font-medium text-gray-700">Cover (Image):</label>

                        <div
                            class="mt-2 flex flex-col items-center justify-center border-2 border-dashed border-gray-300 rounded-lg p-4 relative bg-gray-50">

                            <!-- Preview -->

                            @if ($cover)
                            <div class="w-36 h-36 relative">
                                <img src="{{ $cover->temporaryUrl() }}"
                                    class="w-full h-full object-cover rounded-md shadow-md border">


                                <button type="button" wire:click="$set('cover', null)"
                                    class="absolute -top-2 -right-2 bg-red-600 text-white p-1.5 rounded-full shadow-md hover:bg-red-700 transition">
                                    ✕
                                </button>
                            </div>
                            @elseif (!empty($coverShow))
                            <div class="w-36 h-36 relative">
                                <img src="{{ Storage::url($coverShow) }}"
                                    class="w-full h-full object-cover rounded-md shadow-md border">
                            </div>
                            @else
                                <svg class="w-16 h-16 text-gray-400 mb-3" fill="none" stroke="currentColor"
                                    stroke-width="2" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round"
                                        d="M3 16v1a2 2 0 002 2h14a2 2 0 002-2v-1m-4-4l-4-4m0 0l-4 4m4-4v12"></path>
                                </svg>
                            @endif

                            <!-- Upload Input -->
                            <label
                                class="cursor-pointer bg-blue-600 hover:bg-blue-700 text-white font-medium py-2 px-4 rounded-md transition">
                                <input type="file" wire:model="cover" accept="image/png, image/jpeg, image/jpg"
                                    class="hidden">
                                Choose Main Image
                            </label>
                        </div>

                        <!-- Loading State -->
                        <div wire:loading wire:target="cover" class="text-sm text-gray-500 mt-2">
                            Uploading...</div>

                        <!-- Error Message -->
                        @if ($errors->has("cover"))
                            <span class="text-red-500 text-sm">{{ $errors->first("cover") }}</span>
                        @endif

                    </div>


                                        <div class="col-span-full">
                                            <h3 class="text-xl font-bold text-gray-800 mb-2">team
                                            </h3>

                                            @foreach ($team as $index => $item)
                                                <div wire:key="team-{{ $item['uuid'] }}" class="grid grid-cols-6 gap-4 mb-4 items-center">

                                                    <div class="col-span-full">
    <label class="block text-sm font-medium text-gray-700">Image (Image):</label>

    <div
        class="mt-2 flex flex-col items-center justify-center border-2 border-dashed border-gray-300 rounded-lg p-4 relative bg-gray-50">

        @php
            // currentItemVariable will be '$item' (literal string)
            // subfieldName will be 'imgAlt2' (literal string)
            $value = $item['image'] ?? null;
        @endphp

        @if (isset($value) && is_object($value))
            <img src="{{ $value->temporaryUrl() }}"
                class="w-36 h-36 object-cover rounded-md shadow-md border">
        @elseif (isset($value) && is_string($value))
            <img src="{{ Storage::url($value) }}"
                class="w-36 h-36 object-cover rounded-md shadow-md border">
        @else
            <svg class="w-16 h-16 text-gray-400 mb-3" fill="none"
                stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round"
                    d="M3 16v1a2 2 0 002 2h14a2 2 0 002-2v-1m-4-4l-4-4m0 0l-4 4m4-4v12" />
            </svg>
        @endif

        <label
            class="cursor-pointer bg-blue-600 hover:bg-blue-700 text-white font-medium py-2 px-4 rounded-md transition">
            <input type="file" wire:model="team.{{ $index }}.image"
                accept=".jpg,.jpeg,.png,.svg" class="hidden">
            Choose Image
        </label>
    </div>

    <div wire:loading wire:target="team.{{ $index }}.image" class="text-sm text-gray-500 mt-2">
        Uploading...
    </div>

    @if ($errors->has("team.$index.image"))
        <span class="text-red-500 text-sm">{{ $errors->first("team.$index.image") }}</span>
    @endif
</div>


                    <div class="sm:col-span-3">
                        <label class="block text-sm/6 font-medium text-gray-900">team.{{ $index }}.title</label>
                        <div class="mt-2">
                            <input type="text" wire:model="team.{{ $index }}.title"
                                class="block w-full rounded-md bg-white px-3 py-1.5 text-base text-gray-900 outline-1 -outline-offset-1 outline-gray-300 placeholder:text-gray-400 focus:outline-2 focus:-outline-offset-2 focus:outline-black sm:text-sm/6">
                        </div>
                        @if ($errors->has("team.$index.title"))
                            <span class="text-red-500 text-sm">{{ $errors->first("team.$index.title") }}</span>
                        @endif
                    </div>


<div class="col-span-full">
    <label class="block text-sm/6 font-medium text-gray-900">Para</label>
    {{-- The saved HTML is handed to the editor through $wire, never printed raw into the page. --}}
    <div class="mt-2" wire:ignore x-data x-init="
        const editor = new window.Editor({
            el: $refs.editor,
            height: '300px',
            initialEditType: 'wysiwyg',
            previewStyle: 'vertical',
            toolbarItems: [
                ['heading', 'bold', 'italic'],
                ['hr', 'quote'],
                ['ul', 'ol', 'task'],
                ['table'],
                ['link'],
                ['code', 'codeblock']
            ]
        });

        editor.setHTML($wire.$get('team.{{ $index }}.para') ?? '');

        editor.on('change', () => {
            $wire.$set('team.{{ $index }}.para', editor.getHTML(), false);
        });
    ">
        <div x-ref="editor"></div>
    </div>
    @if ($errors->has("team.$index.para"))
        <span class="text-red-500 text-sm">{{ $errors->first("team.$index.para") }}</span>
    @endif
</div>




                                                    <!-- Remove Button -->
                                                    <div class="col-span-full flex justify-end items-center">
                                                        <button type="button"
                                                            wire:click="removeteam('{{ $item['uuid'] }}')"
                                                            class="mt-2 rounded-md bg-red-700 px-3 py-2 text-sm text-white hover:bg-red-800">
                                                            <span wire:loading.remove wire:target="removeteam('{{ $item['uuid'] }}')" >Delete</span>
                                                                <svg wire:loading wire:target="removeteam('{{ $item['uuid'] }}')"
                                                                    class="w-4 h-4 animate-spin text-white inline-block" fill="none"
                                                                    viewBox="0 0 24 24">
                                                                    <circle class="opacity-25" cx="12" cy="12" r="10"
                                                                        stroke="currentColor" stroke-width="4"></circle>
                                                                    <path class="opacity-75" fill="currentColor"
                                                                        d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z"></path>
                                                                </svg>
                                                            </button>
                                                    </div>
                                                </div>
                                            @endforeach

                                            <!-- Add Feature Button -->
                                            <div class="col-span-full flex justify-end items-center">
                                                <button type="button" wire:click="addteam"
                                                    class="mt-2 rounded-md bg-gray-800 px-3 py-2 text-sm text-white hover:bg-black">
                                                    <span wire:loading.remove wire:target="addteam" >+ Add team</span>
                                                    <svg wire:loading wire:target="addteam"
                                                        class="w-4 h-4 animate-spin text-white inline-block" fill="none"
                                                        viewBox="0 0 24 24">
                                                        <circle class="opacity-25" cx="12" cy="12" r="10"
                                                            stroke="currentColor" stroke-width="4"></circle>
                                                        <path class="opacity-75" fill="currentColor"
                                                            d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z"></path>
                                                    </svg>
                                                </button>
                                            </div>


                                            @if ($errors->has("team"))
                                                <span class="text-red-500 text-sm">{{ $errors->first("team") }}</span>
                                            @endif
                                        </div>


                                        <div class="col-span-full">
                                            <h3 class="text-xl font-bold text-gray-800 mb-2">logos
                                            </h3>

                                            @foreach ($logos as $index => $item)
                                                <div wire:key="logos-{{ $item['uuid'] }}" class="grid grid-cols-6 gap-4 mb-4 items-center">

                                                    <div class="col-span-full">
    <label class="block text-sm font-medium text-gray-700">Logo (Image):</label>

    <div
        class="mt-2 flex flex-col items-center justify-center border-2 border-dashed border-gray-300 rounded-lg p-4 relative bg-gray-50">

        @php
            // currentItemVariable will be '$item' (literal string)
            // subfieldName will be 'imgAlt2' (literal string)
            $value = $item['logo'] ?? null;
        @endphp

        @if (isset($value) && is_object($value))
            <img src="{{ $value->temporaryUrl() }}"
                class="w-36 h-36 object-cover rounded-md shadow-md border">
        @elseif (isset($value) && is_string($value))
            <img src="{{ Storage::url($value) }}"
                class="w-36 h-36 object-cover rounded-md shadow-md border">
        @else
            <svg class="w-16 h-16 text-gray-400 mb-3" fill="none"
                stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round"
                    d="M3 16v1a2 2 0 002 2h14a2 2 0 002-2v-1m-4-4l-4-4m0 0l-4 4m4-4v12" />
            </svg>
        @endif

        <label
            class="cursor-pointer bg-blue-600 hover:bg-blue-700 text-white font-medium py-2 px-4 rounded-md transition">
            <input type="file" wire:model="logos.{{ $index }}.logo"
                accept=".jpg,.jpeg,.png,.svg" class="hidden">
            Choose Image
        </label>
    </div>

    <div wire:loading wire:target="logos.{{ $index }}.logo" class="text-sm text-gray-500 mt-2">
        Uploading...
    </div>

    @if ($errors->has("logos.$index.logo"))
        <span class="text-red-500 text-sm">{{ $errors->first("logos.$index.logo") }}</span>
    @endif
</div>




                                                    <!-- Remove Button -->
                                                    <div class="col-span-full flex justify-end items-center">
                                                        <button type="button"
                                                            wire:click="removelogos('{{ $item['uuid'] }}')"
                                                            class="mt-2 rounded-md bg-red-700 px-3 py-2 text-sm text-white hover:bg-red-800">
                                                            <span wire:loading.remove wire:target="removelogos('{{ $item['uuid'] }}')" >Delete</span>
                                                                <svg wire:loading wire:target="removelogos('{{ $item['uuid'] }}')"
                                                                    class="w-4 h-4 animate-spin text-white inline-block" fill="none"
                                                                    viewBox="0 0 24 24">
                                                                    <circle class="opacity-25" cx="12" cy="12" r="10"
                                                                        stroke="currentColor" stroke-width="4"></circle>
                                                                    <path class="opacity-75" fill="currentColor"
                                                                        d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z"></path>
                                                                </svg>
                                                            </button>
                                                    </div>
                                                </div>
                                            @endforeach

                                            <!-- Add Feature Button -->
                                            <div class="col-span-full flex justify-end items-center">
                                                <button type="button" wire:click="addlogos"
                                                    class="mt-2 rounded-md bg-gray-800 px-3 py-2 text-sm text-white hover:bg-black">
                                                    <span wire:loading.remove wire:target="addlogos" >+ Add logos</span>
                                                    <svg wire:loading wire:target="addlogos"
                                                        class="w-4 h-4 animate-spin text-white inline-block" fill="none"
                                                        viewBox="0 0 24 24">
                                                        <circle class="opacity-25" cx="12" cy="12" r="10"
                                                            stroke="currentColor" stroke-width="4"></circle>
                                                        <path class="opacity-75" fill="currentColor"
                                                            d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z"></path>
                                                    </svg>
                                                </button>
                                            </div>


                                            @if ($errors->has("logos"))
                                                <span class="text-red-500 text-sm">{{ $errors->first("logos") }}</span>
                                            @endif
                                        </div>


                <div class="col-span-full flex justify-end items-center">
                    <button type="submit"
                        class="rounded-md bg-green-600 px-3 py-2 text-sm font-semibold text-white shadow-xs hover:bg-green-800 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-green ml-[1em]">Submit
                        <svg wire:loading wire:target="save" class="w-4 h-4 animate-spin text-blue-500 inline-block"
                            fill="none" viewBox="0 0 24 24">
                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor"
                                stroke-width="4"></circle>
                            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z">
                            </path>
                        </svg>
                    </button>
                </div>

            </div>

        </form>
    @endif


    <div class="overflow-x-auto bg-white shadow-md rounded-lg mt-[2em]">
        <table class="w-full border-collapse border border-gray-200">
            <thead class="bg-gray-100">
                <tr class="text-left text-gray-700 text-sm md:text-base">
                    <th class="px-2 py-2 md:px-4 md:py-3 border border-gray-200">#</th>
                    <th class="px-2 py-2 md:px-4 md:py-3 border capitalize">{{ ucfirst(str_replace('_', ' ', 'title')) }}</th>

<th class="px-2 py-2 md:px-4 md:py-3 border capitalize">{{ ucfirst(str_replace('_', ' ', 'cover')) }}</th>

<th class="px-2 py-2 md:px-4 md:py-3 border capitalize">{{ ucfirst(str_replace('_', ' ', 'region_id')) }}</th>

                    <th class="px-2 py-2 md:px-4 md:py-3 border border-gray-200 text-center">Actions</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($this->records as $index => $pt)
                    <tr wire:key="row-{{ $pt->getKey() }}" class="border border-gray-200 hover:bg-gray-50 transition text-sm md:text-base">
                        <!-- Correct Index Based on Pagination -->
                        <td class="px-2 py-2 md:px-4 md:py-3">
                            {{ ($this->records->currentPage() - 1) * $this->records->perPage() + $loop->index + 1 }}
                        </td>

                        <td class="px-2 py-2 md:px-4 md:py-3 border">
    {{  $pt->title  }}
</td>

<td class="px-2 py-2 md:px-4 md:py-3 border">
    @if($pt->cover)
        <img src="{{ Storage::url($pt->cover) }}" class="w-16 h-16 object-cover rounded-md">
    @endif
</td>

<td class="px-2 py-2 md:px-4 md:py-3 border">
    {{ $pt->region?->name }}
</td>


                        <!-- Action Buttons -->
                        <td class="px-2 py-2 md:px-4 md:py-3 flex justify-center space-x-2">
                            <button wire:click="edit({{ $pt->getKey() }})"
                                class="text-blue-500 hover:text-blue-700 cursor-pointer">
                                <span wire:loading.remove wire:target="edit({{ $pt->getKey() }})">✏️</span>

                                <!-- Spinner (only visible while loading this button) -->
                                <svg wire:loading wire:target="edit({{ $pt->getKey() }})"
                                    class="w-4 h-4 animate-spin text-blue-500 inline-block" fill="none"
                                    viewBox="0 0 24 24">
                                    <circle class="opacity-25" cx="12" cy="12" r="10"
                                        stroke="currentColor" stroke-width="4"></circle>
                                    <path class="opacity-75" fill="currentColor"
                                        d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z"></path>
                                </svg>
                            </button>
                            <button wire:click="delete({{ $pt->getKey() }})"
                                wire:confirm="Are you sure you want to delete this?"
                                class="text-red-500 hover:text-red-700 cursor-pointer">
                                🗑️
                            </button>
                        </td>
                    </tr>

                    @if ($editingId === $pt->getKey())
                        <tr wire:key="edit-{{ $pt->getKey() }}">
                            <td colspan="12" style="padding: 2em;">

                                <form wire:submit.prevent="save">

                                    <div class="mt-10 grid grid-cols-1 gap-x-6 gap-y-8 sm:grid-cols-6">

                                                                                <div class="sm:col-span-3">
                                            <label class="block text-sm font-medium text-gray-900">Foreign Key: Region Id</label>
                                            <div class="mt-2 relative">
                                                <select wire:model="region_id"
                                                    class="w-full appearance-none rounded-md bg-white py-1.5 pr-8 pl-3 text-base text-gray-900 outline-1 outline-gray-300 focus:outline-2 focus:outline-black sm:text-sm">
                                                    <option value="">--Select Region Id--</option>
                                                    @foreach (\App\Models\Region::all() as $item)
                                                        <option value="{{ $item->id }}">{{ $item->id }}-{{ $item->name }}
                                                        </option>
                                                    @endforeach
                                                </select>
                                                <svg class="pointer-events-none absolute right-3 top-1/2 transform -translate-y-1/2 size-5 text-gray-500"
                                                    viewBox="0 0 16 16" fill="currentColor" aria-hidden="true">
                                                    <path fill-rule="evenodd"
                                                        d="M4.22 6.22a.75.75 0 0 1 1.06 0L8 8.94l2.72-2.72a.75.75 0 1 1 1.06 1.06l-3.25 3.25a.75.75 0 0 1-1.06 0L4.22 7.28a.75.75 0 0 1 0-1.06Z"
                                                        clip-rule="evenodd" />
                                                </svg>
                                            </div>
                                            @if ($errors->has("region_id"))
                                                <span class="text-red-500 text-sm">{{ $errors->first("region_id") }}</span>
                                            @endif
                                        </div>


                    <div class="sm:col-span-3">
                        <label class="block text-sm/6 font-medium text-gray-900">title</label>
                        <div class="mt-2">
                            <input type="text" wire:model="title"
                                class="block w-full rounded-md bg-white px-3 py-1.5 text-base text-gray-900 outline-1 -outline-offset-1 outline-gray-300 placeholder:text-gray-400 focus:outline-2 focus:-outline-offset-2 focus:outline-black sm:text-sm/6">
                        </div>
                        @if ($errors->has("title"))
                            <span class="text-red-500 text-sm">{{ $errors->first("title") }}</span>
                        @endif
                    </div>


                    <div class="col-span-full">
                        <label class="block text-sm/6 font-medium text-gray-900">para</label>
                        <div class="mt-2">
                            <textarea type="text" wire:model="para"
                                class="block w-full rounded-md bg-white px-3 py-1.5 text-base text-gray-900 outline-1 -outline-offset-1 outline-gray-300 placeholder:text-gray-400 focus:outline-2 focus:-outline-offset-2 focus:outline-black sm:text-sm/6"></textarea>
                        </div>
                        @if ($errors->has("para"))
                            <span class="text-red-500 text-sm">{{ $errors->first("para") }}</span>
                        @endif
                    </div>


<div class="col-span-full">
    <label class="block text-sm/6 font-medium text-gray-900">Body</label>
    {{-- The saved HTML is handed to the editor through $wire, never printed raw into the page. --}}
    <div class="mt-2" wire:ignore x-data x-init="
        const editor = new window.Editor({
            el: $refs.editor,
            height: '300px',
            initialEditType: 'wysiwyg',
            previewStyle: 'vertical',
            toolbarItems: [
                ['heading', 'bold', 'italic'],
                ['hr', 'quote'],
                ['ul', 'ol', 'task'],
                ['table'],
                ['link'],
                ['code', 'codeblock']
            ]
        });

        editor.setHTML($wire.$get('body') ?? '');

        editor.on('change', () => {
            $wire.$set('body', editor.getHTML(), false);
        });
    ">
        <div x-ref="editor"></div>
    </div>
    @if ($errors->has("body"))
        <span class="text-red-500 text-sm">{{ $errors->first("body") }}</span>
    @endif
</div>


                    <div class="sm:col-span-3">
                        <label class="block text-sm/6 font-medium text-gray-900">Published On</label>
                        <div class="mt-2">
                            <input type="date" wire:model="published_on"
                                class="block w-full rounded-md bg-white px-3 py-1.5 text-base text-gray-900 outline-1 -outline-offset-1 outline-gray-300 placeholder:text-gray-400 focus:outline-2 focus:-outline-offset-2 focus:outline-black sm:text-sm/6">
                        </div>
                        @if ($errors->has("published_on"))
                            <span class="text-red-500 text-sm">{{ $errors->first("published_on") }}</span>
                        @endif
                    </div>


                                        <div class="sm:col-span-3">
                                            <label class="block text-sm font-medium text-gray-900">Active</label>
                                            <div class="mt-2 relative">
                                                <select wire:model="active"
                                                    class="w-full appearance-none rounded-md bg-white py-1.5 pr-8 pl-3 text-base text-gray-900 outline-1 outline-gray-300 focus:outline-2 focus:outline-black sm:text-sm">
                                                    <option value="">--Select Active--</option>

                                                        <option value="0">No</option>
                                                        <option value="1">Yes</option>
                                                </select>
                                                <svg class="pointer-events-none absolute right-3 top-1/2 transform -translate-y-1/2 size-5 text-gray-500"
                                                    viewBox="0 0 16 16" fill="currentColor" aria-hidden="true">
                                                    <path fill-rule="evenodd"
                                                        d="M4.22 6.22a.75.75 0 0 1 1.06 0L8 8.94l2.72-2.72a.75.75 0 1 1 1.06 1.06l-3.25 3.25a.75.75 0 0 1-1.06 0L4.22 7.28a.75.75 0 0 1 0-1.06Z"
                                                        clip-rule="evenodd" />
                                                </svg>
                                            </div>
                                            @if ($errors->has("active"))
                                                <span class="text-red-500 text-sm">{{ $errors->first("active") }}</span>
                                            @endif
                                        </div>


                    <div class="col-span-full">
                        <label class="block text-sm font-medium text-gray-700">Cover (Image):</label>

                        <div
                            class="mt-2 flex flex-col items-center justify-center border-2 border-dashed border-gray-300 rounded-lg p-4 relative bg-gray-50">

                            <!-- Preview -->

                            @if ($cover)
                            <div class="w-36 h-36 relative">
                                <img src="{{ $cover->temporaryUrl() }}"
                                    class="w-full h-full object-cover rounded-md shadow-md border">


                                <button type="button" wire:click="$set('cover', null)"
                                    class="absolute -top-2 -right-2 bg-red-600 text-white p-1.5 rounded-full shadow-md hover:bg-red-700 transition">
                                    ✕
                                </button>
                            </div>
                            @elseif (!empty($coverShow))
                            <div class="w-36 h-36 relative">
                                <img src="{{ Storage::url($coverShow) }}"
                                    class="w-full h-full object-cover rounded-md shadow-md border">
                            </div>
                            @else
                                <svg class="w-16 h-16 text-gray-400 mb-3" fill="none" stroke="currentColor"
                                    stroke-width="2" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round"
                                        d="M3 16v1a2 2 0 002 2h14a2 2 0 002-2v-1m-4-4l-4-4m0 0l-4 4m4-4v12"></path>
                                </svg>
                            @endif

                            <!-- Upload Input -->
                            <label
                                class="cursor-pointer bg-blue-600 hover:bg-blue-700 text-white font-medium py-2 px-4 rounded-md transition">
                                <input type="file" wire:model="cover" accept="image/png, image/jpeg, image/jpg"
                                    class="hidden">
                                Choose Main Image
                            </label>
                        </div>

                        <!-- Loading State -->
                        <div wire:loading wire:target="cover" class="text-sm text-gray-500 mt-2">
                            Uploading...</div>

                        <!-- Error Message -->
                        @if ($errors->has("cover"))
                            <span class="text-red-500 text-sm">{{ $errors->first("cover") }}</span>
                        @endif

                    </div>


                                        <div class="col-span-full">
                                            <h3 class="text-xl font-bold text-gray-800 mb-2">team
                                            </h3>

                                            @foreach ($team as $index => $item)
                                                <div wire:key="team-{{ $item['uuid'] }}" class="grid grid-cols-6 gap-4 mb-4 items-center">

                                                    <div class="col-span-full">
    <label class="block text-sm font-medium text-gray-700">Image (Image):</label>

    <div
        class="mt-2 flex flex-col items-center justify-center border-2 border-dashed border-gray-300 rounded-lg p-4 relative bg-gray-50">

        @php
            // currentItemVariable will be '$item' (literal string)
            // subfieldName will be 'imgAlt2' (literal string)
            $value = $item['image'] ?? null;
        @endphp

        @if (isset($value) && is_object($value))
            <img src="{{ $value->temporaryUrl() }}"
                class="w-36 h-36 object-cover rounded-md shadow-md border">
        @elseif (isset($value) && is_string($value))
            <img src="{{ Storage::url($value) }}"
                class="w-36 h-36 object-cover rounded-md shadow-md border">
        @else
            <svg class="w-16 h-16 text-gray-400 mb-3" fill="none"
                stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round"
                    d="M3 16v1a2 2 0 002 2h14a2 2 0 002-2v-1m-4-4l-4-4m0 0l-4 4m4-4v12" />
            </svg>
        @endif

        <label
            class="cursor-pointer bg-blue-600 hover:bg-blue-700 text-white font-medium py-2 px-4 rounded-md transition">
            <input type="file" wire:model="team.{{ $index }}.image"
                accept=".jpg,.jpeg,.png,.svg" class="hidden">
            Choose Image
        </label>
    </div>

    <div wire:loading wire:target="team.{{ $index }}.image" class="text-sm text-gray-500 mt-2">
        Uploading...
    </div>

    @if ($errors->has("team.$index.image"))
        <span class="text-red-500 text-sm">{{ $errors->first("team.$index.image") }}</span>
    @endif
</div>


                    <div class="sm:col-span-3">
                        <label class="block text-sm/6 font-medium text-gray-900">team.{{ $index }}.title</label>
                        <div class="mt-2">
                            <input type="text" wire:model="team.{{ $index }}.title"
                                class="block w-full rounded-md bg-white px-3 py-1.5 text-base text-gray-900 outline-1 -outline-offset-1 outline-gray-300 placeholder:text-gray-400 focus:outline-2 focus:-outline-offset-2 focus:outline-black sm:text-sm/6">
                        </div>
                        @if ($errors->has("team.$index.title"))
                            <span class="text-red-500 text-sm">{{ $errors->first("team.$index.title") }}</span>
                        @endif
                    </div>


<div class="col-span-full">
    <label class="block text-sm/6 font-medium text-gray-900">Para</label>
    {{-- The saved HTML is handed to the editor through $wire, never printed raw into the page. --}}
    <div class="mt-2" wire:ignore x-data x-init="
        const editor = new window.Editor({
            el: $refs.editor,
            height: '300px',
            initialEditType: 'wysiwyg',
            previewStyle: 'vertical',
            toolbarItems: [
                ['heading', 'bold', 'italic'],
                ['hr', 'quote'],
                ['ul', 'ol', 'task'],
                ['table'],
                ['link'],
                ['code', 'codeblock']
            ]
        });

        editor.setHTML($wire.$get('team.{{ $index }}.para') ?? '');

        editor.on('change', () => {
            $wire.$set('team.{{ $index }}.para', editor.getHTML(), false);
        });
    ">
        <div x-ref="editor"></div>
    </div>
    @if ($errors->has("team.$index.para"))
        <span class="text-red-500 text-sm">{{ $errors->first("team.$index.para") }}</span>
    @endif
</div>




                                                    <!-- Remove Button -->
                                                    <div class="col-span-full flex justify-end items-center">
                                                        <button type="button"
                                                            wire:click="removeteam('{{ $item['uuid'] }}')"
                                                            class="mt-2 rounded-md bg-red-700 px-3 py-2 text-sm text-white hover:bg-red-800">
                                                            <span wire:loading.remove wire:target="removeteam('{{ $item['uuid'] }}')" >Delete</span>
                                                                <svg wire:loading wire:target="removeteam('{{ $item['uuid'] }}')"
                                                                    class="w-4 h-4 animate-spin text-white inline-block" fill="none"
                                                                    viewBox="0 0 24 24">
                                                                    <circle class="opacity-25" cx="12" cy="12" r="10"
                                                                        stroke="currentColor" stroke-width="4"></circle>
                                                                    <path class="opacity-75" fill="currentColor"
                                                                        d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z"></path>
                                                                </svg>
                                                            </button>
                                                    </div>
                                                </div>
                                            @endforeach

                                            <!-- Add Feature Button -->
                                            <div class="col-span-full flex justify-end items-center">
                                                <button type="button" wire:click="addteam"
                                                    class="mt-2 rounded-md bg-gray-800 px-3 py-2 text-sm text-white hover:bg-black">
                                                    <span wire:loading.remove wire:target="addteam" >+ Add team</span>
                                                    <svg wire:loading wire:target="addteam"
                                                        class="w-4 h-4 animate-spin text-white inline-block" fill="none"
                                                        viewBox="0 0 24 24">
                                                        <circle class="opacity-25" cx="12" cy="12" r="10"
                                                            stroke="currentColor" stroke-width="4"></circle>
                                                        <path class="opacity-75" fill="currentColor"
                                                            d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z"></path>
                                                    </svg>
                                                </button>
                                            </div>


                                            @if ($errors->has("team"))
                                                <span class="text-red-500 text-sm">{{ $errors->first("team") }}</span>
                                            @endif
                                        </div>


                                        <div class="col-span-full">
                                            <h3 class="text-xl font-bold text-gray-800 mb-2">logos
                                            </h3>

                                            @foreach ($logos as $index => $item)
                                                <div wire:key="logos-{{ $item['uuid'] }}" class="grid grid-cols-6 gap-4 mb-4 items-center">

                                                    <div class="col-span-full">
    <label class="block text-sm font-medium text-gray-700">Logo (Image):</label>

    <div
        class="mt-2 flex flex-col items-center justify-center border-2 border-dashed border-gray-300 rounded-lg p-4 relative bg-gray-50">

        @php
            // currentItemVariable will be '$item' (literal string)
            // subfieldName will be 'imgAlt2' (literal string)
            $value = $item['logo'] ?? null;
        @endphp

        @if (isset($value) && is_object($value))
            <img src="{{ $value->temporaryUrl() }}"
                class="w-36 h-36 object-cover rounded-md shadow-md border">
        @elseif (isset($value) && is_string($value))
            <img src="{{ Storage::url($value) }}"
                class="w-36 h-36 object-cover rounded-md shadow-md border">
        @else
            <svg class="w-16 h-16 text-gray-400 mb-3" fill="none"
                stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round"
                    d="M3 16v1a2 2 0 002 2h14a2 2 0 002-2v-1m-4-4l-4-4m0 0l-4 4m4-4v12" />
            </svg>
        @endif

        <label
            class="cursor-pointer bg-blue-600 hover:bg-blue-700 text-white font-medium py-2 px-4 rounded-md transition">
            <input type="file" wire:model="logos.{{ $index }}.logo"
                accept=".jpg,.jpeg,.png,.svg" class="hidden">
            Choose Image
        </label>
    </div>

    <div wire:loading wire:target="logos.{{ $index }}.logo" class="text-sm text-gray-500 mt-2">
        Uploading...
    </div>

    @if ($errors->has("logos.$index.logo"))
        <span class="text-red-500 text-sm">{{ $errors->first("logos.$index.logo") }}</span>
    @endif
</div>




                                                    <!-- Remove Button -->
                                                    <div class="col-span-full flex justify-end items-center">
                                                        <button type="button"
                                                            wire:click="removelogos('{{ $item['uuid'] }}')"
                                                            class="mt-2 rounded-md bg-red-700 px-3 py-2 text-sm text-white hover:bg-red-800">
                                                            <span wire:loading.remove wire:target="removelogos('{{ $item['uuid'] }}')" >Delete</span>
                                                                <svg wire:loading wire:target="removelogos('{{ $item['uuid'] }}')"
                                                                    class="w-4 h-4 animate-spin text-white inline-block" fill="none"
                                                                    viewBox="0 0 24 24">
                                                                    <circle class="opacity-25" cx="12" cy="12" r="10"
                                                                        stroke="currentColor" stroke-width="4"></circle>
                                                                    <path class="opacity-75" fill="currentColor"
                                                                        d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z"></path>
                                                                </svg>
                                                            </button>
                                                    </div>
                                                </div>
                                            @endforeach

                                            <!-- Add Feature Button -->
                                            <div class="col-span-full flex justify-end items-center">
                                                <button type="button" wire:click="addlogos"
                                                    class="mt-2 rounded-md bg-gray-800 px-3 py-2 text-sm text-white hover:bg-black">
                                                    <span wire:loading.remove wire:target="addlogos" >+ Add logos</span>
                                                    <svg wire:loading wire:target="addlogos"
                                                        class="w-4 h-4 animate-spin text-white inline-block" fill="none"
                                                        viewBox="0 0 24 24">
                                                        <circle class="opacity-25" cx="12" cy="12" r="10"
                                                            stroke="currentColor" stroke-width="4"></circle>
                                                        <path class="opacity-75" fill="currentColor"
                                                            d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z"></path>
                                                    </svg>
                                                </button>
                                            </div>


                                            @if ($errors->has("logos"))
                                                <span class="text-red-500 text-sm">{{ $errors->first("logos") }}</span>
                                            @endif
                                        </div>


                                        <div class="col-span-full flex justify-end items-center">
                                            <button type="button" wire:click.prevent="cancel"
                                                class="rounded-md bg-black px-3 py-2 text-sm font-semibold text-white shadow-xs hover:bg-gray-800 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-black">Cancel</button>
                                            <button type="submit"
                                                class="rounded-md bg-green-600 px-3 py-2 text-sm font-semibold text-white shadow-xs hover:bg-green-800 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-green ml-[1em]">Submit</button>
                                        </div>



                                    </div>

                                </form>

                            </td>
                        </tr>
                    @endif
                @endforeach
            </tbody>
        </table>

        <div>
            {{ $this->records->links() }}
        </div>

    </div>


</div>
