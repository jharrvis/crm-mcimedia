<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Situs yang dipindai WPScan otomatis (t_2e555b0b).
     *
     * Satu baris = satu domain target scan. Baris dibuat otomatis oleh
     * `crm:wpscan` dari akun Hestia aktif yang sudah terpetakan ke klien,
     * lalu status deteksi WordPress disimpan di sini agar domain non-WordPress
     * tidak dipindai ulang setiap jadwal.
     *
     * `domain` unik: dua akun Hestia yang menunjuk domain sama (multi-server)
     * berbagi satu baris target — scan terhadap domain yang sama dua kali
     * sehari hanya buang kuota API dan berisiko dianggap serangan.
     */
    public function up(): void
    {
        Schema::create('wp_scan_sites', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('hestia_account_id')->nullable()->constrained('hestia_accounts')->nullOnDelete();
            $table->string('domain')->unique();
            $table->string('url');                    // https://<domain> yang dipindai
            $table->string('status', 24)->default('pending_detection')->index(); // pending_detection|active|non_wordpress|error|disabled
            $table->string('wp_version', 32)->nullable();
            $table->timestamp('last_scan_at')->nullable();
            $table->unsignedInteger('last_finding_count')->default(0);
            $table->text('last_error')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wp_scan_sites');
    }
};