<?php

namespace App\Support;

final class SignedIdentifiers
{
    private ?string $cachedSecret = null;

    private array $keys = [];

    public function key(string $salt, int $length = 64): string
    {
        $secret = config('campfire.secret');
        if ($this->cachedSecret !== $secret) {
            $this->cachedSecret = $secret;
            $this->keys = [];
        }

        return $this->keys[$salt][$length] ??= hash_pbkdf2('sha256', $secret, $salt, 1000, $length, true);
    }

    public function json(mixed $value): string
    {
        return str_replace(['<', '>', '&'], ['\\u003c', '\\u003e', '\\u0026'], json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_LINE_TERMINATORS | JSON_THROW_ON_ERROR));
    }

    private function unpack(string|false $data, ?string $purpose): mixed
    {
        if ($data === false) {
            return null;
        }
        try {
            $value = json_decode($data, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }
        if (isset($value['_rails'])) {
            $meta = $value['_rails'];
            if ((isset($meta['pur']) ? $meta['pur'] !== $purpose : $purpose !== null) || (isset($meta['exp']) && strtotime($meta['exp']) <= now()->timestamp)) {
                return null;
            }
            if (array_key_exists('data', $meta)) {
                return $meta['data'];
            }

            return json_decode(base64_decode($meta['message'] ?? '', true) ?: '', true);
        }

        return $value;
    }

    private function modelPurpose(string $model, ?string $purpose): string
    {
        $model = str_replace('::', '/', $model);
        $model = preg_replace('/([a-z0-9])([A-Z])/', '$1_$2', $model);

        return strtolower($model).($purpose ? '/'.$purpose : '');
    }

    public function appSign(mixed $value, ?string $purpose, ?string $expires = null, string $name = 'ActiveStorage'): string
    {
        $meta = ['data' => $value];
        if ($expires !== null) {
            $meta['exp'] = $expires;
        }if ($purpose !== null) {
            $meta['pur'] = $purpose;
        }
        $data = base64_encode($this->json(($purpose !== null || $expires !== null) ? ['_rails' => $meta] : $value));

        return $data.'--'.hash_hmac('sha1', $data, $this->key($name));
    }

    public function appVerify(string $raw, ?string $purpose, string $name = 'ActiveStorage'): mixed
    {
        $p = explode('--', $raw);
        if (count($p) !== 2 || ! hash_equals(hash_hmac('sha1', $p[0], $this->key($name)), $p[1])) {
            return null;
        }

        return $this->unpack(base64_decode(strtr($p[0], '-_', '+/'), true), $purpose);
    }

    public function signedId(int $id, string $model, ?string $purpose, ?string $expires = null): string
    {
        if ($model === 'ActiveStorage::Blob') {
            return $this->appSign($id, $purpose ?? 'blob_id', $expires);
        }
        $meta = ['data' => $id];
        if ($expires !== null) {
            $meta['exp'] = $expires;
        }$meta['pur'] = $this->modelPurpose($model, $purpose);
        $data = rtrim(strtr(base64_encode($this->json(['_rails' => $meta])), '+/', '-_'), '=');

        return $data.'--'.hash_hmac('sha256', $data, $this->key('active_record/signed_id'));
    }

    public function verifyId(string $raw, string $model, ?string $purpose): ?int
    {
        if ($model === 'ActiveStorage::Blob') {
            $id = $this->appVerify($raw, $purpose ?? 'blob_id');

            return is_int($id) ? $id : null;
        }
        $p = explode('--', $raw);
        if (count($p) !== 2) {
            return null;
        }$algorithm = strlen($p[1]) === 40 ? 'sha1' : 'sha256';
        if (! hash_equals(hash_hmac($algorithm, $p[0], $this->key('active_record/signed_id')), $p[1])) {
            return null;
        }
        $v = $this->unpack(base64_decode(strtr($p[0], '-_', '+/'), true), $this->modelPurpose($model, $purpose));

        return is_int($v) ? $v : (is_string($v) && ctype_digit(trim($v)) ? (int) $v : null);
    }

    public function sgid(int $id, string $model = 'User'): string
    {
        $data = strtr(base64_encode($this->json(['_rails' => ['data' => 'gid://campfire/'.$model.'/'.$id.'?expires_in', 'pur' => 'attachable']])), '+/', '-_');

        return $data.'--'.hash_hmac('sha1', $data, $this->key('signed_global_ids'));
    }

    public function verifySgid(string $raw): ?array
    {
        $p = explode('--', $raw);
        if (count($p) !== 2 || ! hash_equals(hash_hmac('sha1', $p[0], $this->key('signed_global_ids')), $p[1])) {
            return null;
        }
        $data = $this->unpack(base64_decode(strtr($p[0], '-_', '+/'), true), 'attachable');
        if (! is_string($data)) {
            return null;
        }

        return preg_match('~^gid://campfire/(User|ActiveStorage::Blob)/(\d+)(?:\?.*)?$~', $data, $m) ? ['model' => $m[1], 'id' => (int) $m[2]] : null;
    }
}
