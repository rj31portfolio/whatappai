<?php

namespace Database\Seeders;

use App\Enums\Role;
use App\Models\Campaign;
use App\Models\Company;
use App\Models\Contact;
use App\Models\Deal;
use App\Models\KnowledgeEntry;
use App\Models\Plan;
use App\Models\Task;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Workflow;
use App\Services\ContactScoring;
use App\Tenancy\TenantContext;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class DemoSeeder extends Seeder
{
    public const PASSWORD = 'DemoPass!2026';

    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new RuntimeException('Demo data is allowed only in local and testing environments.');
        }
        DB::transaction(function () {
            $tenants = [];
            foreach ([['Acme Growth Studio', 'acme-demo', 'Professional services'], ['BrightPath Academy', 'brightpath-demo', 'Education']] as [$name, $slug, $industry]) {
                $tenant = Tenant::firstOrCreate(['slug' => $slug], ['name' => $name, 'industry' => $industry, 'is_demo' => true]);
                if ($tenant->wasRecentlyCreated) {
                    $tenant->trial_ends_at = now()->addDays(14);
                    $tenant->save();
                }
                if (! $tenant->is_demo) {
                    throw new RuntimeException('Refusing to seed into a non-demo business.');
                }
                $tenants[] = $tenant;
            }
            foreach ([
                ['owner', 'Aarav Mehta', Role::BusinessOwner], ['manager', 'Maya Singh', Role::BusinessManager],
                ['sales', 'Rohan Shah', Role::SalesAgent], ['marketing', 'Priya Nair', Role::MarketingManager],
                ['analyst', 'Neha Rao', Role::Analyst], ['agency', 'Alex Morgan', Role::AgencyOwner],
            ] as [$handle, $name, $role]) {
                $user = $this->demoUser($handle.'@demo.test', $name);
                $tenants[0]->users()->syncWithoutDetaching([$user->id => ['role' => $role->value]]);
                if (in_array($handle, ['owner', 'agency'], true)) {
                    $tenants[1]->users()->syncWithoutDetaching([$user->id => ['role' => $role->value]]);
                }
            }
            $otherOwner = $this->demoUser('education@demo.test', 'Samira Khan');
            $tenants[1]->users()->syncWithoutDetaching([$otherOwner->id => ['role' => Role::BusinessOwner->value]]);
            $admin = $this->demoUser('admin@demo.test', 'Platform Administrator');
            if ($admin->wasRecentlyCreated) {
                $admin->is_super_admin = true;
                $admin->save();
            }
            foreach ($tenants as $index => $tenant) {
                app(TenantContext::class)->run($tenant, function () use ($index) {
                    KnowledgeEntry::firstOrCreate(['title' => 'Demo opening hours'], ['kind' => 'faq', 'content' => 'Fictional demo business opening hours: Monday to Friday, 9am to 6pm. This information is for testing only.', 'approved' => true]);
                    Campaign::firstOrCreate(['name' => 'Demo welcome draft'], ['subject' => 'Welcome to our business', 'body' => 'Hello {{name}}, thank you for your interest. What would you like to learn about our services?', 'segment' => 'all']);
                    Workflow::firstOrCreate(['name' => 'Demo lead follow-up'], ['trigger' => 'contact.created', 'steps' => [['type' => 'task', 'value' => 'Review the new lead'], ['type' => 'delay', 'value' => '60'], ['type' => 'score', 'value' => 'warm']], 'active' => false]);
                    $companyNames = $index === 0 ? ['Northstar Realty', 'Bloom Commerce', 'Evergreen Consulting'] : ['Learning Tree School', 'Future Skills Institute', 'Campus Partners'];
                    $companies = collect($companyNames)->map(fn ($name) => Company::firstOrCreate(['name' => $name], ['website' => 'https://example.com']));
                    $names = $index === 0 ? ['Ananya Sharma', 'Vikram Patel', 'Ishita Kapoor', 'Kabir Sethi', 'Diya Menon', 'Arjun Verma', 'Zoya Ali', 'Rahul Das', 'Meera Iyer', 'Dev Malhotra', 'Sara Thomas', 'Aditi Roy'] : ['Nisha Joshi', 'Omar Khan', 'Tara Bose', 'Ravi Kumar', 'Anika Gupta', 'Sahil Rao'];
                    foreach ($names as $i => $name) {
                        $data = [
                            'name' => $name, 'email' => ($i % 5 === 4) ? null : 'contact'.($i + 1).'@'.($index ? 'brightpath' : 'acme').'.example',
                            'phone' => ($i % 5 === 4) ? null : '+919800'.str_pad((string) ($index * 100 + $i), 6, '0', STR_PAD_LEFT),
                            'company_id' => $companies[$i % 3]->id, 'source' => Contact::SOURCES[$i % 6],
                            'notes' => 'Fictional demo contact. Discuss requirements and confirm the next step.',
                            'email_consent' => $i % 3 === 0, 'whatsapp_consent' => $i % 4 === 0,
                        ];
                        $contact = Contact::firstOrCreate(['name' => $name], array_merge($data, ContactScoring::evaluate($data)));
                        Deal::firstOrCreate(['title' => ($index ? 'Learning programme' : 'Growth partnership').' · '.$name], [
                            'contact_id' => $contact->id, 'amount' => 15000 + $i * 7500,
                            'stage' => Deal::STAGES[$i % 5], 'expected_close_at' => today()->addDays($i * 2 + 1),
                        ]);
                        if ($i < 5) {
                            Task::firstOrCreate(['title' => ['Discuss requirements', 'Send proposal outline', 'Confirm discovery call', 'Review next steps', 'Check in after demo'][$i]], [
                                'contact_id' => $contact->id, 'due_at' => now()->addDays($i - 1)->startOfHour(),
                            ]);
                        }
                    }
                });
            }
            Plan::firstOrCreate(['name' => 'Starter', 'interval' => 'monthly'], ['price_paise' => 149900, 'monthly_messages' => 1000, 'contact_limit' => 10000]);
            Plan::firstOrCreate(['name' => 'Growth', 'interval' => 'yearly'], ['price_paise' => 1499900, 'monthly_messages' => 10000, 'contact_limit' => 100000]);
        });
    }

    private function demoUser(string $email, string $name): User
    {
        $existing = User::where('email', $email)->first();
        if ($existing) {
            // Never change existing passwords or elevate existing users during reseeding.
            if ($existing->tenants()->where('is_demo', false)->exists()) {
                throw new RuntimeException('Demo identity is attached to a non-demo business.');
            }

            return $existing;
        }

        return User::create(['email' => $email, 'name' => $name, 'password' => self::PASSWORD]);
    }
}
