<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class ExerciseTagRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        // Quyền class.manage được chặn ở route admin và kiểm tiếp trong ExerciseTagService.
        return $this->user() !== null;
    }

    /**
     * Get the validation rules that apply to the request.
     */
    public function rules(): array
    {
        $isPost = $this->isMethod('post');

        return [
            'name'        => [$isPost ? 'required' : 'sometimes', 'string', 'max:100'],
            'kind'        => [$isPost ? 'required' : 'sometimes', 'string', 'in:difficulty,category'],
            'part'        => ['nullable', 'string', 'in:ALL,DDL,DML,DQL,TRIGGER'],
            'orderIndex'  => ['nullable', 'integer'],
            'order_index' => ['nullable', 'integer'],
        ];
    }

    /**
     * Return normalized payload with snake_case fields
     */
    public function validatedPayload(): array
    {
        $data = $this->validated();

        $payload = [];
        if (isset($data['name'])) {
            $payload['name'] = trim((string) $data['name']);
        }
        if (isset($data['kind'])) {
            $payload['kind'] = strtolower(trim((string) $data['kind']));
        }
        if (array_key_exists('part', $data)) {
            $part = strtoupper(trim((string) ($data['part'] ?? 'ALL')));
            $payload['part'] = in_array($part, ['ALL', 'DDL', 'DML', 'DQL', 'TRIGGER'], true) ? $part : 'ALL';
        }
        if (isset($data['orderIndex']) || isset($data['order_index'])) {
            $payload['order_index'] = (int) ($data['orderIndex'] ?? $data['order_index']);
        }

        return $payload;
    }
}

