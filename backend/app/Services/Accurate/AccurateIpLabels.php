<?php

namespace App\Services\Accurate;

use App\Models\AccurateIpLabel;

/**
 * User name / division / note per IP for the Accurate Online page, stored in
 * the STOCKWISE database (accurate_ip_labels). Never touches Accurate / Firebird.
 */
class AccurateIpLabels
{
    /** @return array<string, AccurateIpLabel> keyed by IP */
    public function all(): array
    {
        return AccurateIpLabel::query()->get()->keyBy('ip')->all();
    }

    /** Save the label of an IP; all fields empty = remove it. */
    public function put(string $ip, array $fields, ?int $actorId): ?AccurateIpLabel
    {
        $values = [];
        foreach (['user_name', 'division', 'note'] as $key) {
            $v = trim((string) ($fields[$key] ?? ''));
            $values[$key] = $v !== '' ? $v : null;
        }

        if (array_filter($values) === []) {
            AccurateIpLabel::where('ip', $ip)->delete();

            return null;
        }

        return AccurateIpLabel::updateOrCreate(['ip' => $ip], $values + ['updated_by' => $actorId]);
    }
}
