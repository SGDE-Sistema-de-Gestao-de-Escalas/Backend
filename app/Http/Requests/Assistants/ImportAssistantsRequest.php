<?php

namespace App\Http\Requests\Assistants;

use App\Models\Assistants\Assistant;
use App\Services\AssistantImportService;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ImportAssistantsRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()?->can('create', Assistant::class) ?? false;
    }

    /**
     * Os assistentes importados ficam na escola do cabeçalho X-School-ID
     * (a escola nunca vem no ficheiro).
     */
    protected function prepareForValidation(): void
    {
        $this->merge(['school_id' => $this->header('X-School-ID')]);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'school_id' => ['required', 'uuid', Rule::exists('schools', 'id')->where('active', true)],
            'file' => ['required', 'file', 'mimes:csv,txt', 'max:2048'],
            'on_duplicate' => ['sometimes', Rule::in([
                AssistantImportService::ON_DUPLICATE_SKIP,
                AssistantImportService::ON_DUPLICATE_OVERWRITE,
            ])],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'school_id.required' => 'Por favor, selecione uma escola.',
            'school_id.uuid' => 'A escola selecionada é inválida.',
            'school_id.exists' => 'A escola selecionada não existe ou encontra-se inativa.',
            'file.required' => 'Selecione o ficheiro CSV a importar.',
            'file.file' => 'O ficheiro enviado é inválido.',
            'file.mimes' => 'O ficheiro tem de ser um CSV (.csv).',
            'file.max' => 'O ficheiro não pode ter mais de 2 MB.',
            'on_duplicate.in' => 'A opção para assistentes já existentes deve ser "skip" (ignorar) ou "overwrite" (atualizar).',
        ];
    }
}
