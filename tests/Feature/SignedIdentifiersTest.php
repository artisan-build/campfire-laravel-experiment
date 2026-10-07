<?php

namespace Tests\Feature;

use App\Support\SignedIdentifiers;
use Carbon\Carbon;
use Tests\TestCase;

final class SignedIdentifiersTest extends TestCase
{
    private array $v;

    protected function setUp(): void
    {
        parent::setUp();
        $this->v = json_decode(file_get_contents(base_path('compat/rails_compat.json')), true);
        config(['campfire.secret' => $this->v['secret_key_base']]);
        Carbon::setTestNow($this->v['now']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_user_avatar_signed_ids_match_rails(): void
    {
        $c = app(SignedIdentifiers::class);
        foreach ($this->v['signed_ids']['generate'] as $v) {
            if ($v['expires_at'] === null) {
                $this->assertSame($v['signed_id'], $c->signedId($v['id'], $v['model'], $v['purpose']));
                $this->assertSame($v['id'], $c->verifyId($v['signed_id'], $v['model'], $v['purpose']));
            }
        }
    }

    public function test_signed_global_ids_match_rails(): void
    {
        $c = app(SignedIdentifiers::class);
        foreach ($this->v['sgids']['generate'] as $v) {
            if ($v['expires_at'] === null && str_contains($v['gid'], '/User/')) {
                $id = (int) basename($v['gid']);
                $this->assertSame($v['sgid'], $c->sgid($id));
                $this->assertSame(['model' => 'User', 'id' => $id], $c->verifySgid($v['sgid']));
            }
        }
    }

    public function test_purpose_and_signature_boundaries(): void
    {
        $c = app(SignedIdentifiers::class);
        $this->assertNull($c->verifyId($c->signedId(1, 'User', 'avatar'), 'User', 'transfer'));
    }

    public function test_all_cookie_and_signed_id_positive_and_negative_oracles(): void
    {
        $c = app(SignedIdentifiers::class);
        foreach ($this->v['signed_ids']['verify'] as $row) {
            Carbon::setTestNow($row['now'] ?? $this->v['now']);
            $actual = $c->verifyId($row['signed_id'], $row['model'], $row['purpose']);
            $expected = $row['expected'];
            if (is_string($expected)) {
                $expected = (int) $expected;
            }$this->assertSame($expected, $actual, $row['case']);
        }
    }

    public function test_active_storage_specific_verifier_goldens(): void
    {
        $c = app(SignedIdentifiers::class);
        foreach ($this->v['app_verifiers']['generate'] as $row) {
            if ($row['name'] !== 'ActiveStorage') {
                continue;
            }$value = json_decode($row['data_json'], true);
            $this->assertSame($row['message'], $c->appSign($value, $row['purpose'], $row['expires_at']));
            $this->assertSame($value, $c->appVerify($row['message'], $row['purpose']));
        }
    }

    public function test_cached_keys_track_secret_rotation_salt_and_output_length(): void
    {
        $crypto = app(SignedIdentifiers::class);
        config(['campfire.secret' => 'first-local-fixture-secret']);
        $first = $crypto->key('active_record/signed_id');
        $this->assertSame(hash_pbkdf2('sha256', 'first-local-fixture-secret', 'active_record/signed_id', 1000, 64, true), $first);
        $oldId = $crypto->signedId(1, 'User', 'avatar');
        config(['campfire.secret' => 'rotated-local-fixture-secret']);
        $this->assertNotSame($first, $crypto->key('active_record/signed_id'));
        $this->assertNull($crypto->verifyId($oldId, 'User', 'avatar'));
        $newId = $crypto->signedId(1, 'User', 'avatar');
        $this->assertSame(1, $crypto->verifyId($newId, 'User', 'avatar'));
        config(['campfire.secret' => 'first-local-fixture-secret']);
        $this->assertSame($first, $crypto->key('active_record/signed_id'));
        $this->assertNull($crypto->verifyId($newId, 'User', 'avatar'));
        $this->assertSame(1, $crypto->verifyId($oldId, 'User', 'avatar'));
    }
}
