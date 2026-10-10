<?php

namespace App\Livewire;

use App\Models\Contact;
use Illuminate\Support\Facades\Gate;
use Livewire\Component;
use Livewire\WithPagination;

class ContactTable extends Component
{
    use WithPagination;

    public string $search = '';

    public string $score = '';

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedScore(): void
    {
        $this->resetPage();
    }

    public function render()
    {
        Gate::authorize('viewAny', Contact::class);
        $this->validate(['search' => 'string|max:160', 'score' => 'nullable|in:cold,warm,hot']);
        $query = Contact::with('company')->latest();
        if ($this->search !== '') {
            $term = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $this->search).'%';
            $query->where(fn ($q) => $q->whereRaw("name LIKE ? ESCAPE '!'", [$term])->orWhereRaw("email LIKE ? ESCAPE '!'", [$term])->orWhereRaw("phone LIKE ? ESCAPE '!'", [$term]));
        }
        if ($this->score !== '') {
            $query->where('score', $this->score);
        }

        return view('livewire.contact-table', ['contacts' => $query->paginate(15)]);
    }
}
