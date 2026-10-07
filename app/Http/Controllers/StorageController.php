<?php

namespace App\Http\Controllers;

use App\Models\Attachment;
use App\Models\Blob;
use App\Models\User;
use App\Support\Assets;
use App\Support\BlobStorage;
use App\Support\Media;
use App\Support\SignedIdentifiers;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class StorageController extends Controller
{
    /**
     * Attachment downloads redirect to a short-lived signed URL on the disk, which is what Active
     * Storage does too: the app stays the authorization gate and the bytes never touch it. When the
     * disk cannot sign (the local driver in development and tests) the file is streamed instead.
     */
    public function blob(Request $r, string $signed, string $filename)
    {
        $id = app(SignedIdentifiers::class)->verifyId($signed, 'ActiveStorage::Blob', 'blob_id');
        $blob = Blob::findOrFail($id);
        $storage = app(BlobStorage::class);
        abort_unless($storage->disk()->exists($storage->path($blob)), 404);

        // Installed ActiveStorage::Blob::Servable determines both MIME and disposition.
        $binary = in_array($blob->content_type, ['text/html', 'image/svg+xml', 'application/postscript', 'application/x-shockwave-flash', 'text/xml', 'application/xml', 'application/xhtml+xml', 'application/mathml+xml', 'text/cache-manifest']);
        $inline = in_array($blob->content_type, ['image/webp', 'image/avif', 'image/png', 'image/gif', 'image/jpeg', 'image/tiff', 'image/bmp', 'image/vnd.adobe.photoshop', 'image/vnd.microsoft.icon', 'application/pdf']);
        $type = $binary ? 'application/octet-stream' : ($blob->content_type ?? 'application/octet-stream');
        $disposition = (! $binary && $inline && $r->input('disposition') !== 'attachment' ? 'inline' : 'attachment').'; filename="'.str_replace(['"', "\r", "\n"], '_', $blob->filename).'"';

        return $this->serve($storage->path($blob), $type, $disposition);
    }

    public function avatar(Request $r, string $user)
    {
        $id = app(SignedIdentifiers::class)->verifyId($user, 'User', 'avatar');
        $u = User::findOrFail($id);
        $blob = app(BlobStorage::class)->attached('User', $u->id, 'avatar');
        if ($blob && in_array($blob->content_type, Media::IMAGE_TYPES, true)) {
            $path = app(Media::class)->variant($blob, ['resize_to_limit' => [512, 512], 'format' => 'webp']);

            return $this->cachedAvatar($this->serve($path, 'image/webp', 'inline'), $u, $r);
        }
        if ($u->role === 2) {
            return $this->cachedAvatar(response()->file(public_path(ltrim(app(Assets::class)->path('default-bot-avatar.svg'), '/')), ['Content-Type' => 'image/svg+xml', 'Content-Disposition' => 'inline']), $u, $r);
        }
        $initials = implode('', array_map(fn ($s) => mb_substr($s, 0, 1), preg_split('/\s+/u', trim($u->name))));
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" width="512" height="512"><rect width="100%" height="100%" rx="256" fill="#ddd"/><text x="50%" y="54%" text-anchor="middle" dominant-baseline="middle" font-size="180">'.htmlspecialchars($initials, ENT_QUOTES | ENT_XML1).'</text></svg>';

        return $this->cachedAvatar(response($svg)->header('Content-Type', 'image/svg+xml'), $u, $r);
    }

    private function cachedAvatar($response, User $user, Request $request)
    {
        $response->headers->set('Cache-Control', 'public, max-age=1800, stale-while-revalidate=604800');
        if (! $response->isRedirection()) {
            $response->setEtag(hash('sha256', $user->id.'-'.$user->getRawOriginal('updated_at')));
            $response->isNotModified($request);
        }

        return $response;
    }

    public function representation(Request $r, string $signed, string $variation, string $filename)
    {
        $b = Blob::findOrFail(app(SignedIdentifiers::class)->verifyId($signed, 'ActiveStorage::Blob', 'blob_id'));
        $v = app(SignedIdentifiers::class)->appVerify($variation, 'variation');
        abort_unless(is_array($v), 404);
        $path = app(Media::class)->variant($b, $v);

        return $this->serve($path, 'image/'.($v['format'] ?? 'webp'), 'inline', 'public, max-age=31536000');
    }

    /**
     * Redirect to a signed disk URL when the disk can mint one, stream otherwise.
     */
    private function serve(string $path, string $type, string $disposition, string $cacheControl = 'private, max-age=300')
    {
        $storage = app(BlobStorage::class);
        $disk = $storage->disk();

        if ($storage->signsResponses()) {
            return redirect($disk->temporaryUrl($path, now()->addMinutes(5), [
                'ResponseContentType' => $type,
                'ResponseContentDisposition' => $disposition,
                'ResponseCacheControl' => $cacheControl,
            ]))->header('Cache-Control', 'private, max-age=60');
        }

        return $disk->response($path, null, [
            'Content-Type' => $type,
            'Content-Disposition' => $disposition,
            'Cache-Control' => $cacheControl,
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function deleteAvatar(Request $r, string $user)
    {
        Attachment::where(['record_type' => 'User', 'record_id' => $r->user()->id, 'name' => 'avatar'])->delete();
        $r->user()->touch();

        return redirect('/users/me/profile');
    }

    public function deleteLogo(Request $r)
    {
        abort_unless($r->user()->role === 1, 403);
        Attachment::where('record_type', 'Account')->where('name', 'logo')->delete();

        return redirect('/account/edit');
    }

    public function directUpload(Request $r)
    {
        $a = $r->validate(['blob.filename' => 'required|string', 'blob.byte_size' => 'required|integer|min:0|max:104857600', 'blob.checksum' => 'required|string', 'blob.content_type' => 'nullable|string']);
        $b = Blob::create($a['blob'] + ['key' => bin2hex(random_bytes(14)), 'metadata' => '{}', 'service_name' => 'campfire', 'created_at' => now()]);
        $signed = app(SignedIdentifiers::class)->signedId($b->id, 'ActiveStorage::Blob', 'blob_id');
        $upload = app(SignedIdentifiers::class)->appSign(['key' => $b->key, 'content_type' => $b->content_type, 'content_length' => $b->byte_size, 'checksum' => $b->checksum, 'service_name' => 'campfire'], 'blob_token', now()->addMinutes(5)->format('Y-m-d\\TH:i:s.v\\Z'));

        return response()->json($b->toArray() + ['signed_id' => $signed, 'direct_upload' => ['url' => url('/rails/active_storage/disk/'.$upload), 'headers' => ['Content-Type' => $b->content_type, 'Content-MD5' => $b->checksum]]]);
    }

    public function disk(Request $r, string $signed)
    {
        $token = app(SignedIdentifiers::class)->appVerify($signed, 'blob_token');
        abort_unless(is_array($token) && ($token['service_name'] ?? '') === 'campfire', 404);
        $b = Blob::where('key', $token['key'] ?? null)->firstOrFail();
        $data = $r->getContent();
        abort_unless(strlen($data) === $token['content_length'] && hash_equals($token['checksum'], base64_encode(md5($data, true))), 422);
        app(BlobStorage::class)->write($b, $data);
        $b->update(['metadata' => json_encode(app(Media::class)->analyze($b))]);

        return response('', 204);
    }

    public function diskDownload(Request $r, string $signed, string $filename)
    {
        $token = app(SignedIdentifiers::class)->appVerify($signed, 'blob_key');
        abort_unless(is_array($token) && ($token['service_name'] ?? '') === 'campfire', 404);
        $b = Blob::where('key', $token['key'] ?? null)->firstOrFail();

        return $this->blob($r, app(SignedIdentifiers::class)->signedId($b->id, 'ActiveStorage::Blob', 'blob_id'), $filename);
    }

    public function logo()
    {
        $b = app(BlobStorage::class)->attached('Account', (int) DB::table('accounts')->value('id'), 'logo');
        if ($b) {
            return redirect(app(BlobStorage::class)->url($b));
        }

        return response()->file(public_path(ltrim(app(Assets::class)->path('campfire-icon.png'), '/')));
    }
}
