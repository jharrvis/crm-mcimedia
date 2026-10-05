<x-input name="name" label="Nama" :required="true" :value="$contact?->name" />
<x-input name="role" label="Peran / jabatan" :value="$contact?->role" />
<x-input name="email" label="Email" type="email" :value="$contact?->email" />
<x-input name="whatsapp" label="WhatsApp" :value="$contact?->whatsapp" placeholder="628…" />