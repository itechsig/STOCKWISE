<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Http\Request;
use Tests\TestCase;

/**
 * Login hardening for access through Cloudflare Tunnel: the visitor's real IP
 * (only trusted from cloudflared on loopback) and the per-account lockout
 * (5 failed passwords → 15 minutes, per account + IP so nobody can lock a
 * colleague out on purpose).
 */
class LoginSecurityTest extends TestCase
{
    use RefreshDatabase;

    protected $seeder = RbacSeeder::class;

    private const CLOUDFLARED = '127.0.0.1';

    protected function setUp(): void
    {
        parent::setUp();
        User::factory()->create(['username' => 'budi', 'is_active' => true]); // password: "password"
    }

    private function attempt(string $password, string $remoteAddr = '10.10.20.50', ?string $forwardedFor = null, string $username = 'budi')
    {
        $req = $this->withServerVariables(['REMOTE_ADDR' => $remoteAddr]);
        if ($forwardedFor !== null) {
            $req = $req->withHeader('X-Forwarded-For', $forwardedFor);
        }

        return $req->postJson('/api/login', ['username' => $username, 'password' => $password]);
    }

    private function failFiveTimes(string $remoteAddr = '10.10.20.50', ?string $forwardedFor = null, string $username = 'budi'): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->attempt('salah', $remoteAddr, $forwardedFor, $username)->assertStatus(422);
        }
    }

    public function test_five_failed_passwords_lock_the_account_for_fifteen_minutes(): void
    {
        $this->failFiveTimes();

        // even the right password is refused while locked
        $this->attempt('password')->assertStatus(429)
            ->assertJsonPath('errors.username.0', 'Terlalu banyak percobaan login yang gagal. Coba lagi dalam 15 menit.');

        $this->travel(14)->minutes();
        $this->attempt('password')->assertStatus(429);

        $this->travel(61)->seconds();
        $this->attempt('password')->assertOk()->assertJsonStructure(['token']);
    }

    public function test_the_fifteen_minutes_run_from_the_fifth_failure(): void
    {
        for ($i = 0; $i < 4; $i++) {
            $this->attempt('salah')->assertStatus(422);
            $this->travel(3)->minutes(); // 4 failures spread over 12 minutes
        }
        $this->attempt('salah')->assertStatus(422); // 5th

        $this->travel(10)->minutes();
        $this->attempt('password')->assertStatus(429);
    }

    public function test_a_successful_login_resets_the_failure_count(): void
    {
        for ($i = 0; $i < 4; $i++) {
            $this->attempt('salah')->assertStatus(422);
        }
        $this->attempt('password')->assertOk();

        for ($i = 0; $i < 4; $i++) {
            $this->attempt('salah')->assertStatus(422);
        }
        $this->attempt('password')->assertOk();
    }

    public function test_someone_else_cannot_lock_an_employee_out_from_another_place(): void
    {
        $this->failFiveTimes('10.10.20.66'); // attacker's PC
        $this->attempt('password', '10.10.20.66')->assertStatus(429);

        $this->attempt('password', '10.10.20.50')->assertOk(); // the employee's own PC
    }

    public function test_unknown_usernames_are_locked_the_same_way(): void
    {
        $this->failFiveTimes(username: 'tidak-ada');

        $this->attempt('password', username: 'tidak-ada')->assertStatus(429);
    }

    public function test_behind_cloudflared_the_visitor_ip_comes_from_the_last_forwarded_entry(): void
    {
        // Cloudflare's edge appends the real visitor IP; anything before it is client-supplied
        $this->failFiveTimes(self::CLOUDFLARED, '203.0.113.9');
        $this->attempt('password', self::CLOUDFLARED, '203.0.113.9')->assertStatus(429);
        $this->attempt('password', self::CLOUDFLARED, '6.6.6.6, 203.0.113.9')->assertStatus(429);

        // another visitor through the same tunnel is not affected
        $this->attempt('password', self::CLOUDFLARED, '198.51.100.20')->assertOk();
    }

    public function test_a_client_that_is_not_cloudflared_cannot_fake_its_ip(): void
    {
        $this->failFiveTimes('10.10.20.50');

        // a forged X-Forwarded-For from an untrusted address is ignored
        $this->attempt('password', '10.10.20.50', '203.0.113.77')->assertStatus(429);
    }

    public function test_forwarded_scheme_is_only_trusted_from_cloudflared(): void
    {
        $viaTunnel = Request::create('/api/login', 'POST', server: [
            'REMOTE_ADDR' => self::CLOUDFLARED, 'HTTP_X_FORWARDED_PROTO' => 'https',
        ]);
        $direct = Request::create('/api/login', 'POST', server: [
            'REMOTE_ADDR' => '10.10.20.50', 'HTTP_X_FORWARDED_PROTO' => 'https',
        ]);

        $this->assertTrue($this->trusted($viaTunnel)->isSecure());
        $this->assertFalse($this->trusted($direct)->isSecure());
    }

    /** A request as cloudflared forwards it: from loopback, with Cloudflare's edge headers. */
    private function viaTunnel(string $password, array $headers, string $username = 'budi')
    {
        return $this->withServerVariables(['REMOTE_ADDR' => self::CLOUDFLARED])
            ->withHeaders(['CF-Ray' => '8f00000000000000-SIN', 'CDN-Loop' => 'cloudflare'] + $headers)
            ->postJson('/api/login', ['username' => $username, 'password' => $password]);
    }

    public function test_tunnel_visitor_with_a_valid_ip_logs_in_normally(): void
    {
        $this->viaTunnel('salah', ['X-Forwarded-For' => '203.0.113.9', 'CF-Connecting-IP' => '203.0.113.9'])->assertStatus(422);
        $this->viaTunnel('password', ['X-Forwarded-For' => '203.0.113.9', 'CF-Connecting-IP' => '203.0.113.9'])->assertOk();
        $this->viaTunnel('password', ['X-Forwarded-For' => '2001:db8::7', 'CF-Connecting-IP' => '2001:0db8:0:0:0:0:0:7'])->assertOk();
    }

    public function test_tunnel_request_without_a_visitor_ip_is_refused_and_not_counted(): void
    {
        foreach ([[], ['X-Forwarded-For' => 'bukan-ip, ;;;'], ['X-Forwarded-For' => '127.0.0.1'], ['CF-Connecting-IP' => '203.0.113.9']] as $headers) {
            // 3 x 4 kinds = 12 — over the 5-failure lockout, under the route's own 20/min limit
            for ($i = 0; $i < 3; $i++) {
                $this->viaTunnel('salah', $headers)->assertStatus(503)
                    ->assertJsonPath('errors.username.0', 'Login dari luar kantor sedang tidak dapat diproses (alamat IP tidak terbaca). Coba lagi nanti atau hubungi administrator.');
            }
        }
        $this->viaTunnel('password', [])->assertStatus(503); // even the right password

        // nothing was counted under 127.0.0.1: the account is not locked for anyone
        $this->viaTunnel('password', ['X-Forwarded-For' => '203.0.113.9', 'CF-Connecting-IP' => '203.0.113.9'])->assertOk();
        $this->attempt('password', self::CLOUDFLARED)->assertOk(); // a browser on the server itself
    }

    public function test_tunnel_request_whose_ip_headers_disagree_is_refused(): void
    {
        // e.g. cloudflared appending an edge IP after the visitor's — fail closed
        $this->viaTunnel('password', ['X-Forwarded-For' => '203.0.113.9, 198.51.100.1', 'CF-Connecting-IP' => '203.0.113.9'])->assertStatus(503);
        $this->viaTunnel('password', ['X-Forwarded-For' => '203.0.113.9', 'CF-Connecting-IP' => 'rusak'])->assertStatus(503);
    }

    public function test_tunnel_request_without_cf_connecting_ip_is_refused_and_not_counted(): void
    {
        foreach (['CF-Ray' => '8f00000000000000-SIN', 'CDN-Loop' => 'cloudflare'] as $name => $value) {
            for ($i = 0; $i < 6; $i++) {
                $this->withServerVariables(['REMOTE_ADDR' => self::CLOUDFLARED])
                    ->withHeaders([$name => $value, 'X-Forwarded-For' => '203.0.113.9'])
                    ->postJson('/api/login', ['username' => 'budi', 'password' => 'salah'])
                    ->assertStatus(503);
            }
        }

        // 12 refusals, none counted: the same visitor is not locked once both headers are there
        $this->viaTunnel('password', ['X-Forwarded-For' => '203.0.113.9', 'CF-Connecting-IP' => '203.0.113.9'])->assertOk();
        $this->attempt('password', self::CLOUDFLARED)->assertOk();   // browser on the server itself
        $this->attempt('password', '10.10.20.50')->assertOk();       // LAN PC, no proxy
    }

    public function test_tunnel_visitor_cannot_spoof_its_ip(): void
    {
        $tunnel = ['X-Forwarded-For' => '6.6.6.6, 203.0.113.9', 'CF-Connecting-IP' => '203.0.113.9'];
        for ($i = 0; $i < 5; $i++) {
            $this->viaTunnel('salah', $tunnel)->assertStatus(422);
        }
        // locked under the real IP, whatever is put in front
        $this->viaTunnel('password', ['X-Forwarded-For' => '1.1.1.1, 203.0.113.9', 'CF-Connecting-IP' => '203.0.113.9'])->assertStatus(429);
        // another real visitor is not affected
        $this->viaTunnel('password', ['X-Forwarded-For' => '198.51.100.20', 'CF-Connecting-IP' => '198.51.100.20'])->assertOk();
    }

    public function test_lan_client_sending_cloudflare_headers_still_uses_its_own_ip(): void
    {
        $lan = fn (string $pw) => $this->withServerVariables(['REMOTE_ADDR' => '10.10.20.50'])
            ->withHeaders(['CF-Ray' => 'x', 'CF-Connecting-IP' => '203.0.113.9'])
            ->postJson('/api/login', ['username' => 'budi', 'password' => $pw]);

        for ($i = 0; $i < 5; $i++) {
            $lan('salah')->assertStatus(422);
        }
        $lan('password')->assertStatus(429);                       // locked under 10.10.20.50
        $this->attempt('password', '10.10.20.51')->assertOk();     // a colleague's PC is fine
    }

    /** Runs the app's TrustProxies middleware on a bare request. */
    private function trusted(Request $request): Request
    {
        app(TrustProxies::class)->handle($request, fn ($r) => response(''));

        return $request;
    }
}
