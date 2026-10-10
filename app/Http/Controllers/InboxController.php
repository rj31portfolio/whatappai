<?php
namespace App\Http\Controllers;

use App\Models\{Contact, Conversation, MessageTemplate};
use App\Services\Messaging;
use App\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class InboxController extends Controller
{
    public function index(Request $request)
    {
        Gate::authorize('viewAny', Contact::class);
        $selected = $request->filled('conversation') ? Conversation::with(['contact', 'messages' => fn ($q) => $q->latest()->limit(100)])->findOrFail($request->integer('conversation')) : null;
        return view('modules.inbox', ['conversations' => Conversation::with('contact')->latest('updated_at')->paginate(25), 'selected' => $selected, 'contacts' => Contact::orderBy('name')->get(), 'members' => app(TenantContext::class)->tenant->users, 'templates' => MessageTemplate::where('channel', 'whatsapp')->where('approval_status', 'APPROVED')->get()]);
    }

    public function store(Request $request)
    {
        Gate::authorize('manage-inbox');
        $data = $request->validate(['contact_id' => ['required', Rule::exists('contacts', 'id')->where('tenant_id', app(TenantContext::class)->id())], 'channel' => 'required|in:email,whatsapp', 'subject' => 'nullable|string|max:200']);
        $conversation = Conversation::firstOrCreate(['contact_id' => $data['contact_id'], 'channel' => $data['channel']], ['subject' => $data['subject'] ?? null]);
        return redirect()->route('inbox', ['conversation' => $conversation->id]);
    }

    public function update(Request $request, Conversation $conversation)
    {
        Gate::authorize('manage-inbox');
        $data = $request->validate(['status' => 'required|in:open,human_handover,resolved', 'assigned_to' => ['nullable', Rule::exists('memberships', 'user_id')->where('tenant_id', app(TenantContext::class)->id())], 'summary' => 'nullable|string|max:5000']);
        $conversation->update($data);
        \App\Services\Audit::record('conversation.updated', $conversation);
        return back()->with('status', 'Conversation updated.');
    }

    public function reply(Request $request, Conversation $conversation)
    {
        Gate::authorize('manage-inbox');
        $data = $request->validate(['body' => 'required|string|max:4000', 'template_id' => ['nullable', 'integer', Rule::exists('message_templates', 'id')->where('tenant_id', app(TenantContext::class)->id())], 'request_key' => 'required|uuid']);
        app(Messaging::class)->queue($conversation, $data['body'], ['subject' => $conversation->subject ?? 'Following up', 'template_id' => $data['template_id'] ?? null], $data['request_key']);
        return back()->with('status', 'Reply queued. Delivery status updates when the worker and provider respond.');
    }
}
