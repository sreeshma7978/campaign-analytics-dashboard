<?php

namespace App\Http\Requests;

use App\Enums\EngagementEventType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreEngagementEventRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'event_id' => ['required', 'string', 'max:128'],
            'campaign_id' => ['required', 'string', 'max:128'],
            'type' => ['required', 'string', Rule::in(EngagementEventType::values())],
            'timestamp' => ['required', 'date'],
        ];
    }
}
