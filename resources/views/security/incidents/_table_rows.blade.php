@forelse ($incidents as $incident)
    <tr>
        <td class="text-slate-400">{{ $incident->occurred_at?->format('d/m/Y H:i') ?? '—' }}</td>
        <td>{{ $incident->client?->name ?? '—' }}</td>
        <td>
            <span class="inline-flex items-center rounded-full px-2 py-1 text-xs font-semibold {{ $incident->severity->badgeClass() }}">{{ $incident->severity->label() }}</span>
        </td>
        <td>{{ $incident->source->label() }}</td>
        <td>
            <span class="font-semibold">{{ $incident->title }}</span>
            @if ($incident->description)
                <p class="mt-1 max-w-md text-xs text-slate-400">{{ \Illuminate\Support\Str::limit($incident->description, 120) }}</p>
            @endif
        </td>
        <td>
            <span class="inline-flex items-center rounded-full px-2 py-1 text-xs font-semibold {{ $incident->status->badgeClass() }}">{{ $incident->status->label() }}</span>
        </td>
        <td class="whitespace-nowrap text-right">
            <a href="{{ route('security.incidents.edit', $incident) }}" class="text-sm font-semibold text-brand-600 hover:underline">Ubah</a>
            <span class="mx-1 text-slate-300">|</span>
            <form method="POST" action="{{ route('security.incidents.destroy', $incident) }}" class="inline"
                  onsubmit="return confirm('Hapus insiden ini?')">
                @csrf
                @method('DELETE')
                <button class="text-sm font-semibold text-rose-600 hover:underline">Hapus</button>
            </form>
        </td>
    </tr>
@empty
    <tr><td colspan="7" class="py-10 text-center text-slate-400">Belum ada insiden.</td></tr>
@endforelse