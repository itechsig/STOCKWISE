<?php

namespace Tests\Feature;

use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** "Catatan" on any table row. */
class RecordNoteTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    protected $seeder = RbacSeeder::class;

    public function test_notes_are_written_read_and_cleared_per_entity_row(): void
    {
        $this->getJson('/api/record-notes?entity=ppb&keys=1')->assertUnauthorized();

        $this->actingAsRole('karyawan');
        $this->putJson('/api/record-notes', ['entity' => 'ppb', 'key' => '12', 'note' => 'tanya purchasing'])
            ->assertOk()->assertJsonPath('data.note', 'tanya purchasing');
        $this->putJson('/api/record-notes', ['entity' => 'npbg', 'key' => '12', 'note' => 'lain tabel']);
        $this->putJson('/api/record-notes', ['entity' => 'ppb', 'key' => '12', 'note' => 'sudah ditanya'])->assertOk();

        $this->getJson('/api/record-notes?entity=ppb&keys=12,13')->assertOk()
            ->assertJsonPath('data.12.note', 'sudah ditanya')
            ->assertJsonMissingPath('data.13');
        $this->assertDatabaseCount('record_notes', 2);

        $this->putJson('/api/record-notes', ['entity' => 'ppb', 'key' => '12', 'note' => '  '])->assertOk();
        $this->getJson('/api/record-notes?entity=ppb&keys=12')->assertJsonMissingPath('data.12');

        $this->putJson('/api/record-notes', ['entity' => 'PPB; drop', 'key' => '1', 'note' => 'x'])->assertStatus(422);
    }
}
