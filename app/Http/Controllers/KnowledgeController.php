<?php
namespace App\Http\Controllers;

use App\Models\{Contact, Integration, KnowledgeEntry, AiUsage};
use App\Services\AI\AiGateway;
use App\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{Gate, Storage};
use Illuminate\Validation\Rule;

class KnowledgeController extends Controller
{
    public function index()
    {
        Gate::authorize('viewAny', Contact::class);
        return view('modules.knowledge', ['entries' => KnowledgeEntry::latest()->paginate(20)]);
    }

    public function store(Request $request)
    {
        Gate::authorize('manage-settings');
        $data = $request->validate(['title' => 'required|string|max:160', 'kind' => ['required', Rule::in(['faq', 'product', 'service', 'policy', 'profile', 'website', 'document'])], 'content' => 'nullable|string|max:50000', 'approved' => 'sometimes|boolean', 'document' => 'nullable|file|mimes:txt,md|max:512']);
        if ($request->hasFile('document')) {
            $text = file_get_contents($request->file('document')->getRealPath());
            if (! mb_check_encoding($text, 'UTF-8') || str_contains($text, "\0")) { return back()->withErrors(['document' => 'Upload a UTF-8 text or Markdown document.']); }
            $data['content'] = mb_substr($text, 0, 50000);
        }
        if (empty($data['content'])) { return back()->withErrors(['content' => 'Add approved business information or a text document.']); }
        unset($data['document']);
        if ($request->hasFile('document')) { $data['file_path'] = $request->file('document')->store('knowledge/'.app(TenantContext::class)->id(), 'local'); }
        $entry = KnowledgeEntry::create($data);
        \App\Services\Audit::record('knowledge.created', $entry);
        return back()->with('status', 'Business knowledge saved. Only approved entries are retrieved.');
    }

    public function update(Request $request, KnowledgeEntry $entry)
    {
        Gate::authorize('manage-settings');
        $entry->update($request->validate(['content' => 'required|string|max:50000', 'approved' => 'required|boolean']));
        \App\Services\Audit::record('knowledge.updated', $entry);
        return back()->with('status', 'Knowledge updated.');
    }

    public function destroy(KnowledgeEntry $entry)
    {
        Gate::authorize('manage-settings');
        if ($entry->file_path) { Storage::disk('local')->delete($entry->file_path); }
        $entry->delete();
        return back()->with('status', 'Knowledge removed.');
    }

    public function download(KnowledgeEntry $entry)
    {
        Gate::authorize('manage-settings');
        abort_unless($entry->file_path, 404);
        return Storage::disk('local')->download($entry->file_path, 'knowledge.txt');
    }

    public function ai()
    {
        Gate::authorize('viewAny', Contact::class);
        return view('modules.ai', ['settings' => app(TenantContext::class)->tenant->settings ?? [], 'usage' => AiUsage::latest()->paginate(20), 'integrations' => Integration::whereIn('provider', ['openai', 'deepseek', 'claude'])->get()]);
    }

    public function suggest(Request $request)
    {
        Gate::authorize('manage-inbox');
        $data = $request->validate(['question' => 'required|string|max:3000']);
        $answer = app(AiGateway::class)->suggest($data['question']);
        return back()->with('ai_answer', $answer)->withInput();
    }
}
