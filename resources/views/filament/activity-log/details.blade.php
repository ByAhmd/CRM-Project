<dl class="crm-activity-details divide-y divide-gray-100 dark:divide-white/10">
    @foreach ($rows as $row)
        <div class="flex flex-col gap-1 py-3 sm:flex-row sm:gap-4">
            <dt class="min-w-40 shrink-0 text-sm font-medium text-gray-500 dark:text-gray-400">
                {{ $row['label'] }}
            </dt>
            <dd class="text-sm text-gray-950 dark:text-white break-words">
                {{ $row['value'] }}
            </dd>
        </div>
    @endforeach
</dl>
