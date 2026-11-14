<?php

declare(strict_types=1);

namespace App\Tantra\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Tantra\Mail\MailImage;
use App\Tantra\Mail\WarmMailImage;
use Illuminate\Http\Response;

/** tantra: serves the email-sized copy of a post's media (App\Tantra\Mail\MailImage). */
class MailImageController extends Controller
{
    public function __invoke(string $kind, string $src): Response
    {
        $path = MailImage::decode($src);

        // The GIF is still being made: show its first frame now, not cached, so the next open animates.
        if ($kind === 'gif' && ! MailImage::cached($path, 'gif')) {
            WarmMailImage::dispatch($path);
            $still = MailImage::render($path, 'cover');
            abort_if($still === null, 404);

            return response($still[0], 200, ['Content-Type' => $still[1], 'Cache-Control' => 'no-store']);
        }

        $file = MailImage::render($path, $kind);
        abort_if($file === null, 404);

        return response($file[0], 200, [
            'Content-Type' => $file[1],
            'Cache-Control' => 'public, max-age=31536000, immutable',
        ]);
    }
}
