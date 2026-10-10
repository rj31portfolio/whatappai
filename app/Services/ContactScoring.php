<?php

namespace App\Services;

class ContactScoring
{
    public static function evaluate(array $data): array
    {
        if (! empty($data['email']) && ! empty($data['phone']) && ($data['source'] ?? null) === 'referral') {
            return ['score' => 'hot', 'score_reason' => 'Referral with both email and phone.'];
        }
        if (! empty($data['email']) || ! empty($data['phone'])) {
            return ['score' => 'warm', 'score_reason' => 'At least one contact channel supplied.'];
        }

        return ['score' => 'cold', 'score_reason' => 'No contact channel supplied yet.'];
    }
}
