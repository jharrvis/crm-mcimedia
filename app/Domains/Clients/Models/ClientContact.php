<?php

namespace App\Domains\Clients\Models;

use App\Domains\Core\Traits\LogsActivity;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ClientContact extends Model
{
    use HasFactory;
    use LogsActivity;

    protected $fillable = ['client_id', 'name', 'role', 'email', 'whatsapp'];

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function activityLabel(): string
    {
        return "kontak {$this->name}";
    }
}
