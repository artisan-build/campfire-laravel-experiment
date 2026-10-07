<?php

namespace App\Support;

use App\Models\Attachment;
use App\Models\Blob;
use App\Models\Message;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Database\Events\TransactionCommitted;
use Illuminate\Database\Events\TransactionRolledBack;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;

/**
 * Every uploaded byte goes through the Storage facade.
 *
 * Upstream wrote Rails' Active Storage on-disk layout with file_put_contents under STORAGE_PATH,
 * which is instance-local and lost on redeploy. The object layout here is ours:
 *
 *   blobs/<key>                                  the original
 *   variants/<key>/<variation digest>.<format>   a derived image
 */
final class BlobStorage
{
    /** @var array<int, array{blob: Blob, level: int}> */
    private array $pendingFiles = [];

    public function __construct()
    {
        Event::listen(TransactionRolledBack::class, function ($event) {
            foreach ($this->pendingFiles as $id => $pending) {
                if ($pending['level'] > $event->connection->transactionLevel()) {
                    $this->deleteFiles($pending['blob']);
                    unset($this->pendingFiles[$id]);
                }
            }
        });
        Event::listen(TransactionCommitted::class, function ($event) {
            if ($event->connection->transactionLevel() === 0) {
                $this->pendingFiles = [];
            }
        });
    }

    public function diskName(): string
    {
        return config('filesystems.default');
    }

    public function disk(): Filesystem
    {
        return Storage::disk($this->diskName());
    }

    /**
     * Whether the disk can mint a URL that also carries the Content-Type and Content-Disposition the
     * app wants. S3 can, through its Response* query parameters; a local disk's signed URL cannot, so
     * development and tests stream the bytes instead.
     */
    public function signsResponses(): bool
    {
        return config('filesystems.disks.'.$this->diskName().'.driver') === 's3'
            && $this->disk()->providesTemporaryUrls();
    }

    public function path(Blob $blob): string
    {
        return 'blobs/'.$this->key($blob);
    }

    public function variantDirectory(Blob $blob): string
    {
        return 'variants/'.$this->key($blob);
    }

    private function key(Blob $blob): string
    {
        if (! preg_match('/^[a-zA-Z0-9_-]{8,}$/', (string) $blob->key)) {
            throw new \RuntimeException('Invalid storage key');
        }

        return $blob->key;
    }

    public function url(Blob $blob): string
    {
        return '/rails/active_storage/blobs/redirect/'.app(SignedIdentifiers::class)->signedId($blob->id, 'ActiveStorage::Blob', 'blob_id').'/'.rawurlencode($blob->filename);
    }

    public function attach(Message $message, UploadedFile|string $source): Blob
    {
        if (is_string($source)) {
            $id = app(SignedIdentifiers::class)->verifyId($source, 'ActiveStorage::Blob', 'blob_id');
            $blob = Blob::findOrFail($id);
        } else {
            $blob = $this->store($source);
        }
        try {
            if (app(Media::class)->previewable($blob)) {
                app(Media::class)->variant($blob, ['resize_to_limit' => [1200, 800], 'format' => $this->thumbnailFormat($blob)]);
            }
        } catch (\Throwable $error) {
            if ($source instanceof UploadedFile) {
                $this->deleteFiles($blob);
            }
            throw $error;
        }
        $message->attachment()->delete();
        Attachment::create(['name' => 'attachment', 'record_type' => 'Message', 'record_id' => $message->id, 'blob_id' => $blob->id, 'created_at' => now()]);

        return $blob;
    }

    public function deleteFiles(Blob $blob): void
    {
        $this->disk()->delete($this->path($blob));
        $this->disk()->deleteDirectory($this->variantDirectory($blob));
    }

    public function purgeUnreferenced(Blob $blob): void
    {
        if (! Attachment::where('blob_id', $blob->id)->exists()) {
            $blob->delete();
            $this->deleteFiles($blob);
        }
    }

    public function store(UploadedFile $source): Blob
    {
        $data = file_get_contents($source->getRealPath());
        $blob = Blob::create([
            'key' => bin2hex(random_bytes(14)),
            'filename' => basename($source->getClientOriginalName()),
            'content_type' => $source->getMimeType(),
            'metadata' => '{}',
            'service_name' => 'campfire',
            'byte_size' => strlen($data),
            'checksum' => base64_encode(md5($data, true)),
            'created_at' => now(),
        ]);
        if (DB::transactionLevel() > 0) {
            $this->pendingFiles[$blob->id] = ['blob' => $blob, 'level' => DB::transactionLevel()];
        }
        $this->write($blob, $data);
        $blob->update(['metadata' => json_encode(app(Media::class)->analyze($blob, $source->getRealPath()))]);

        return $blob;
    }

    public function write(Blob $blob, string $data): void
    {
        $this->disk()->write($this->path($blob), $data);
    }

    public function attachTo(string $type, int $id, string $name, UploadedFile $source): Blob
    {
        $blob = $this->store($source);
        Attachment::where(['record_type' => $type, 'record_id' => $id, 'name' => $name])->delete();
        Attachment::create(['record_type' => $type, 'record_id' => $id, 'name' => $name, 'blob_id' => $blob->id, 'created_at' => now()]);
        $table = match ($type) {
            'User' => 'users', 'Account' => 'accounts', default => null
        };
        if ($table) {
            DB::table($table)->where('id', $id)->update(['updated_at' => now()]);
        }

        return $blob;
    }

    public function thumbnailFormat(Blob $blob): string
    {
        return match ($blob->content_type) {
            'image/jpeg' => 'jpeg', 'image/png' => 'png', 'image/gif' => 'png', default => 'webp'
        };
    }

    public function representationUrl(Blob $blob, array $variation): string
    {
        return '/rails/active_storage/representations/redirect/'.app(SignedIdentifiers::class)->signedId($blob->id, 'ActiveStorage::Blob', 'blob_id').'/'.app(SignedIdentifiers::class)->appSign($variation, 'variation').'/'.rawurlencode($blob->filename);
    }

    public function attached(string $type, int $id, string $name): ?Blob
    {
        return Attachment::where(['record_type' => $type, 'record_id' => $id, 'name' => $name])->with('blob')->first()?->blob;
    }
}
