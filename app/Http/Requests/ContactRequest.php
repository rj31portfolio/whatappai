<?php

namespace App\Http\Requests;

use App\Models\Contact;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class ContactRequest extends FormRequest
{
    public function authorize(): bool
    {
        $contact = $this->route('contact');

        return $contact ? Gate::allows('update', $contact) : Gate::allows('create', Contact::class);
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['email' => $this->filled('email') ? strtolower(trim($this->email)) : null]);
    }

    public function rules(): array
    {
        $tenant = app(TenantContext::class)->id();
        $id = $this->route('contact')?->id;

        return [
            'tenant_id' => ['prohibited'], 'name' => ['required', 'string', 'max:160'],
            'email' => ['nullable', 'email', 'max:255', Rule::unique('contacts')->where('tenant_id', $tenant)->ignore($id)],
            'phone' => ['nullable', 'regex:/^\+[1-9][0-9]{7,14}$/', Rule::unique('contacts')->where('tenant_id', $tenant)->ignore($id)],
            'company_id' => ['nullable', 'integer', Rule::exists('companies', 'id')->where('tenant_id', $tenant)],
            'source' => ['required', Rule::in(Contact::SOURCES)], 'notes' => ['nullable', 'string', 'max:5000'],
            'email_consent' => ['sometimes', 'boolean'], 'whatsapp_consent' => ['sometimes', 'boolean'],
        ];
    }
}
