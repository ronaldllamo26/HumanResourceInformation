<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Stores an employee photo, stripped of everything that is not the picture.
 *
 * **Employee photos are on the public disk, and that is deliberate** — an
 * avatar rendered by `<img>` should not cost a PHP request per row. The price
 * of that decision is that the file is served to anyone with the URL, with no
 * policy check in front of it.
 *
 * Which makes the file's *metadata* a leak nobody would go looking for. A
 * photo taken on a phone carries EXIF, and EXIF carries GPS: HR photographs a
 * new hire at their house, or the employee sends a selfie, and the coordinates
 * of somebody's home are on a public URL. Laravel's `image` validation rule
 * does not catch this — it checks the format, not what is inside it.
 *
 * So the upload is **re-encoded rather than stored**. Decoding to a pixel
 * buffer and writing a fresh file keeps the picture and discards every chunk
 * that came with it — EXIF, GPS, camera serial, thumbnails, colour profiles,
 * and anything a crafted file smuggled in past the mime check.
 *
 * A photo that cannot be decoded is refused rather than stored raw. Storing it
 * unprocessed would be the one path that defeats the point of the class.
 */
class PhotoStore
{
    /**
     * Longest edge, in pixels.
     *
     * An avatar is rendered at 40px and an employee header at 96px, so a 4000px
     * phone photo is three megabytes spent on detail no screen shows. Capped
     * rather than fixed: a portrait and a landscape both keep their shape.
     */
    private const MAX_EDGE = 800;

    /** JPEG quality. 82 is where the file stops shrinking and starts smudging. */
    private const QUALITY = 82;

    public function __construct(private readonly string $disk = 'public') {}

    /**
     * Re-encodes the upload and stores it, returning the stored path.
     *
     * Null when the file could not be read as an image — the caller keeps
     * whatever photo was already on the record, which is the safe outcome:
     * a failed upload should not blank somebody's avatar.
     */
    public function store(UploadedFile $file, string $directory = 'employee-photos'): ?string
    {
        $image = $this->decode($file);

        if ($image === null) {
            Log::warning('Employee photo could not be decoded and was not stored', [
                'mime' => $file->getMimeType(),
                'size' => $file->getSize(),
            ]);

            return null;
        }

        $resized = $this->fit($image);

        if ($resized !== $image) {
            imagedestroy($image);
        }

        /*
         * Always JPEG, whatever came in.
         *
         * One output format means one decoder path for anything reading these
         * back, and it drops PNG's ancillary chunks — which are another place
         * metadata and payloads travel. Transparency is lost, which for a
         * photograph of a person is not a loss.
         */
        ob_start();
        imagejpeg($resized, null, self::QUALITY);
        $encoded = (string) ob_get_clean();

        imagedestroy($resized);

        $path = $directory.'/'.Str::uuid()->toString().'.jpg';

        Storage::disk($this->disk)->put($path, $encoded);

        return $path;
    }

    /**
     * Reads the upload into a pixel buffer.
     *
     * From the *real* bytes rather than the declared type: `getMimeType()` is
     * already checked by validation, but a file that merely claims to be an
     * image fails here rather than being written to a public directory.
     */
    private function decode(UploadedFile $file): ?\GdImage
    {
        $contents = @file_get_contents($file->getRealPath());

        if ($contents === false) {
            return null;
        }

        $image = @imagecreatefromstring($contents);

        return $image === false ? null : $image;
    }

    /**
     * Scales the longest edge down to MAX_EDGE, never up.
     *
     * Enlarging a small photo would spend bytes inventing detail that is not
     * there, so anything already inside the cap is returned untouched — it
     * still gets re-encoded by the caller, which is where the stripping
     * happens.
     */
    private function fit(\GdImage $image): \GdImage
    {
        $width = imagesx($image);
        $height = imagesy($image);
        $longest = max($width, $height);

        if ($longest <= self::MAX_EDGE) {
            return $image;
        }

        $scale = self::MAX_EDGE / $longest;

        return imagescale($image, (int) round($width * $scale), (int) round($height * $scale));
    }
}
