@props(['align' => 'left'])

<div class="{{ $align === 'right' ? 'text-right' : 'text-left' }}">
    <div class="inline-block h-10 {{ $align === 'right' ? 'w-40' : 'w-56' }} bg-gray-200 rounded animate-pulse"></div>
</div>
