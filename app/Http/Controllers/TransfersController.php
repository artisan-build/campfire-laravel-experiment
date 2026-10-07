<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Support\SignedIdentifiers;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Illuminate\Http\Request;

final class TransfersController extends Controller
{
    public function show(string $id)
    {
        return view('sessions.transfer', ['transferId' => $id]);
    }

    public function update(Request $r, string $id)
    {
        $user = User::active()->find(app(SignedIdentifiers::class)->verifyId($id, 'User', 'transfer'));
        abort_unless($user, 400);

        return app(SessionController::class)->start($r, $user);
    }

    public function qr(string $id)
    {
        $url = base64_decode(strtr($id, '-_', '+/'), true);
        abort_if($url === false || strlen($url) > 4096, 400);
        $renderer = new ImageRenderer(new RendererStyle(512), new SvgImageBackEnd);
        $writer = new Writer($renderer);

        return response($writer->writeString($url))->header('Content-Type', 'image/svg+xml')->header('Cache-Control', 'public, max-age=31536000');
    }
}
