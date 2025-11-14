<!doctype html>
<html>
<head>
  <meta charset="utf-8">
  <title>Opening myLegacy…</title>
  <meta name="viewport" content="width=device-width,initial-scale=1">
  @if($isiOS)
    <script>
      // iOS: try to open app via custom scheme; if not installed, go to App Store
      window.onload = function(){
        var t = Date.now();
        window.location = "{{ $schemeUrl }}";
        setTimeout(function(){
          if (Date.now() - t < 1500) { window.location = "{{ $appStore }}"; }
        }, 1200);
      };
    </script>
  @elseif($isAndroid)
    <script>
      // Android: intent:// opens the app; includes Play fallback
      window.onload = function(){ window.location = "{{ $intentUrl }}"; };
    </script>
  @endif
  <style>
    body{font-family:system-ui,-apple-system,Segoe UI,Roboto,Helvetica,Arial;margin:2rem}
    a{display:inline-block;padding:12px 16px;border-radius:8px;text-decoration:none;background:#4f46e5;color:#fff;margin-right:12px}
  </style>
</head>
<body>
  <h1>Opening myLegacy…</h1>
  <p>If nothing happens, use one of the buttons below.</p>
  <p>
    <a href="{{ $schemeUrl }}">Open the App</a>
    @if($isAndroid)
      <a href="{{ $play }}">Get it on Google Play</a>
    @elseif($isiOS)
      <a href="{{ $appStore }}">Get it on the App&nbsp;Store</a>
    @else
      <a href="{{ $play }}">Google Play</a>
      <a href="{{ $appStore }}">App Store</a>
    @endif
  </p>
</body>
</html>
