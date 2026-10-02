<?php

namespace App\Domains\Hestia\Services;

use App\Domains\Clients\Models\Client;
use App\Domains\Hestia\Enums\HestiaMappingStatus;
use App\Domains\Services\Models\Service;
use Illuminate\Support\Str;

/**
 * Strategi pencocokan otomatis akun Hestia → klien CRM (F3-1).
 *
 * Urutan aturan (berhenti pada yang pertama cocok):
 *   1. Layanan CRM yang sudah ada dengan referensi/nama = domain → adopsi
 *      layanan itu (klien & service_id diambil dari situ). Ini menghormati data
 *      yang tadinya diinput manual.
 *   2. Klien yang alamat emailnya memakai domain yang sama (atau domain
 *      registrabel-nya, mis. crm.mcimedia.net vs mcimedia.net).
 *   3. Nama klien (di-slug) sama dengan salah satu kandidat label domain atau
 *      dengan username Hestia.
 * Selain itu → belum dipetakan (perlu pemetaan manual admin).
 */
class HestiaClientMatcher
{
    /**
     * @return array{client_id: ?int, service_id: ?int, mapping_status: HestiaMappingStatus}
     */
    public function match(string $domain, string $hestiaUser): array
    {
        $domain = mb_strtolower(trim($domain));

        $service = $this->existingService($domain);
        if ($service !== null) {
            return [
                'client_id' => $service->client_id,
                'service_id' => $service->id,
                'mapping_status' => HestiaMappingStatus::Auto,
            ];
        }

        $client = $this->clientByEmailDomain($domain) ?? $this->clientByName($domain, $hestiaUser);

        if ($client !== null) {
            return [
                'client_id' => $client->id,
                'service_id' => null,
                'mapping_status' => HestiaMappingStatus::Auto,
            ];
        }

        return [
            'client_id' => null,
            'service_id' => null,
            'mapping_status' => HestiaMappingStatus::Unmapped,
        ];
    }

    /** Layanan eksisting dengan referensi atau nama sama dengan domain. */
    private function existingService(string $domain): ?Service
    {
        return Service::query()
            ->whereRaw('lower(reference) = ?', [$domain])
            ->orWhereRaw('lower(name) = ?', [$domain])
            ->orderBy('id')
            ->first();
    }

    private function clientByEmailDomain(string $domain): ?Client
    {
        $candidates = $this->domainCandidates($domain);
        $emails = Client::query()->whereNotNull('email')->pluck('email');

        foreach ($emails as $email) {
            $at = strrpos((string) $email, '@');
            if ($at === false) {
                continue;
            }

            $emailDomain = mb_strtolower(trim(substr((string) $email, $at + 1)));

            if ($emailDomain !== '' && in_array($emailDomain, $candidates, true)) {
                /** @var Client|null $client */
                $client = Client::query()->where('email', $email)->first();

                return $client;
            }
        }

        return null;
    }

    private function clientByName(string $domain, string $hestiaUser): ?Client
    {
        $candidates = array_unique([
            ...$this->domainCandidates($domain),
            Str::slug($hestiaUser),
        ]);

        $clients = Client::query()->get(['id', 'name']);

        foreach ($clients as $client) {
            if (in_array(Str::slug((string) $client->name), $candidates, true)) {
                return $client;
            }
        }

        return null;
    }

    /**
     * Kandidat label dari sebuah domain untuk dicocokkan dengan nama/email klien:
     * domain penuh, domain registrabel (dua label terakhir), dan subdomain pertama.
     *
     * @return list<string>
     */
    private function domainCandidates(string $domain): array
    {
        $domain = Str::slug($domain, '.');
        $domain = str_replace('www.', '', $domain);

        $labels = array_values(array_filter(explode('.', $domain)));
        $candidates = [$domain];

        if (count($labels) >= 2) {
            $candidates[] = implode('.', array_slice($labels, -2));
            $candidates[] = $labels[0];
        }

        return array_values(array_unique(array_filter($candidates)));
    }
}
