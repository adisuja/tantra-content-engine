<?php

declare(strict_types=1);

namespace App\Tantra\Mail;

use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Imagick;
use Throwable;

/**
 * tantra: email-sized copies of post media. An email links a signed URL
 * (/tantra/mail-image/...) that serves a resized JPEG, a square thumbnail or a
 * smaller animated GIF of a file on the media disk, made once and cached next
 * to it. Signed so nobody can use it to resize arbitrary files; no expiry so
 * old emails keep their pictures.
 */
final class MailImage
{
    /** cover: 1040px wide JPEG (520px at 2x); thumb: 128px square JPEG; gif: 520px wide animated GIF. */
    public const array KINDS = ['cover', 'thumb', 'gif'];

    public static function url(?string $path, string $kind = 'cover'): ?string
    {
        if (blank($path) || ! in_array($kind, self::KINDS, true)) {
            return null;
        }

        if ($kind === 'gif' && ! self::cached((string) $path, $kind)) {
            // A GIF takes up to a minute on a small server: make it now, before the email is opened.
            WarmMailImage::dispatch((string) $path);
        }

        return URL::signedRoute('tantra.mail.image', ['kind' => $kind, 'src' => rtrim(strtr(base64_encode((string) $path), '+/', '-_'), '=')]);
    }

    public static function cached(string $path, string $kind): bool
    {
        return Storage::disk(config('filesystems.default'))->exists(self::cachePath($path, $kind));
    }

    private static function cachePath(string $path, string $kind): string
    {
        return 'mail-cache/'.sha1($path.'|'.$kind).($kind === 'gif' ? '.gif' : '.jpg');
    }

    public static function decode(string $src): string
    {
        return (string) base64_decode(strtr($src, '-_', '+/'), true);
    }

    /** @return array{0: string, 1: string}|null [bytes, mime] */
    public static function render(string $path, string $kind): ?array
    {
        $disk = Storage::disk(config('filesystems.default'));
        $cache = self::cachePath($path, $kind);
        $mime = $kind === 'gif' ? 'image/gif' : 'image/jpeg';

        if ($disk->exists($cache)) {
            return [(string) $disk->get($cache), $mime];
        }
        if (str_contains($path, '..') || ! $disk->exists($path)) {
            return null;
        }

        $source = (string) $disk->get($path);
        try {
            $bytes = self::transform($source, $kind);
        } catch (Throwable) {
            return [$source, (string) ($disk->mimeType($path) ?: 'application/octet-stream')];
        }
        $disk->put($cache, $bytes);

        return [$bytes, $mime];
    }

    private static function transform(string $source, string $kind): string
    {
        // Past these limits ImageMagick pages pixels to disk instead of growing in RAM (2 GB server).
        Imagick::setResourceLimit(Imagick::RESOURCETYPE_MEMORY, 96 * 1024 * 1024);
        Imagick::setResourceLimit(Imagick::RESOURCETYPE_MAP, 96 * 1024 * 1024);
        Imagick::setResourceLimit(Imagick::RESOURCETYPE_AREA, 32 * 1024 * 1024);
        $im = new Imagick;
        $im->readImageBlob($source);

        if ($kind === 'gif') {
            // Bounded work on a small server: at most 36 frames, 480px wide, so a
            // 6 second GIF comes out around 1MB and never holds every frame at full size.
            $count = $im->getNumberImages();
            $step = max(1, (int) ceil($count / 36));
            $im = $im->coalesceImages();
            $out = new Imagick;
            $index = 0;
            foreach ($im as $frame) {
                if ($index++ % $step !== 0) {
                    continue;
                }
                $small = $frame->getImage(); // this frame only (clone would copy every frame)
                $small->thumbnailImage(480, 0);
                $small->setImagePage($small->getImageWidth(), $small->getImageHeight(), 0, 0);
                $small->setImageDelay($frame->getImageDelay() * $step);
                $out->addImage($small);
                $small->clear();
            }
            $im->clear();
            $out->setImageIterations(0);
            $out = $out->optimizeImageLayers();

            return $out->getImagesBlob();
        }

        $im->setIteratorIndex(0);
        $im = $im->getImage(); // a GIF's or PDF's first frame
        $im->setImageBackgroundColor('white');
        $im = $im->mergeImageLayers(Imagick::LAYERMETHOD_FLATTEN);
        if ($kind === 'thumb') {
            $im->cropThumbnailImage(128, 128);
        } else {
            $im->thumbnailImage(min(1040, $im->getImageWidth()), 0);
            // A preview, not the post: tall images are cut to a square from the top, where the hook is.
            if ($im->getImageHeight() > $im->getImageWidth()) {
                $im->cropImage($im->getImageWidth(), $im->getImageWidth(), 0, 0);
                $im->setImagePage(0, 0, 0, 0);
            }
        }
        $im->setImageFormat('jpeg');
        $im->setImageCompressionQuality(78);
        $im->stripImage();

        return $im->getImageBlob();
    }
}
