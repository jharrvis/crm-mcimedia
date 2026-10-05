<x-input name="name" label="Nama usaha" :required="true" :value="$client?->name" />
<x-input name="contact_name" label="Nama kontak utama" :value="$client?->contact_name" />
<x-input name="email" label="Email" type="email" :value="$client?->email" />
<x-input name="whatsapp" label="WhatsApp" :value="$client?->whatsapp" placeholder="628…" />
<x-input name="address" label="Alamat" type="textarea" rows="2" :value="$client?->address" />
<x-input name="notes" label="Catatan" type="textarea" rows="3" :value="$client?->notes" />
<label class="flex items-center gap-2 text-sm text-slate-700 dark:text-slate-300">
    <input type="checkbox" name="is_active" value="1" @checked(old('is_active', $client?->is_active ?? true))
           class="rounded border-slate-300 text-brand-600 focus:ring-brand-500">
    Klien aktif
</label>
