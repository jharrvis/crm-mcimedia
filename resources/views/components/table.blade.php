{{-- Tabel bergaya template: header uppercase abu, baris hover, geser horizontal.
     Slot berisi isi <table> lengkap (thead + tbody) supaya struktur HTML valid:
     pemanggil menulis <thead>/<tbody>-nya sendiri. Padding sel & hover baris
     datang dari .ds-table di app.css (@layer components, jadi utility bg
     status di <tr> tetap menang). --}}
<div {{ $attributes->merge(['class' => 'overflow-x-auto rounded-xl']) }}>
    <table class="ds-table w-full text-left text-sm">
        {{ $slot }}
    </table>
</div>