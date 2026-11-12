<div>
    <x-slot:title>
        {{ data_get_str($server, 'name')->limit(10) }} > Availability | Coolify
    </x-slot>

    <livewire:server.navbar :server="$server" />

    <div
        class="server-settings-workspace application-settings-workspace mt-4 grid w-full max-w-none min-w-0 gap-8 lg:mt-0 xl:grid-cols-[210px_minmax(0,1fr)] xl:gap-8">
        <x-server.sidebar :server="$server" activeMenu="availability" />

        <div class="flex w-full min-w-0 flex-col gap-6" wire:poll.60s>
            <x-application.settings-section id="server-availability-section" title="Availability"
                helper="Uptime of the server as Coolify sees it. Coolify marks a server as down after two failed connection checks, about 3 to 5 minutes after it stops answering, so short outages are not recorded. A disabled server is not counted as down.">
                <x-availability.summary :model="$server" :server="$server" />
            </x-application.settings-section>
        </div>
    </div>
</div>
