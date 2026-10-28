<?php

namespace Tests\Feature\ItemRequest;

use App\Models\ItemRequest;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** The requester attaches up to 3 images; everyone handling the request sees them until it is done. */
class ItemRequestImageTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    protected $seeder = RbacSeeder::class;

    private const PNG = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII=';

    public function test_images_go_with_the_request_from_the_form_to_everyone_on_it(): void
    {
        $body = ['lines' => [['deskripsi_barang' => 'kran air bocor', 'qty' => 1]]];
        $this->actingAsRole('karyawan', ['name' => 'SITI']);
        $this->postJson('/api/item-requests', $body + ['images' => array_fill(0, 4, self::PNG)])->assertStatus(422)->assertJsonValidationErrors('images');
        $this->postJson('/api/item-requests', $body + ['images' => ['data:text/plain;base64,aGk=']])->assertStatus(422);
        $this->assertSame(0, ItemRequest::count(), 'a bad image refuses the whole request');

        $req = $this->postJson('/api/item-requests', $body + ['images' => [self::PNG, self::PNG]])->assertCreated()->json('data');
        $this->assertSame(['img1', 'img2'], $req['images']);
        $this->get("/api/item-requests/{$req['id']}/image/img2")->assertOk();
        $this->get("/api/item-requests/{$req['id']}/image/img3")->assertNotFound();

        // admin gudang and the approvers see them; another karyawan does not
        $this->actingAsRole('admin_gudang');
        $this->getJson("/api/item-requests/{$req['id']}")->assertJsonPath('data.images', ['img1', 'img2']);
        $this->get("/api/item-requests/{$req['id']}/image/img1")->assertOk();
        $this->actingAsRole('karyawan');
        $this->get("/api/item-requests/{$req['id']}/image/img1")->assertForbidden();
    }

    public function test_a_share_link_request_has_its_images_on_the_status_page(): void
    {
        $this->actingAsRole('admin_gudang');
        $link = $this->postJson('/api/item-request-links', ['label' => 'Umum', 'form_type' => 'UMUM'])->assertCreated()->json('data');
        app('auth')->forgetGuards();

        $sent = $this->postJson("/api/public/item-request/{$link['token']}", ['requester_name' => 'ANDI', 'images' => [self::PNG],
            'lines' => [['deskripsi_barang' => 'lampu', 'qty' => 2]]])->assertCreated()->json('data');
        $this->getJson("/api/public/request-status?tokens={$sent['track_token']}")->assertJsonPath('data.0.images', ['img1']);
        $this->get("/api/public/request-status/image?token={$sent['track_token']}&slot=img1")->assertOk();
        $this->get('/api/public/request-status/image?token='.str_repeat('x', 32).'&slot=img1')->assertNotFound();
    }

    public function test_each_item_has_its_own_images_beside_its_name(): void
    {
        $this->actingAsRole('karyawan', ['name' => 'SITI']);
        $this->postJson('/api/item-requests', ['lines' => [['deskripsi_barang' => 'kran', 'qty' => 1, 'images' => array_fill(0, 4, self::PNG)]]])
            ->assertStatus(422)->assertJsonValidationErrors('lines.0.images');
        $req = $this->postJson('/api/item-requests', ['lines' => [
            ['deskripsi_barang' => 'kran air', 'qty' => 1, 'images' => [self::PNG, self::PNG]],
            ['deskripsi_barang' => 'selang', 'qty' => 2],
        ]])->assertCreated()->json('data');
        $this->assertSame([['img1', 'img2'], []], array_column($req['lines'], 'images'));
        [$kran, $selang] = array_column($req['lines'], 'id');
        $this->get("/api/item-requests/{$req['id']}/lines/{$kran}/image/img2")->assertOk();
        $this->get("/api/item-requests/{$req['id']}/lines/{$selang}/image/img1")->assertNotFound();

        // admin gudang and the approvers see them beside the item
        $this->actingAsRole('admin_gudang');
        $this->getJson("/api/item-requests/{$req['id']}")->assertJsonPath('data.lines.0.images', ['img1', 'img2']);
        $this->actingAsRole('manager');
        $this->get("/api/item-requests/{$req['id']}/lines/{$kran}/image/img1")->assertOk();
        // another request's line: not through this one
        $this->actingAsRole('karyawan');
        $this->get("/api/item-requests/{$req['id']}/lines/{$kran}/image/img1")->assertForbidden();

        // the requester's status page (no account)
        app('auth')->forgetGuards();
        $token = \App\Models\ItemRequest::find($req['id'])->track_token;
        $this->getJson("/api/public/request-status?tokens={$token}")->assertJsonPath('data.0.lines.0.images', ['img1', 'img2']);
        $this->get("/api/public/request-status/image?token={$token}&line={$kran}&slot=img1")->assertOk();
        $this->get("/api/public/request-status/image?token={$token}&line=999999&slot=img1")->assertNotFound();
    }
}
