<?php

namespace App\Http\Requests\Assistants;

use App\Models\Assistants\Assistant;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreAssistantRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()?->can('create', Assistant::class) ?? false;
    }

    /**
     * A escola vem do header X-School-ID (escola selecionada no frontend).
     * Passa para o body para ser validada como qualquer outro campo.
     * NIF e NSS chegam muitas vezes com espaços (ex.: "234 567 890").
     */
    protected function prepareForValidation(): void
    {
        $this->merge(array_filter([
            'school_id' => $this->header('X-School-ID'),
            'nif' => $this->filled('nif') ? str_replace(' ', '', (string) $this->input('nif')) : null,
            'social_security_number' => $this->filled('social_security_number')
                ? str_replace(' ', '', (string) $this->input('social_security_number'))
                : null,
        ], fn ($value) => $value !== null));
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'school_id' => ['required', 'uuid', Rule::exists('schools', 'id')->where('active', true)],

            // Conta (users)
            'first_name' => ['required', 'string', 'max:255'],
            'last_name' => ['required', 'string', 'max:255'],
            'email' => [
                'required', 'email', 'max:255',
                Rule::unique('users', 'email')->whereNull('deleted_at'),
            ],

            // Ficha do assistente (assistants)
            'internal_number' => [
                'required', 'string', 'max:50',
                Rule::unique('assistants', 'internal_number')->whereNull('deleted_at'),
            ],
            'phone' => [
                'nullable', 'string', 'max:20',
                Rule::unique('assistants', 'phone')->whereNull('deleted_at'),
            ],
            'nif' => [
                'nullable', 'digits:9',
                Rule::unique('assistants', 'nif')->whereNull('deleted_at'),
            ],
            'social_security_number' => [
                'nullable', 'digits:11',
                Rule::unique('assistants', 'social_security_number')->whereNull('deleted_at'),
            ],
            'birth_date' => ['nullable', 'date', 'before:today'],
            'admission_date' => ['nullable', 'date'],
            'criminal_record_expiry' => ['nullable', 'date'],

            'address_street' => ['nullable', 'string', 'max:255'],
            'address_zip_code' => ['nullable', 'string', 'regex:/^\d{4}-\d{3}$/'],
            'address_city' => ['nullable', 'string', 'max:100'],

            'emergency_contact_name' => ['nullable', 'string', 'max:255'],
            'emergency_contact_phone' => ['nullable', 'string', 'max:20'],
            'emergency_contact_kinship' => ['nullable', 'string', 'max:50'],

            'available_for_transfer' => ['sometimes', 'boolean'],
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
            'first_name.required' => 'O primeiro nome é obrigatório.',
            'first_name.string' => 'O primeiro nome contém carateres inválidos.',
            'first_name.max' => 'O primeiro nome não pode ter mais de 255 carateres.',
            'last_name.required' => 'O apelido é obrigatório.',
            'last_name.string' => 'O apelido contém carateres inválidos.',
            'last_name.max' => 'O apelido não pode ter mais de 255 carateres.',
            'email.required' => 'O email é obrigatório.',
            'email.email' => 'Por favor, introduza um endereço de email válido.',
            'email.max' => 'O email não pode ter mais de 255 carateres.',
            'email.unique' => 'Já existe um utilizador registado com este email.',
            'internal_number.required' => 'O número mecanográfico é obrigatório.',
            'internal_number.string' => 'O número mecanográfico é inválido.',
            'internal_number.max' => 'O número mecanográfico não pode ter mais de 50 carateres.',
            'internal_number.unique' => 'Este número mecanográfico já se encontra atribuído a outro assistente.',
            'phone.unique' => 'Este número de telefone já se encontra registado.',
            'phone.max' => 'O número de telefone não pode ter mais de 20 carateres.',
            'nif.digits' => 'O NIF tem de conter exatamente 9 dígitos.',
            'nif.unique' => 'Este NIF já se encontra registado.',
            'social_security_number.digits' => 'O número de Segurança Social tem de conter exatamente 11 dígitos.',
            'social_security_number.unique' => 'Este número de Segurança Social já se encontra registado.',
            'birth_date.date' => 'A data de nascimento introduzida não é válida.',
            'birth_date.before' => 'A data de nascimento tem de ser anterior à data de hoje.',
            'admission_date.date' => 'A data de admissão introduzida não é válida.',
            'criminal_record_expiry.date' => 'A data de validade do registo criminal não é válida.',
            'address_street.max' => 'A morada não pode ter mais de 255 carateres.',
            'address_zip_code.regex' => 'O código postal tem de seguir o formato 0000-000.',
            'address_city.max' => 'A localidade não pode ter mais de 100 carateres.',
            'emergency_contact_name.max' => 'O nome do contacto de emergência não pode ter mais de 255 carateres.',
            'emergency_contact_phone.max' => 'O telefone do contacto de emergência não pode ter mais de 20 carateres.',
            'emergency_contact_kinship.max' => 'O grau de parentesco não pode ter mais de 50 carateres.',
            'available_for_transfer.boolean' => 'O campo de disponibilidade para transferência tem de ser sim ou não.',
        ];
    }
}
