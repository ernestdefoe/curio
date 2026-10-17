<?php

namespace Ernestdefoe\Curio\Storage;

use Illuminate\Contracts\Filesystem\Cloud;
use Illuminate\Contracts\Filesystem\Factory;
use Illuminate\Support\Str;
use Intervention\Image\ImageManager;
use RuntimeException;
use Throwable;

/**
 * Photos and covers on Curio's own disk (public/assets/curio unless a storage
 * driver says otherwise). Every image is re-encoded as WebP twice, full size and
 * small, which also drops whatever metadata the original carried (a phone
 * photo's location included). A stored value is the full image's path; the
 * small one is derived from it.
 */
class Images
{
    public const DISK = 'ernestdefoe-curio';

    private const FULL = 1600;

    private const SMALL = 480;

    private const MAX_HEIGHT = 4096;

    protected Cloud $disk;

    public function __construct(Factory $filesystems, protected ImageManager $images)
    {
        $this->disk = $filesystems->disk(self::DISK);
    }

    /** Store an image from a local path or its bytes; returns the stored path. */
    public function store(string $source, string $folder): string
    {
        $image = $this->images->read($source);
        $name = $folder.'/'.Str::random(24).'.webp';
        $written = [];

        try {
            foreach ([$name => self::FULL, self::small($name) => self::SMALL] as $target => $width) {
                $copy = clone $image;
                $copy->scaleDown(width: $width, height: self::MAX_HEIGHT);
                $encoded = (string) $copy->toWebp(quality: 82);

                if ($encoded === '') {
                    throw new RuntimeException('The image could not be encoded.');
                }

                $this->disk->put($target, $encoded);
                $written[] = $target;
            }
        } catch (Throwable $e) {
            $this->disk->delete($written);

            throw $e;
        }

        return $name;
    }

    public function delete(?string $stored): void
    {
        if ($stored) {
            $this->disk->delete([$stored, self::small($stored)]);
        }
    }

    public function url(?string $stored, bool $small = false): ?string
    {
        return $stored ? $this->disk->url($small ? self::small($stored) : $stored) : null;
    }

    public static function small(string $name): string
    {
        return substr($name, 0, -5).'-sm.webp';
    }
}
