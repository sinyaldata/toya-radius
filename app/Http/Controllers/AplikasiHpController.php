<?php

namespace App\Http\Controllers;

use App\Models\Central\Tenant;
use Illuminate\Http\JsonResponse;

/**
 * APLIKASI-HP -- dipakai aplikasi Android "Toya Radius":
 *  - GET /api-app/isp/{kode} : cek kode ISP (subdomain tenant) -> alamat panel
 *  - GET /api-app/versi      : info versi APK terbaru (public/unduh/versi.json)
 *  - GET /aplikasi           : halaman unduh APK
 */
class AplikasiHpController extends Controller
{
    /** Host domain utama, mis. "toyaqi.web.id" (dari APP_URL). */
    protected function hostUtama(): string
    {
        $host = parse_url((string) config('app.url'), PHP_URL_HOST);

        if (! $host || in_array($host, ['localhost', '127.0.0.1'], true)) {
            $host = request()->getHost();
        }

        return strtolower((string) $host);
    }

    protected function json(array $data, int $status = 200): JsonResponse
    {
        return response()->json($data, $status)
            ->header('Access-Control-Allow-Origin', '*')
            ->header('Cache-Control', 'no-store');
    }

    public function isp(string $kode): JsonResponse
    {
        $kode = strtolower(trim($kode));
        $host = $this->hostUtama();
        $depanHost = explode('.', $host)[0] ?? '';

        // Instalasi utama (domain tanpa subdomain tenant)
        if (in_array($kode, array_filter(['utama', 'pusat', $depanHost]), true)) {
            return $this->json([
                'ok' => true,
                'kode' => $kode,
                'nama' => (string) config('app.name', 'Toya Radius'),
                'url' => 'https://'.$host.'/admin',
            ]);
        }

        if (! preg_match('/^[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/', $kode)) {
            return $this->json(['ok' => false, 'pesan' => 'Kode ISP tidak valid.'], 404);
        }

        try {
            $tenant = Tenant::where('subdomain', $kode)->first();
        } catch (\Throwable $e) {
            report($e);

            return $this->json(['ok' => false, 'pesan' => 'Server sedang bermasalah, coba lagi sebentar.'], 500);
        }

        if (! $tenant) {
            return $this->json(['ok' => false, 'pesan' => 'Kode ISP "'.$kode.'" tidak ditemukan. Periksa lagi ejaannya.'], 404);
        }

        if (! $tenant->isActive()) {
            return $this->json(['ok' => false, 'pesan' => 'Langganan ISP ini sedang tidak aktif. Hubungi pengelola.'], 403);
        }

        return $this->json([
            'ok' => true,
            'kode' => $kode,
            'nama' => (string) $tenant->name,
            'url' => 'https://'.$kode.'.'.$host.'/admin',
        ]);
    }

    protected function bacaVersi(): array
    {
        $file = public_path('unduh/versi.json');

        if (! is_file($file)) {
            return ['kode' => 0];
        }

        $data = json_decode((string) file_get_contents($file), true);

        return is_array($data) ? $data : ['kode' => 0];
    }

    public function versi(): JsonResponse
    {
        return $this->json($this->bacaVersi());
    }

    public function unduh()
    {
        return response()->view('aplikasi-hp.unduh', ['versi' => $this->bacaVersi()]);
    }
}
