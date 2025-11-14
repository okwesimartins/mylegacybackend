<!DOCTYPE html><html><body style="font-family:Arial,sans-serif;">

  <!-- Static Logo Section -->
  <div style="text-align:center;margin:12px 0 16px 0;">
    <div style="display:inline-block;width:64px;height:64px;border-radius:12px;overflow:hidden;background:#f0f4f8;">
      <img src="https://mylegacyjournals.app/backend/public/products_image/logolegacy.png" alt="My Legacy Journals" width="64" height="64"
           style="display:block;width:64px;height:64px;object-fit:contain;">
    </div>
  </div>

  <h2 style="margin:0 0 8px;">A Special Memory Awaits You</h2>
  <p>From <strong>My Legacy Journals</strong></p>

  <div style="padding:12px;background:#e7f0ff;border-radius:8px;margin:16px 0;">
    <strong>{{ $payload['owner_name'] }}</strong>
    <div style="color:#555;">Your {{ $payload['relationship'] }}</div>
    @if(!empty($payload['message']))
      <p style="margin-top:8px;">"{{ $payload['message'] }}"</p>
    @endif
  </div>

  <h3>Your Inherited Journal(s)</h3>
  <ul>
    @foreach($payload['journal_meta'] as $j)
      <li>{{ $j['title'] }} — {{ $j['entries'] }} entries</li>
    @endforeach
  </ul>

  <div style="padding:12px;background:#f7f7f7;border-radius:8px;margin:16px 0;">
    <strong>Your Pass Key:</strong> <code>{{ $payload['passkey'] }}</code>
    <p style="margin:8px 0 0;">Use the same email address that received this message to sign up in the app.</p>
  </div>

  <p>
    <a href="{{ $payload['deep_link'] }}" style="display:inline-block;padding:10px 16px;background:#7ab83d;color:#fff;text-decoration:none;border-radius:6px;">
      Access Your Journal
    </a>
  </p>

  <p style="color:#666;font-size:12px;">
    Secure • Private • Yours Forever — You can request a printed copy at any time.
  </p>
</body></html>
