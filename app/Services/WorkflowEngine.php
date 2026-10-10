<?php

namespace App\Services;

use App\Models\Contact;
use App\Models\Workflow;
use App\Models\WorkflowRun;

class WorkflowEngine
{
    public function trigger(string $trigger, Contact $contact, string $eventKey): void
    {
        foreach (Workflow::where('active', true)->where('trigger', $trigger)->get() as $workflow) {
            if ($workflow->score_condition && $workflow->score_condition !== $contact->score) {
                continue;
            }
            WorkflowRun::firstOrCreate(['workflow_id' => $workflow->id, 'event_key' => $eventKey], ['contact_id' => $contact->id, 'steps' => $workflow->steps, 'next_at' => now()]);
        }
    }
}
