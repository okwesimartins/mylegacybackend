<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

class LinkController extends Controller
{
    public function nokLanding(Request $r)
    {
        $invite = (string) $r->query('invite', '');
        $ua = strtolower($r->userAgent() ?? '');
        $appleid = "177782827";
        $isAndroid = str_contains($ua, 'android');
        $isiOS     = str_contains($ua, 'iphone') || str_contains($ua, 'ipad') || str_contains($ua, 'ipod');

        // Store URLs (adjust ids/packages in .env)
        $play = 'https://play.google.com/store/apps/details?id=app.mylegacyjournals.mylegacyjournals'
              .'&referrer='.rawurlencode('invite='.$invite);
        $appStore = 'https://apps.apple.com/app/id'.$appleid;

        return response()->view('links.nok', [
            'invite'    => $invite,
            'isAndroid' => $isAndroid,
            'isiOS'     => $isiOS,
            'play'      => $play,
            'appStore'  => $appStore,
            // Your custom scheme for opening the app directly
            'schemeUrl' => 'mylegacyjournals://nok/access?invite='.rawurlencode($invite),
            // Android "intent://" fallback that points to your package + Play fallback
            'intentUrl' => 'mylegacyjournals://nok/access?invite='.rawurlencode($invite)
                        .'#Intent;scheme=legacy;package=app.mylegacyjournals.mylegacyjournals'
                        .';S.browser_fallback_url='.rawurlencode($play).';end',
        ]);
    }
}
