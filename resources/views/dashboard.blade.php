<x-layouts::app :title="__('Dashboard')">
    <div class="flex h-full w-full flex-1 flex-col gap-6 p-4 md:p-6">
        @if ($metrics === null)
            <div class="rounded-xl border border-amber-200 bg-amber-50 p-6 text-amber-900 dark:border-amber-900 dark:bg-amber-950/30 dark:text-amber-200">
                <flux:heading size="lg">{{ __('No merchant workspace assigned') }}</flux:heading>
                <flux:text class="mt-2">{{ __('Ask an administrator to assign your account to a merchant.') }}</flux:text>
            </div>
        @else
            <div class="flex items-center justify-between gap-4">
                <div>
                    <flux:heading size="xl">{{ $merchant->name }} {{ __('Dashboard') }}</flux:heading>
                    <flux:text class="mt-1">{{ __('Usage and billing insights for your merchant workspace.') }}</flux:text>
                </div>
                <flux:badge color="{{ $metrics['system_status']['status'] === 'operational' ? 'green' : 'amber' }}">
                    {{ ucfirst($metrics['system_status']['status']) }}
                </flux:badge>
            </div>

            <div class="grid gap-4 md:grid-cols-3">
                <div class="rounded-xl border border-zinc-200 bg-white p-5 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
                    <flux:text>{{ __('Current cycle usage') }}</flux:text>
                    <flux:heading size="lg" class="mt-2">
                        {{ number_format($metrics['active_plan']['current_cycle_usage']['units'] ?? 0) }}
                        / {{ number_format($metrics['active_plan']['current_cycle_usage']['allowance'] ?? 0) }}
                    </flux:heading>
                    <flux:text class="mt-1">{{ __('units') }}</flux:text>
                </div>
                <div class="rounded-xl border border-zinc-200 bg-white p-5 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
                    <flux:text>{{ __('Projected overage revenue') }}</flux:text>
                    <flux:heading size="lg" class="mt-2">
                        {{ number_format((float) $metrics['projected_overage_revenue'], 2) }}
                    </flux:heading>
                    <flux:text class="mt-1">{{ __('forecast') }}</flux:text>
                </div>
                <div class="rounded-xl border border-zinc-200 bg-white p-5 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
                    <flux:text>{{ __('Active plan') }}</flux:text>
                    <flux:heading size="lg" class="mt-2">
                        {{ $metrics['active_plan']['name'] ?? __('No active plan') }}
                    </flux:heading>
                    <flux:text class="mt-1">
                        {{ $metrics['active_plan']['billing_cycle'] ?? __('No current billing cycle') }}
                    </flux:text>
                </div>
            </div>

            <div class="grid gap-6 lg:grid-cols-3">
                <div class="rounded-xl border border-zinc-200 bg-white p-5 shadow-sm lg:col-span-2 dark:border-zinc-700 dark:bg-zinc-900">
                    <flux:heading size="lg">{{ __('Top customers this month') }}</flux:heading>
                    <div class="mt-4 overflow-x-auto">
                        <table class="w-full text-left text-sm">
                            <thead class="border-b border-zinc-200 text-zinc-500 dark:border-zinc-700">
                                <tr>
                                    <th class="px-2 py-3 font-medium">{{ __('Customer') }}</th>
                                    <th class="px-2 py-3 text-right font-medium">{{ __('Usage') }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse ($metrics['top_customers'] as $customer)
                                    <tr class="border-b border-zinc-100 last:border-0 dark:border-zinc-800">
                                        <td class="px-2 py-3">{{ $customer['name'] }}</td>
                                        <td class="px-2 py-3 text-right">{{ number_format($customer['usage']) }}</td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="2" class="px-2 py-6 text-center text-zinc-500">{{ __('No usage recorded this month.') }}</td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>

                <div class="rounded-xl border border-zinc-200 bg-white p-5 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
                    <flux:heading size="lg">{{ __('Churn risk') }}</flux:heading>
                    <flux:text class="mt-1">{{ __('Customers with a usage drop greater than 50%.') }}</flux:text>
                    <ul class="mt-4 space-y-3 text-sm">
                        @forelse ($metrics['churn_risk_customers'] as $customer)
                            <li class="flex items-center justify-between gap-3">
                                <span>{{ $customer['name'] }}</span>
                                <flux:badge color="red">{{ $customer['drop_percentage'] }}%</flux:badge>
                            </li>
                        @empty
                            <li class="text-zinc-500">{{ __('No churn risk detected.') }}</li>
                        @endforelse
                    </ul>
                </div>
            </div>

            <div class="rounded-xl border border-zinc-200 bg-white p-5 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
                <div class="flex items-center justify-between">
                    <div>
                        <flux:heading size="lg">{{ __('Daily usage trend') }}</flux:heading>
                        <flux:text class="mt-1">{{ __('Last 30 calendar days') }}</flux:text>
                    </div>
                    <flux:text>{{ $metrics['system_status']['message'] }}</flux:text>
                </div>
                <div class="mt-5 grid grid-cols-6 gap-2 sm:grid-cols-10 md:grid-cols-15 lg:grid-cols-30">
                    @foreach ($metrics['usage_trend'] as $day)
                        <div class="group flex min-w-0 flex-col items-center gap-1">
                            <div
                                class="w-full rounded-sm bg-blue-500/80"
                                style="height: {{ max(4, min(100, $day['units'] > 0 ? 20 + log($day['units'] + 1) * 8 : 4)) }}px"
                                title="{{ $day['date'] }}: {{ number_format($day['units']) }} units"
                            ></div>
                            @if ($loop->first || $loop->last || $loop->iteration % 7 === 0)
                                <span class="truncate text-[10px] text-zinc-500">{{ \Illuminate\Support\Carbon::parse($day['date'])->format('M d') }}</span>
                            @endif
                        </div>
                    @endforeach
                </div>
            </div>
        @endif
    </div>
</x-layouts::app>
