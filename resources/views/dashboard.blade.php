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
                <div data-testid="current-cycle-card" class="rounded-xl border border-zinc-200 bg-white p-5 shadow-sm dark:border-zinc-700 dark:bg-zinc-900" style="border-left: 4px solid #2563eb;">
                    <flux:text>{{ __('Current cycle usage') }}</flux:text>
                    <flux:heading size="lg" class="mt-2">
                        {{ number_format($metrics['active_plan']['current_cycle_usage']['units'] ?? 0) }}
                        / {{ number_format($metrics['active_plan']['current_cycle_usage']['allowance'] ?? 0) }}
                    </flux:heading>
                    <flux:text class="mt-1">{{ __('units') }}</flux:text>
                </div>
                <div data-testid="projected-overage-card" class="rounded-xl border border-zinc-200 bg-white p-5 shadow-sm dark:border-zinc-700 dark:bg-zinc-900" style="border-left: 4px solid #f97316;">
                    <flux:text>{{ __('Projected overage revenue') }}</flux:text>
                    <flux:heading size="lg" class="mt-2">
                        {{ number_format((float) $metrics['projected_overage_revenue'], 2) }}
                    </flux:heading>
                    <flux:text class="mt-1">{{ __('forecast') }}</flux:text>
                </div>
                <div data-testid="active-plan-card" class="rounded-xl border border-zinc-200 bg-white p-5 shadow-sm dark:border-zinc-700 dark:bg-zinc-900" style="border-left: 4px solid #16a34a;">
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

                <div data-testid="churn-risk-card" class="rounded-xl border border-red-300 p-5 shadow-sm dark:border-red-900" style="background-color: #fef2f2;">
                    <flux:heading size="lg">{{ __('Churn risk') }}</flux:heading>
                    <flux:text class="mt-1 text-red-800 dark:text-red-200">{{ __('Customers with a usage drop greater than 50%.') }}</flux:text>
                    <ul class="mt-4 space-y-3 text-sm">
                        @forelse ($metrics['churn_risk_customers'] as $customer)
                            <li class="flex items-center justify-between gap-3">
                                <span>{{ $customer['name'] }}</span>
                                <flux:badge color="red">{{ $customer['drop_percentage'] }}%</flux:badge>
                            </li>
                        @empty
                            <li class="text-red-800 dark:text-red-200">{{ __('No churn risk detected.') }}</li>
                        @endforelse
                    </ul>
                </div>
            </div>

            <div data-testid="system-status-card" class="rounded-xl border border-sky-300 p-5 shadow-sm dark:border-sky-800" style="background-color: #eff6ff;">
                <flux:heading size="lg" class="text-sky-700 dark:text-sky-300">
                    {{ __('System status (informational)') }}
                </flux:heading>
                <ul class="mt-4 grid gap-3 text-sm leading-6 text-sky-900 dark:text-sky-100 md:grid-cols-3 md:gap-6">
                    <li>
                        <span class="block text-xs font-medium uppercase tracking-wide text-zinc-500">{{ __('Plan pricing cache') }}</span>
                        <span>{{ $metrics['system_status']['cache'] }}, {{ __('TTL :minutes minutes', ['minutes' => $metrics['system_status']['cache_ttl_minutes']]) }}</span>
                    </li>
                    <li>
                        <span class="block text-xs font-medium uppercase tracking-wide text-zinc-500">{{ __('Nightly aggregation job') }}</span>
                        <span>{{ $metrics['system_status']['aggregation'] }} ({{ number_format($metrics['system_status']['aggregation_chunk_size']) }} {{ __('rows/batch') }})</span>
                    </li>
                    <li>
                        <span class="block text-xs font-medium uppercase tracking-wide text-zinc-500">{{ __('Usage endpoint') }}</span>
                        <span>{{ __('Rate-limited') }} ({{ $metrics['system_status']['usage_rate_limit'] }})</span>
                    </li>
                </ul>
            </div>

            <div class="rounded-xl border border-zinc-200 bg-white p-5 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
                <div class="flex items-center justify-between">
                    <div>
                        <flux:heading size="lg">{{ __('Daily usage trend') }}</flux:heading>
                        <flux:text class="mt-1">{{ __('Last 30 calendar days') }}</flux:text>
                    </div>
                    <flux:text>{{ $metrics['system_status']['message'] }}</flux:text>
                </div>
                @php
                    $trend = collect($metrics['usage_trend']);
                    $trendMax = max(1, (int) $trend->max('units'));
                    $trendPoints = $trend->map(function (array $day, int $index) use ($trend, $trendMax): string {
                        $x = $trend->count() > 1 ? ($index / ($trend->count() - 1)) * 1000 : 500;
                        $y = 232 - (($day['units'] / $trendMax) * 192);

                        return number_format($x, 2, '.', '').','.number_format($y, 2, '.', '');
                    })->implode(' ');
                @endphp
                <div class="mt-5 overflow-hidden rounded-lg border border-zinc-200 dark:border-zinc-700">
                    <svg
                        class="h-64 w-full min-w-[640px]"
                        viewBox="0 0 1000 260"
                        role="img"
                        aria-label="{{ __('Daily usage trend for the last 30 calendar days') }}"
                    >
                        <line x1="0" y1="24" x2="1000" y2="24" stroke="#3f3f46" stroke-width="1" />
                        <line x1="0" y1="128" x2="1000" y2="128" stroke="#3f3f46" stroke-width="1" />
                        <line x1="0" y1="232" x2="1000" y2="232" stroke="#52525b" stroke-width="1" />
                        @foreach ([0, 250, 500, 750, 1000] as $gridX)
                            <line x1="{{ $gridX }}" y1="24" x2="{{ $gridX }}" y2="232" stroke="#3f3f46" stroke-width="1" />
                        @endforeach
                        <polyline
                            points="{{ $trendPoints }}"
                            fill="none"
                            stroke="#3b82f6"
                            stroke-width="4"
                            stroke-linecap="round"
                            stroke-linejoin="round"
                            vector-effect="non-scaling-stroke"
                        />
                    </svg>
                </div>
                <div class="mt-2 flex justify-between text-xs text-zinc-500">
                    @foreach ($trend as $day)
                        @if ($loop->first || $loop->last || $loop->iteration % 7 === 0)
                            <span>{{ \Illuminate\Support\Carbon::parse($day['date'])->format('M d') }}</span>
                        @endif
                    @endforeach
                </div>
            </div>
        @endif
    </div>
</x-layouts::app>
