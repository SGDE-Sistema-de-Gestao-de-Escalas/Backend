<x-mail::message>
# Reposição de Palavra-passe

Foi submetido um pedido de recuperação de acesso para a conta de **{{ $user->first_name }} {{ $user->last_name }}** no sistema **SGDE**.

Para definir uma nova palavra-passe, utilize o botão abaixo:

<x-mail::button :url="$resetUrl" color="primary">
Repor Palavra-passe
</x-mail::button>

@include('emails.partials.fallback-link', ['url' => $resetUrl, 'label' => 'Hiperligação direta para recuperação:'])

<p class="help-note">
Se este pedido não foi efetuado pelo titular da conta, nenhuma ação é necessária. A palavra-passe atual permanece inalterada.
</p>
</x-mail::message>