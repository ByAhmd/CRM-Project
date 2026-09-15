{{--
    Published from Laravel's mail views (decisions D-5, D-10): the plain-text
    part carries the translated product name and rights line; a mailable
    sent on behalf of the organisation passes its name as brand.
--}}
@props(['brand' => null])
@php($crmBrand = is_string($brand) && trim($brand) !== '' ? $brand : __('app.name'))
<x-mail::layout>
    {{-- Header --}}
    <x-slot:header>
        <x-mail::header :url="config('app.url')">
            {{ $crmBrand }}
        </x-mail::header>
    </x-slot:header>

    {{-- Body --}}
    {{ $slot }}

    {{-- Subcopy --}}
    @isset($subcopy)
        <x-slot:subcopy>
            <x-mail::subcopy>
                {{ $subcopy }}
            </x-mail::subcopy>
        </x-slot:subcopy>
    @endisset

    {{-- Footer --}}
    <x-slot:footer>
        <x-mail::footer>
            {{ __('email.mail.rights', ['year' => date('Y'), 'name' => $crmBrand]) }}
        </x-mail::footer>
    </x-slot:footer>
</x-mail::layout>
