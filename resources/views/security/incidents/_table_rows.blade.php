@forelse ($incidents as $incident)
    <tr class="border-t border-slate-100 dark:border-slate-800">
        <td class="px-4 py-3 text-slate-500">{{ $incident->occurred_at?->format('d/m/Y H:i') ?? '—' }}</td>
        <td class="px-4 py-3">{{ $incident->client?->name ?? '—' }}</td>
        <td class="px-4 py-3"><span class="rounded-full px-2 py-0.5 text-xs font-medium {{ $incident->severity->badgeClass() }}">{{ $incident->severity->label() }}</span></td>
        <td class="px-4 py-3">{{ $incident->source->label() }}</td>
        <td class="px-4 py-3">
            <span class="font-medium">{{ $incident->title }}</span>
            @if ($incident->description)
                <p class="mt-1 max-w-md text-xs text-slate-500">{{ \Illuminate\Support\Str::limit($incident->description, 120) }}</p>
            @endif
        </td>
        <td class="px-4 py-3"><span class="rounded-full px-2 py-0.5 text-xs font-medium {{ $incident->status->badgeClass() }}">{{ $incident->status->label() }}</span></td>
        <td class="px-4 py-3 text-right whitespace-nowrap">
            <a href="{{ route('security.incidents.edit', $incident) }}" class="text-sm text-indigo-600 hover:underline">Ubah</a>
            <form method="POST" action="{{ route('security.incidents.destroy', $incident) }}" class="inline"
                  onsubmit="return confirm('Hapus insiden ini?')">
                @csrf
                @method('DELETE')
                <button class="ml-2 text-sm text-red-600 hover:underline">Hapus</button>
            </form>
        </td>
    </tr>
@empty
    <tr><td colspan="7" class="px-4 py-8 text-center text-slate-500">Belum ada insiden.</td></tr>
@endforelse